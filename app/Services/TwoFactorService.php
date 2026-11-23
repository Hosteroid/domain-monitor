<?php

namespace App\Services;

use App\Models\User;
use App\Models\Setting;
use App\Helpers\EmailHelper;
use App\Services\Logger;
use PragmaRX\Google2FA\Google2FA;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TwoFactorService
{
    /** Steps of 30 seconds before and after the current one whose authenticator codes count too */
    private const TOTP_WINDOW = 1;

    /** Times a backup code is looked for when the list of codes changes under the request */
    private const BACKUP_CODE_TRIES = 3;

    private User $userModel;
    private Setting $settingModel;
    private Logger $logger;
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->userModel = new User();
        $this->settingModel = new Setting();
        $this->logger = new Logger('2fa');
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate a new TOTP secret for user
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Generate QR code data URI for authenticator app
     */
    public function generateQrCodeDataUri(string $email, string $secret, string $appName = 'Domain Monitor'): string
    {
        $previousLevel = error_reporting(error_reporting() & ~E_DEPRECATED);

        try {
            $qrCode = new QrCode($this->google2fa->getQRCodeUrl($appName, $email, $secret));
            $qrCode->setSize(200);
            $qrCode->setMargin(10);

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            return 'data:image/png;base64,' . base64_encode($result->getString());
        } finally {
            error_reporting($previousLevel);
        }
    }

    /**
     * Time step of a code from an authenticator app: the number of the 30 seconds it is for, as
     * the app counted them. The steps right before and after the current one count too, for
     * clocks that differ a little.
     *
     * @param int $after Codes of this step and of earlier ones are not accepted
     * @return int|null Null when the code is wrong
     */
    public function totpStep(#[\SensitiveParameter] string $secret, string $code, int $after = 0): ?int
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return null;
        }

        // Always a number for the earlier step: given null, the library answers true, not the step
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $after, self::TOTP_WINDOW);

        return is_int($step) ? $step : null;
    }

    /**
     * Verify TOTP code of an account. A code works once.
     *
     * The time step of the accepted code is stored with the account (User::useTotpStep()), and a
     * code of that step or of an earlier one is refused from then on. Without that, a code could
     * be used again for as long as it stays valid, about 90 seconds, by anyone who saw it. A used
     * code is a wrong code: the callers and the 2FA code limit make no difference between them.
     *
     * Until the update that adds the column has run, codes are checked as before it and can be
     * used more than once.
     */
    public function verifyTotpCode(int $userId, string $code): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $user = $this->userModel->find($userId);
        $secret = $user['two_factor_secret'] ?? null;
        if (!$user || !$user['two_factor_enabled'] || !is_string($secret) || $secret === '') {
            return false;
        }

        if (!$this->userModel->remembersTotpStep()) {
            return $this->totpStep($secret, $code) !== null;
        }

        $step = $this->totpStep($secret, $code, (int)($user['two_factor_last_step'] ?? 0));
        if ($step === null) {
            return false;
        }

        // Right only when this request is the one that stored the step. Anything else is a
        // refusal, a database error too: a server may answer the loser of two requests at the
        // same moment with one instead of "no row changed".
        try {
            return $this->userModel->useTotpStep($userId, $step);
        } catch (\PDOException $e) {
            $this->logger->error('Authenticator code refused after a database error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }


    /**
     * Generate backup codes for user
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(md5(random_bytes(16)), 0, 8));
        }
        return $codes;
    }

    /**
     * Verify backup code
     *
     * A backup code works once, also when two requests bring it at the same moment: the list
     * without the code is stored only if nothing has changed the list since it was read
     * (User::takeBackupCode()). A request that finds the list changed looks again, because its
     * own code may still be there: another code was used, or new backup codes were made.
     */
    public function verifyBackupCode(int $userId, string $code): bool
    {
        for ($try = 1; $try <= self::BACKUP_CODE_TRIES; $try++) {
            $used = $this->useBackupCode($userId, $code);
            if ($used !== null) {
                return $used;
            }
        }

        return false;
    }

    /**
     * Look for a backup code in the account's list and take it out
     *
     * @return bool|null True when the code was used up, false when it is not one of the
     *                   account's codes, null when the list changed while this was done
     */
    private function useBackupCode(int $userId, string $code): ?bool
    {
        $user = $this->userModel->find($userId);
        if (!$user || !$user['two_factor_enabled'] || !$user['two_factor_backup_codes']) {
            return false;
        }

        $stored = $user['two_factor_backup_codes'];
        $backupCodes = json_decode($stored, true);
        if (!is_array($backupCodes)) {
            return false;
        }

        // Exact comparison: to a loose one "00000000" and the code "0E123456" are both zero
        $codeIndex = array_search(strtoupper($code), $backupCodes, true);
        if ($codeIndex === false) {
            return false;
        }

        // Remove used backup code
        unset($backupCodes[$codeIndex]);
        $backupCodes = array_values($backupCodes); // Re-index array

        // Update user with remaining backup codes, unless the list is no longer the one read
        // above. A database error is a refusal: the code counts only once the list is stored.
        try {
            if (!$this->userModel->takeBackupCode($userId, $stored, json_encode($backupCodes))) {
                return null;
            }
        } catch (\PDOException $e) {
            $this->logger->error('Backup code refused after a database error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return false;
        }

        $this->logger->info('Backup code used successfully', [
            'user_id' => $userId,
            'remaining_codes' => count($backupCodes)
        ]);

        return true;
    }

    /**
     * Generate and send email verification code
     *
     * An account is sent no more codes per time window than it may enter wrong codes
     * (Setting::getTwoFactorLimitSettings()), whatever address asks. A code whose mail could
     * not be sent counts too.
     */
    public function generateEmailCode(int $userId): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || !$user['email_verified']) {
            return ['success' => false, 'error' => 'User email not verified'];
        }

        $limits = $this->settingModel->getTwoFactorLimitSettings();
        $window = $limits['minutes'] * 60;
        $expiryMinutes = $this->settingModel->getTwoFactorEmailCodeMinutes();

        // Clean up codes that are neither valid nor counted any more
        $this->cleanOldEmailCodes(max($window, $expiryMinutes * 60));

        // Read only: a request refused here leaves nothing behind
        $wait = $this->emailCodeWait($userId, $window, $limits['attempts']);
        if ($wait > 0) {
            return ['success' => false, 'error' => self::emailCodeLimitMessage($wait)];
        }

        // Generate 6-digit code
        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Store code in database. expires_at is for the record only and comes from the
        // database's clock like created_at, which decides (see verifyEmailCode()).
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO two_factor_email_codes (user_id, code, expires_at)
             VALUES (?, ?, FROM_UNIXTIME(UNIX_TIMESTAMP() + ?))"
        );
        $stmt->execute([$userId, $code, $expiryMinutes * 60]);
        $codeId = (int)$pdo->lastInsertId();
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

        // Counted again with this code in: requests sent at the same moment each see the rows
        // of the others, so no more than the limit are sent
        if ($this->emailCodesSent($userId, $window) > $limits['attempts']) {
            $stmt = $pdo->prepare("DELETE FROM two_factor_email_codes WHERE id = ?");
            $stmt->execute([$codeId]);

            return [
                'success' => false,
                'error' => self::emailCodeLimitMessage(max(1, $this->emailCodeWait($userId, $window, $limits['attempts'])))
            ];
        }

        // Send email
        $result = EmailHelper::sendTwoFactorCode($user['email'], $user['full_name'], $code, $expiryMinutes);

        if ($result['success']) {
            $this->logger->info('2FA email code sent', [
                'user_id' => $userId,
                'email' => $user['email']
            ]);
            return ['success' => true, 'expires_at' => $expiresAt];
        } else {
            $this->logger->error('Failed to send 2FA email code', [
                'user_id' => $userId,
                'email' => $user['email'],
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return ['success' => false, 'error' => 'Failed to send email code'];
        }
    }

    /**
     * Verify email code
     *
     * Only the newest code of an account is looked at, so asking for a new one makes the older
     * ones invalid. Its age is measured on the database's clock, in the way TwoFactorAttempt
     * describes: expires_at was once written with PHP's clock and is not read any more.
     */
    public function verifyEmailCode(int $userId, string $code): bool
    {
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT id, code, used, UNIX_TIMESTAMP(created_at) AS noted_at, UNIX_TIMESTAMP() AS db_time
             FROM two_factor_email_codes
             WHERE user_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $emailCode = $stmt->fetch();

        if (!$emailCode || $emailCode['used']) {
            return false;
        }

        $lifetime = $this->settingModel->getTwoFactorEmailCodeMinutes() * 60;
        if ((int)$emailCode['db_time'] - (int)$emailCode['noted_at'] >= $lifetime) {
            return false;
        }

        if (!hash_equals((string)$emailCode['code'], $code)) {
            return false;
        }

        // Mark code as used: of two requests with the same code only one gets to do that.
        // expires_at is named because MariaDB would otherwise set it to now on this update.
        $stmt = $pdo->prepare(
            "UPDATE two_factor_email_codes SET used = 1, expires_at = expires_at WHERE id = ? AND used = 0"
        );
        $stmt->execute([$emailCode['id']]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $this->logger->info('2FA email code verified successfully', [
            'user_id' => $userId
        ]);

        return true;
    }

    /**
     * Check if user can enable 2FA (email must be verified)
     */
    public function canEnableTwoFactor(int $userId): bool
    {
        $user = $this->userModel->find($userId);
        return $user && $user['email_verified'] && !$user['two_factor_enabled'];
    }

    /**
     * Enable 2FA for user
     *
     * @param int $step Time step of the code that proved the setup (totpStep()). It is stored as
     *                  used, so that code does not work a second time at the first login.
     */
    public function enableTwoFactor(int $userId, string $secret, array $backupCodes, int $step): bool
    {
        $user = $this->userModel->find($userId);
        if (!$this->canEnableTwoFactor($userId)) {
            return false;
        }

        $data = [
            'two_factor_enabled' => 1,
            'two_factor_secret' => $secret,
            'two_factor_backup_codes' => json_encode($backupCodes),
            'two_factor_setup_at' => date('Y-m-d H:i:s')
        ];
        // Left out until the update that adds the column has run
        if ($this->userModel->remembersTotpStep()) {
            $data['two_factor_last_step'] = $step;
        }

        $result = $this->userModel->update($userId, $data);

        if ($result) {
            $this->logger->info('2FA enabled successfully', [
                'user_id' => $userId,
                'backup_codes_count' => count($backupCodes)
            ]);
        }

        return $result;
    }

    /**
     * Disable 2FA for user
     */
    public function disableTwoFactor(int $userId): bool
    {
        $data = [
            'two_factor_enabled' => 0,
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_setup_at' => null
        ];
        // The last used step belongs to the secret and goes with it
        if ($this->userModel->remembersTotpStep()) {
            $data['two_factor_last_step'] = null;
        }

        $result = $this->userModel->update($userId, $data);

        if ($result) {
            // Clean up email codes: none is of use once 2FA is off
            $stmt = \Core\Database::getConnection()->prepare(
                "DELETE FROM two_factor_email_codes WHERE user_id = ?"
            );
            $stmt->execute([$userId]);

            $this->logger->info('2FA disabled successfully', [
                'user_id' => $userId
            ]);
        }

        return $result;
    }

    /**
     * Get 2FA policy setting
     */
    public function getTwoFactorPolicy(): string
    {
        return $this->settingModel->getValue('two_factor_policy', 'optional');
    }

    /**
     * Check if 2FA is required for user based on policy
     */
    public function isTwoFactorRequired(int $userId): bool
    {
        $policy = $this->getTwoFactorPolicy();
        
        if ($policy === 'disabled') {
            return false;
        }
        
        if ($policy === 'forced') {
            $user = $this->userModel->find($userId);
            return $user && $user['email_verified'] && !$user['two_factor_enabled'];
        }
        
        return false; // Optional policy
    }

    /**
     * Whether text can be a code at all: six digits (authenticator app or email) or the eight
     * characters of a backup code. Nothing is checked against anything else, so the 2FA code
     * limit (TwoFactorLimiter) does not count it either.
     */
    public function canBeCode(string $code): bool
    {
        return preg_match('/\A(?:[0-9]{6}|[0-9A-Za-z]{8})\z/', $code) === 1;
    }

    /**
     * Mark of the password a login was started with, kept in the session while the login waits
     * for its 2FA code. A changed password changes the mark, which ends those logins: the code
     * page is only for someone who has entered the password the account has now.
     *
     * @param array $user Row of the users table
     */
    public static function passwordMark(array $user): string
    {
        return hash('sha256', is_string($user['password'] ?? null) ? $user['password'] : '');
    }

    /**
     * What a refused request for an email code is told
     */
    private static function emailCodeLimitMessage(int $seconds): string
    {
        $minutes = max(1, (int)ceil($seconds / 60));

        return 'Too many email codes requested. Please try again in ' . ($minutes === 1 ? '1 minute' : "$minutes minutes") . '.';
    }

    /**
     * Email codes sent to an account in the last seconds, measured on the database's clock
     */
    private function emailCodesSent(int $userId, int $seconds): int
    {
        $stmt = \Core\Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM two_factor_email_codes
             WHERE user_id = ? AND UNIX_TIMESTAMP(created_at) > UNIX_TIMESTAMP() - ?"
        );
        $stmt->execute([$userId, $seconds]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Seconds until an account can be sent another email code: until the nth newest code of
     * the last seconds is older than that
     *
     * @return int 0 when fewer than n were sent
     */
    private function emailCodeWait(int $userId, int $seconds, int $n): int
    {
        $stmt = \Core\Database::getConnection()->prepare(
            "SELECT UNIX_TIMESTAMP(created_at) AS noted_at, UNIX_TIMESTAMP() AS db_time
             FROM two_factor_email_codes
             WHERE user_id = ? AND UNIX_TIMESTAMP(created_at) > UNIX_TIMESTAMP() - ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1 OFFSET " . max(0, $n - 1)
        );
        $stmt->execute([$userId, $seconds]);
        $row = $stmt->fetch();

        return $row ? max(1, (int)$row['noted_at'] + $seconds - (int)$row['db_time']) : 0;
    }

    /**
     * Delete the email codes of all accounts that are older than both their lifetime and the
     * time window they are counted in. By created_at: expires_at is not a time to go by (see
     * verifyEmailCode()).
     */
    private function cleanOldEmailCodes(int $seconds): void
    {
        try {
            $stmt = \Core\Database::getConnection()->prepare(
                "DELETE FROM two_factor_email_codes WHERE UNIX_TIMESTAMP(created_at) <= UNIX_TIMESTAMP() - ?"
            );
            $stmt->execute([$seconds]);
        } catch (\Throwable $e) {
            // Another request is cleaning up at the same moment: the next one tries again
        }
    }

}
