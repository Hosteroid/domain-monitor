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
        $verificationCode = $_POST['verification_code'] ?? '';

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
        if (!$this->twoFactorService->verifyTotpCode($secret, $verificationCode)) {
            $_SESSION['error'] = 'Invalid verification code. Please try again.';
            $this->redirect('/2fa/setup');
            return;
        }

        // Generate backup codes
        $backupCodes = $this->twoFactorService->generateBackupCodes();

        // Enable 2FA
        if ($this->twoFactorService->enableTwoFactor($userId, $secret, $backupCodes)) {
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

        if (!$user || !$user['two_factor_enabled']) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/login');
            return;
        }

        $this->view('2fa/verify', [
            'user' => $user,
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

        if (!$user || !$user['two_factor_enabled']) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/login');
            return;
        }

        // Check rate limiting
        if (!$this->twoFactorService->checkRateLimit($ipAddress, $userId)) {
            $_SESSION['error'] = 'Too many failed attempts. Please try again later.';
            $this->redirect('/2fa/verify');
            return;
        }

        $verificationCode = trim($_POST['verification_code'] ?? '');
        $verified = false;

        if (!empty($verificationCode)) {
            // Try TOTP code first (6 digits)
            if (strlen($verificationCode) === 6 && is_numeric($verificationCode)) {
                if ($this->twoFactorService->verifyTotpCode($user['two_factor_secret'], $verificationCode)) {
                    $verified = true;
                }
            }

            // Try email code if TOTP failed (6 digits)
            if (!$verified && strlen($verificationCode) === 6 && is_numeric($verificationCode)) {
                if ($this->twoFactorService->verifyEmailCode($userId, $verificationCode)) {
                    $verified = true;
                }
            }

            // Try backup code (8 characters)
            if (!$verified && strlen($verificationCode) === 8) {
                if ($this->twoFactorService->verifyBackupCode($userId, $verificationCode)) {
                    $verified = true;
                }
            }
        }

        // Record attempt
        $this->twoFactorService->recordAttempt($userId, $ipAddress, $verified);

        if ($verified) {
            // Clear 2FA requirement and complete login
            unset($_SESSION['2fa_required']);
            
            // Determine which method was used
            $method = 'unknown';
            if (strlen($verificationCode) === 6 && is_numeric($verificationCode)) {
                // Try to determine if it was TOTP or email by checking which one succeeded
                if ($this->twoFactorService->verifyTotpCode($user['two_factor_secret'], $verificationCode)) {
                    $method = 'totp';
                } else {
                    $method = 'email';
                }
            } elseif (strlen($verificationCode) === 8) {
                $method = 'backup';
            }

            $this->logger->info('2FA verification successful', [
                'user_id' => $userId,
                'method' => $method
            ]);

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

            // Check rate limit
            if (!$this->twoFactorService->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '', $userId)) {
                $this->jsonResponse(['success' => false, 'error' => 'Rate limit exceeded. Please try again later.']);
                return;
            }

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

        // Require 2FA verification to disable 2FA
        $verificationCode = trim($_POST['verification_code'] ?? '');
        if (empty($verificationCode)) {
            $_SESSION['error'] = 'Please enter your 2FA verification code to disable two-factor authentication';
            $this->redirect('/profile');
            return;
        }

        // Verify the code using any available method
        $verified = false;
        
        // Try TOTP code first
        if ($this->twoFactorService->verifyTotpCode($user['two_factor_secret'], $verificationCode)) {
            $verified = true;
        }
        
        // Try email code if TOTP failed
        if (!$verified && $user['email_verified']) {
            if ($this->twoFactorService->verifyEmailCode($userId, $verificationCode)) {
                $verified = true;
            }
        }
        
        // Try backup code if other methods failed
        if (!$verified) {
            if ($this->twoFactorService->verifyBackupCode($userId, $verificationCode)) {
                $verified = true;
            }
        }

        if (!$verified) {
            $_SESSION['error'] = 'Invalid verification code. Please enter a valid 2FA code to disable two-factor authentication';
            $this->redirect('/profile');
            return;
        }

        // Check if 2FA is forced
        if ($this->twoFactorService->isTwoFactorRequired($userId)) {
            $_SESSION['error'] = 'Two-factor authentication is required and cannot be disabled';
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
     * Send JSON response
     */
    private function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
