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
        $qrCode = new QrCode($this->google2fa->getQRCodeUrl($appName, $email, $secret));
        $qrCode->setSize(200);
        $qrCode->setMargin(10);
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        
        return 'data:image/png;base64,' . base64_encode($result->getString());
    }

    /**
     * Verify TOTP code
     */
    public function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, $window);
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
     */
    public function verifyBackupCode(int $userId, string $code): bool
    {
        $user = $this->userModel->find($userId);
        if (!$user || !$user['two_factor_enabled'] || !$user['two_factor_backup_codes']) {
            return false;
        }

        $backupCodes = json_decode($user['two_factor_backup_codes'], true);
        if (!is_array($backupCodes)) {
            return false;
        }

        $codeIndex = array_search(strtoupper($code), $backupCodes);
        if ($codeIndex === false) {
            return false;
        }

        // Remove used backup code
        unset($backupCodes[$codeIndex]);
        $backupCodes = array_values($backupCodes); // Re-index array

        // Update user with remaining backup codes
        $this->userModel->update($userId, [
            'two_factor_backup_codes' => json_encode($backupCodes)
        ]);

        $this->logger->info('Backup code used successfully', [
            'user_id' => $userId,
            'remaining_codes' => count($backupCodes)
        ]);

        return true;
    }

    /**
     * Generate and send email verification code
     */
    public function generateEmailCode(int $userId): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || !$user['email_verified']) {
            return ['success' => false, 'error' => 'User email not verified'];
        }

        // Clean up expired codes
        $this->cleanExpiredEmailCodes($userId);

        // Generate 6-digit code
        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        
        // Calculate expiry time
        $expiryMinutes = (int)$this->settingModel->getValue('two_factor_email_code_expiry_minutes', 10);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

        // Store code in database
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO two_factor_email_codes (user_id, code, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $code, $expiresAt]);

        // Send email
        $result = EmailHelper::sendTwoFactorCode($user['email'], $user['full_name'], $code);

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
     */
    public function verifyEmailCode(int $userId, string $code): bool
    {
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT * FROM two_factor_email_codes 
             WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() 
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId, $code]);
        $emailCode = $stmt->fetch();

        if (!$emailCode) {
            return false;
        }

        // Mark code as used
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE two_factor_email_codes SET used = 1 WHERE id = ?"
        );
        $stmt->execute([$emailCode['id']]);

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
     */
    public function enableTwoFactor(int $userId, string $secret, array $backupCodes): bool
    {
        $user = $this->userModel->find($userId);
        if (!$this->canEnableTwoFactor($userId)) {
            return false;
        }

        $result = $this->userModel->update($userId, [
            'two_factor_enabled' => 1,
            'two_factor_secret' => $secret,
            'two_factor_backup_codes' => json_encode($backupCodes),
            'two_factor_setup_at' => date('Y-m-d H:i:s')
        ]);

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
        $result = $this->userModel->update($userId, [
            'two_factor_enabled' => 0,
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_setup_at' => null
        ]);

        if ($result) {
            // Clean up email codes
            $this->cleanExpiredEmailCodes($userId);
            
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
     * Check rate limiting for 2FA attempts
     */
    public function checkRateLimit(string $ipAddress, ?int $userId = null): bool
    {
        $rateLimitMinutes = (int)$this->settingModel->getValue('two_factor_rate_limit_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - ($rateLimitMinutes * 60));

        $query = "SELECT COUNT(*) as attempts FROM two_factor_verification_attempts 
                  WHERE ip_address = ? AND created_at > ?";
        $params = [$ipAddress, $since];

        if ($userId) {
            $query .= " AND user_id = ?";
            $params[] = $userId;
        }

        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch();

        // Allow max 5 attempts per IP, 3 per user per IP
        $maxAttempts = $userId ? 3 : 5;
        return $result['attempts'] < $maxAttempts;
    }

    /**
     * Record 2FA verification attempt
     */
    public function recordAttempt(int $userId, string $ipAddress, bool $success): void
    {
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO two_factor_verification_attempts (user_id, ip_address, success) VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $ipAddress, $success ? 1 : 0]);
    }

    /**
     * Clean up expired email codes
     */
    private function cleanExpiredEmailCodes(int $userId): void
    {
        $pdo = \Core\Database::getConnection();
        $stmt = $pdo->prepare(
            "DELETE FROM two_factor_email_codes WHERE user_id = ? AND expires_at < NOW()"
        );
        $stmt->execute([$userId]);
    }

}
