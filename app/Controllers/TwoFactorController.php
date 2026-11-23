<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\Logger;

class TwoFactorController extends Controller
{
    private User $userModel;
    private TwoFactorService $twoFactorService;
    private Logger $logger;

    public function __construct()
    {
        $this->userModel = new User();
        $this->twoFactorService = new TwoFactorService();
        $this->logger = new Logger('2fa');
    }

    /**
     * Show 2FA setup page
     */
    public function setup()
    {
        Auth::require();
        
        $userId = Auth::id();
        $user = $this->userModel->find($userId);
        
        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/profile');
            return;
        }

        // Check if 2FA is disabled by admin
        $policy = $this->twoFactorService->getTwoFactorPolicy();
        if ($policy === 'disabled') {
            $_SESSION['error'] = 'Two-factor authentication is disabled';
            $this->redirect('/profile');
            return;
        }

        // Check if email is verified
        if (!$user['email_verified']) {
            $_SESSION['error'] = 'You must verify your email address before enabling 2FA';
            $this->redirect('/profile');
            return;
        }

        // Check if already enabled
        if ($user['two_factor_enabled']) {
            $_SESSION['info'] = 'Two-factor authentication is already enabled';
            $this->redirect('/profile');
            return;
        }

        // Generate or reuse existing secret for this setup session
        if (!isset($_SESSION['2fa_setup_secret'])) {
            $_SESSION['2fa_setup_secret'] = $this->twoFactorService->generateSecret();
        }

        $secret = $_SESSION['2fa_setup_secret'];
        $qrCodeUrl = $this->twoFactorService->generateQrCodeDataUri($user['email'], $secret);

        $this->view('2fa/setup', [
            'user' => $user,
            'secret' => $secret,
            'qrCodeUrl' => $qrCodeUrl,
            'title' => 'Setup Two-Factor Authentication'
        ]);
    }

    /**
     * Verify 2FA setup and enable it
     */
    public function verifySetup()
    {
        Auth::require();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/2fa/setup');
            return;
        }

        $this->verifyCsrf('/2fa/setup');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);
        // Text only: a field sent as an array reads as empty
        $verificationCode = is_string($_POST['verification_code'] ?? null) ? $_POST['verification_code'] : '';

        if (!$user || !$user['email_verified'] || $user['two_factor_enabled']) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/2fa/setup');
            return;
        }

        // Get the secret from session (should exist from setup page)
        if (!isset($_SESSION['2fa_setup_secret'])) {
            $_SESSION['error'] = 'Setup session expired. Please start over.';
            $this->redirect('/2fa/setup');
            return;
        }

        $secret = $_SESSION['2fa_setup_secret'];

        if (empty($verificationCode)) {
            $_SESSION['error'] = 'Please enter the verification code';
            $this->redirect('/2fa/setup');
            return;
        }

        // Verify the code
        $step = $this->twoFactorService->totpStep($secret, $verificationCode);
        if ($step === null) {
            $_SESSION['error'] = 'Invalid verification code. Please try again.';
            $this->redirect('/2fa/setup');
            return;
        }

        // Generate backup codes
        $backupCodes = $this->twoFactorService->generateBackupCodes();

        // Enable 2FA. The step of the code goes with it: the code is used up by this
        if ($this->twoFactorService->enableTwoFactor($userId, $secret, $backupCodes, $step)) {
            $_SESSION['success'] = 'Two-factor authentication enabled successfully!';
            
            // Clear the setup secret from session
            unset($_SESSION['2fa_setup_secret']);
            
            // Store backup codes in session for display
            $_SESSION['backup_codes'] = $backupCodes;
            
            $this->redirect('/2fa/backup-codes');
        } else {
            $_SESSION['error'] = 'Failed to enable two-factor authentication';
            $this->redirect('/2fa/setup');
        }
    }

    /**
     * Cancel 2FA setup (clear session secret)
     */
    public function cancelSetup()
    {
        Auth::require();
        
        // Clear the setup secret from session
        unset($_SESSION['2fa_setup_secret']);
        
        $_SESSION['info'] = '2FA setup cancelled';
        $this->redirect('/profile');
    }

    /**
     * Show backup codes page
     */
    public function backupCodes()
    {
        Auth::require();
        
        $backupCodes = $_SESSION['backup_codes'] ?? null;
        
        if (!$backupCodes) {
            $_SESSION['error'] = 'No backup codes found';
            $this->redirect('/profile');
            return;
        }

        // Clear backup codes from session after display
        unset($_SESSION['backup_codes']);

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        $this->view('2fa/backup-codes', [
            'user' => $user,
            'backupCodes' => $backupCodes,
            'title' => 'Backup Codes'
        ]);
    }

    /**
     * Show 2FA verification page (during login)
     */
    public function showVerify()
    {
        // Check if user is in 2FA verification state
        if (!isset($_SESSION['2fa_required']) || !$_SESSION['2fa_required']) {
            $this->redirect('/');
            return;
        }

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if ($this->pendingLoginEnded($user)) {
            $this->redirect('/login');
            return;
        }

        $this->view('2fa/verify', [
            'user' => $user,
            'canSendEmailCode' => !empty($user['email_verified']),
            'title' => 'Two-Factor Authentication'
        ]);
    }

    /**
     * Process 2FA verification
     */
    public function verify()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/2fa/verify');
            return;
        }

        $this->verifyCsrf('/2fa/verify');

        // Check if user is in 2FA verification state
        if (!isset($_SESSION['2fa_required']) || !$_SESSION['2fa_required']) {
            $this->redirect('/');
            return;
        }

        $userId = Auth::id();
        $user = $this->userModel->find($userId);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($this->pendingLoginEnded($user)) {
            $this->redirect('/login');
            return;
        }

        // Text only: a field sent as an array reads as empty
        $verificationCode = is_string($_POST['verification_code'] ?? null) ? trim($_POST['verification_code']) : '';

        // Nothing is checked against input that cannot be a code, so it is not counted either
        if (!$this->twoFactorService->canBeCode($verificationCode)) {
            $_SESSION['error'] = 'Invalid verification code. Please try again.';
            $this->redirect('/2fa/verify');
            return;
        }

        // Too many wrong codes for this account: refused before anything is checked
        $limiter = new \App\Services\TwoFactorLimiter();
        $wait = $limiter->start($userId, is_string($ipAddress) ? $ipAddress : '');
        if ($wait > 0) {
            $_SESSION['error'] = \App\Services\TwoFactorLimiter::message($wait);
            $this->redirect('/2fa/verify');
            return;
        }

        // Looked at once more now that the attempt is noted: had the password been reset while
        // this request was on its way, a wrong code would be counted after the reset has
        // cleared the count
        $user = $this->userModel->find($userId);
        if ($this->pendingLoginEnded($user)) {
            $limiter->passed();
            $this->redirect('/login');
            return;
        }

        $verified = false;
        // The kind of code that was right, noted where its check passes: every check uses its
        // code up, so none can be asked a second time afterwards
        $method = 'unknown';

        if (!empty($verificationCode)) {
            // Try TOTP code first (6 digits)
            if (strlen($verificationCode) === 6 && is_numeric($verificationCode)) {
                if ($this->twoFactorService->verifyTotpCode($userId, $verificationCode)) {
                    $verified = true;
                    $method = 'totp';
                }
            }

            // Try email code if TOTP failed (6 digits)
            if (!$verified && strlen($verificationCode) === 6 && is_numeric($verificationCode)) {
                if ($this->twoFactorService->verifyEmailCode($userId, $verificationCode)) {
                    $verified = true;
                    $method = 'email';
                }
            }

            // Try backup code (8 characters)
            if (!$verified && strlen($verificationCode) === 8) {
                if ($this->twoFactorService->verifyBackupCode($userId, $verificationCode)) {
                    $verified = true;
                    $method = 'backup';
                }
            }
        }

        // Only a wrong code stays counted
        if ($verified) {
            $limiter->passed();
        } else {
            $limiter->failed();
        }

        if ($verified) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Clear 2FA requirement and complete login
            $pendingRemember = !empty($_SESSION['pending_remember']);
            unset($_SESSION['2fa_required']);
            unset($_SESSION['2fa_password_mark']);
            unset($_SESSION['pending_remember']);

            $this->logger->info('2FA verification successful', [
                'user_id' => $userId,
                'method' => $method
            ]);

            // Handle remember me (carried over from login form)
            if ($pendingRemember) {
                $authController = new \App\Controllers\AuthController();
                $authController->createRememberTokenPublic($userId);
            }

            // Update last login timestamp
            $this->userModel->updateLastLogin($userId);

            // Create login notification
            try {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $notificationService = new \App\Services\NotificationService();
                $notificationService->notifyNewLogin($userId, "2FA ($method)", $ipAddress, $userAgent);
            } catch (\Exception $e) {
                // Don't block login if notification fails
            }

            $_SESSION['success'] = 'Login successful! Welcome back, ' . htmlspecialchars($user['full_name']) . '.';
            $this->redirect('/');
        } else {
            // Notify user about failed 2FA attempt
            try {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $notificationService = new \App\Services\NotificationService();
                $notificationService->notifyFailedLogin($userId, 'Failed 2FA verification', $ipAddress, $userAgent, $user['username']);
            } catch (\Exception $e) {
                // Don't block response if notification fails
            }

            $_SESSION['error'] = 'Invalid verification code. Please try again.';
            $this->redirect('/2fa/verify');
        }
    }

    /**
     * Send email verification code
     */
    public function sendEmailCode()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/2fa/verify');
            return;
        }

        $this->verifyCsrf('/2fa/verify');

        try {
            // Check if user is in 2FA verification state
            if (!isset($_SESSION['2fa_required']) || !$_SESSION['2fa_required']) {
                $this->jsonResponse(['success' => false, 'error' => 'Invalid request']);
                return;
            }

            $userId = Auth::id();
            if (!$userId) {
                $this->jsonResponse(['success' => false, 'error' => 'User not authenticated']);
                return;
            }

            $user = $this->userModel->find($userId);
            if (!$user) {
                $this->jsonResponse(['success' => false, 'error' => 'User not found']);
                return;
            }

            if (!$user['two_factor_enabled']) {
                $this->jsonResponse(['success' => false, 'error' => 'Two-factor authentication not enabled']);
                return;
            }

            if (!$user['email_verified']) {
                $this->jsonResponse(['success' => false, 'error' => 'Email not verified']);
                return;
            }

            if ($this->pendingLoginEnded($user)) {
                $this->jsonResponse(['success' => false, 'error' => 'Please login again']);
                return;
            }

            // No new code while the account's codes are refused: it could not be entered
            $wait = (new \App\Services\TwoFactorLimiter())->wait($userId);
            if ($wait > 0) {
                $this->jsonResponse(['success' => false, 'error' => \App\Services\TwoFactorLimiter::message($wait)]);
                return;
            }

            // Limits the number of codes sent itself
            $result = $this->twoFactorService->generateEmailCode($userId);
            $this->jsonResponse($result);

        } catch (\Exception $e) {
            $this->logger->error('Error sending 2FA email code', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to send email code']);
        }
    }

    /**
     * Disable 2FA
     */
    public function disable()
    {
        Auth::require();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if (!$user || !$user['two_factor_enabled']) {
            $_SESSION['error'] = 'Two-factor authentication is not enabled';
            $this->redirect('/profile');
            return;
        }

        // The policy "forced" keeps 2FA on. Asked before the code is, so that no code is used up
        // or counted for a request that cannot go through. Not isTwoFactorRequired(): that says
        // whether a user still has to switch 2FA on, which is never the case here.
        if ($this->twoFactorService->getTwoFactorPolicy() === 'forced') {
            $_SESSION['error'] = 'Two-factor authentication is required and cannot be disabled';
            $this->redirect('/profile');
            return;
        }

        // Require 2FA verification to disable 2FA. Text only: a field sent as an array reads as empty.
        $verificationCode = is_string($_POST['verification_code'] ?? null) ? trim($_POST['verification_code']) : '';
        if (empty($verificationCode)) {
            $_SESSION['error'] = 'Please enter your 2FA verification code to disable two-factor authentication';
            $this->redirect('/profile');
            return;
        }

        // Verify the code using any available method
        $error = $this->codeError($user, $verificationCode, 'Invalid verification code. Please enter a valid 2FA code to disable two-factor authentication');
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/profile');
            return;
        }

        if ($this->twoFactorService->disableTwoFactor($userId)) {
            $_SESSION['success'] = 'Two-factor authentication has been disabled';
        } else {
            $_SESSION['error'] = 'Failed to disable two-factor authentication';
        }

        $this->redirect('/profile#twofactor');
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes()
    {
        Auth::require();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if (!$user || !$user['two_factor_enabled']) {
            $_SESSION['error'] = 'Two-factor authentication is not enabled';
            $this->redirect('/profile');
            return;
        }

        // New backup codes are as good as the authenticator app, so they take a 2FA code like
        // turning 2FA off does: a signed-in session alone must not be enough to get them.
        // Text only: a field sent as an array reads as empty.
        $verificationCode = is_string($_POST['verification_code'] ?? null) ? trim($_POST['verification_code']) : '';
        if (empty($verificationCode)) {
            $_SESSION['error'] = 'Please enter your 2FA verification code to generate new backup codes';
            $this->redirect('/profile#twofactor');
            return;
        }

        $error = $this->codeError($user, $verificationCode, 'Invalid verification code. Please enter a valid 2FA code to generate new backup codes');
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/profile#twofactor');
            return;
        }

        // Generate new backup codes
        $backupCodes = $this->twoFactorService->generateBackupCodes();

        // Update user with new backup codes
        if ($this->userModel->update($userId, [
            'two_factor_backup_codes' => json_encode($backupCodes)
        ])) {
            $_SESSION['success'] = 'New backup codes generated successfully!';
            
            // Store backup codes in session for display
            $_SESSION['backup_codes'] = $backupCodes;
            
            $this->redirect('/2fa/backup-codes');
        } else {
            $_SESSION['error'] = 'Failed to generate new backup codes';
            $this->redirect('/profile#twofactor');
        }
    }

    /**
     * Check the 2FA code a signed-in user confirms a change with (turning 2FA off, new backup
     * codes): from the authenticator app, from an email, or a backup code. Wrong codes are
     * counted together with those entered at login (TwoFactorLimiter).
     *
     * @param array $user Row of the users table
     * @param string $invalid What to say about a code that is not right
     * @return string|null Null when the code is right, otherwise what to tell the user
     */
    private function codeError(array $user, string $code, string $invalid): ?string
    {
        // Nothing is checked against input that cannot be a code, so it is not counted either
        if (!$this->twoFactorService->canBeCode($code)) {
            return $invalid;
        }

        $userId = (int)$user['id'];
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $limiter = new \App\Services\TwoFactorLimiter();
        $wait = $limiter->start($userId, is_string($ipAddress) ? $ipAddress : '');
        if ($wait > 0) {
            return \App\Services\TwoFactorLimiter::message($wait);
        }

        $verified = $this->twoFactorService->verifyTotpCode($userId, $code)
            || ($user['email_verified'] && $this->twoFactorService->verifyEmailCode($userId, $code))
            || $this->twoFactorService->verifyBackupCode($userId, $code);

        if (!$verified) {
            $limiter->failed();

            return $invalid;
        }
        $limiter->passed();

        return null;
    }

    /**
     * Whether a login that waits for its 2FA code is over. It belongs to the password it was
     * started with (TwoFactorService::passwordMark()) and to an account with 2FA: after a
     * password change, without a mark, or when the account is gone or has 2FA switched off, the
     * session is emptied and the caller sends the browser to the login form. Emptied, not left
     * as it is: the login form sends a session that still waits for a code back to the code page.
     *
     * @param array|null $user Row of the users table, null when there is none
     */
    private function pendingLoginEnded(?array $user): bool
    {
        $mark = $_SESSION['2fa_password_mark'] ?? null;
        if ($user && $user['two_factor_enabled'] && is_string($mark)
            && hash_equals(TwoFactorService::passwordMark($user), $mark)) {
            return false;
        }

        session_destroy();
        session_start();
        $_SESSION['error'] = 'Please login again to continue';

        return true;
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
