<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\User;
use App\Models\Setting;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Helpers\EmailHelper;
use App\Services\Logger;

class AuthController extends Controller
{
    private User $userModel;
    private Setting $settingModel;
    private Logger $logger;

    public function __construct()
    {
        $this->userModel = new User();
        $this->settingModel = new Setting();
        $this->logger = new Logger('auth');
    }

    /**
     * Show login form
     */
    public function showLogin()
    {
        // If already logged in, redirect to dashboard
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/');
        }
        
        // Check if registration is enabled
        $registrationEnabled = $this->settingModel->getValue('registration_enabled');
        
        // Get CAPTCHA settings
        $captchaSettings = $this->settingModel->getCaptchaSettings();

        $this->view('auth/login', [
            'title' => 'Login',
            'registrationEnabled' => $registrationEnabled,
            'captchaSettings' => $captchaSettings
        ]);
    }

    /**
     * Process login
     */
    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/login');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/login');

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? ''; // Don't trim - passwords may have intentional spaces
        $remember = isset($_POST['remember']);

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = $_POST['captcha_response'] ?? '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/login');
            return;
        }

        // Validate input
        if (empty($username) || empty($password)) {
            $_SESSION['error'] = 'Username and password are required';
            $this->redirect('/login');
            return;
        }

        // Find user by username or email
        $user = $this->userModel->findByUsername($username);
        
        // If not found by username, try email
        if (!$user && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $users = $this->userModel->where('email', $username);
            if (!empty($users) && $users[0]['is_active']) {
                $user = $users[0];
            }
        }

        if (!$user) {
            $logger = new \App\Services\Logger();
            $logger->warning("Login failed - User not found or not active", [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            $_SESSION['error'] = 'Invalid username or password';
            $this->redirect('/login');
            return;
        }

        // Verify password
        if (!$this->userModel->verifyPassword($password, $user['password'])) {
            $logger = new \App\Services\Logger();
            $logger->warning("Login failed - Password verification failed", [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            $_SESSION['error'] = 'Invalid username or password';
            $this->redirect('/login');
            return;
        }
        
        $logger = new \App\Services\Logger();
        $logger->info("Login successful", [
            'username' => $username,
            'user_id' => $user['id'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        // Check if email verification is required
        $requireVerification = $this->settingModel->getValue('require_email_verification');
        if ($requireVerification && !$user['email_verified'] && $user['role'] !== 'admin') {
            $_SESSION['error'] = 'Please verify your email address before logging in';
            $_SESSION['pending_verification_email'] = $user['email'];
            $this->redirect('/verify-email');
            return;
        }

        // Check if 2FA is required
        $twoFactorService = new \App\Services\TwoFactorService();
        $policy = $twoFactorService->getTwoFactorPolicy();
        
        if ($policy !== 'disabled' && $user['two_factor_enabled']) {
            // User has 2FA enabled - require verification
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['2fa_required'] = true;
            
            // Clear any existing session messages before redirecting to 2FA
            unset($_SESSION['error']);
            unset($_SESSION['success']);
            
            $this->redirect('/2fa/verify');
            return;
        }

        // Check if 2FA is forced for this user
        if ($twoFactorService->isTwoFactorRequired($user['id'])) {
            $_SESSION['error'] = 'You must enable two-factor authentication to continue. Please contact an administrator.';
            $this->redirect('/login');
            return;
        }

        // Login successful - create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        // Clear any existing session messages before successful login
        unset($_SESSION['error']);
        unset($_SESSION['success']);
        unset($_SESSION['info']);

        // Session is automatically tracked by DatabaseSessionHandler
        // No need to manually create session record

        // Handle remember me
        if ($remember) {
            $this->createRememberToken($user['id']);
        }

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Set success message for login
        $_SESSION['success'] = 'Login successful! Welcome back, ' . htmlspecialchars($user['full_name']) . '.';

        // Redirect to dashboard
        $this->redirect('/');
    }

    /**
     * Show registration form
     */
    public function showRegister()
    {
        // Check if already logged in
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/');
            return;
        }

        // Check if registration is enabled
        $registrationEnabled = $this->settingModel->getValue('registration_enabled');
        if (!$registrationEnabled) {
            $_SESSION['error'] = 'Registration is currently disabled';
            $this->redirect('/login');
            return;
        }

        // Get CAPTCHA settings
        $captchaSettings = $this->settingModel->getCaptchaSettings();

        $this->view('auth/register', [
            'title' => 'Register',
            'captchaSettings' => $captchaSettings
        ]);
    }

    /**
     * Process registration
     */
    public function register()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/register');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/register');

        // Check if registration is enabled
        $registrationEnabled = $this->settingModel->getValue('registration_enabled');
        if (!$registrationEnabled) {
            $_SESSION['error'] = 'Registration is currently disabled';
            $this->redirect('/login');
            return;
        }

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = $_POST['captcha_response'] ?? '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/register');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Validate inputs
        if (empty($username) || empty($email) || empty($fullName) || empty($password)) {
            $_SESSION['error'] = 'All fields are required';
            $this->redirect('/register');
            return;
        }

        // Validate username format and length
        $usernameError = \App\Helpers\InputValidator::validateUsername($username, 3, 50);
        if ($usernameError) {
            $_SESSION['error'] = $usernameError;
            $this->redirect('/register');
            return;
        }

        // Validate full name length
        $nameError = \App\Helpers\InputValidator::validateLength($fullName, 255, 'Full name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect('/register');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address';
            $this->redirect('/register');
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $_SESSION['error'] = 'Username can only contain letters, numbers, and underscores';
            $this->redirect('/register');
            return;
        }

        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters long';
            $this->redirect('/register');
            return;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'Passwords do not match';
            $this->redirect('/register');
            return;
        }

        // Check if username already exists
        $existingUser = $this->userModel->findByUsername($username);
        if ($existingUser) {
            $_SESSION['error'] = 'Username is already taken';
            $this->redirect('/register');
            return;
        }

        // Check if email already exists
        $existingEmail = $this->userModel->where('email', $email);
        if (!empty($existingEmail)) {
            $_SESSION['error'] = 'Email address is already registered';
            $this->redirect('/register');
            return;
        }

        try {
            // Create user account
            $userId = $this->userModel->createUser($username, $password, $email, $fullName);
            
            // Create welcome notification
            try {
                $notificationService = new \App\Services\NotificationService();
                $notificationService->notifyWelcome($userId, $username);
            } catch (\Exception $e) {
                // Don't fail registration if notification fails
                $logger = new \App\Services\Logger();
                $logger->error("Failed to create welcome notification", [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
            }

            // Check if email verification is required
            $requireVerification = $this->settingModel->getValue('require_email_verification');
            
            if ($requireVerification) {
                // Generate verification token
                $token = bin2hex(random_bytes(32));
                
                // Save token to database using model
                $this->userModel->updateEmailVerificationToken($userId, $token);

                // Send verification email
                $this->sendVerificationEmail($email, $fullName, $token);

                $_SESSION['success'] = 'Account created successfully! Please check your email to verify your account.';
                $_SESSION['pending_verification_email'] = $email;
                $this->redirect('/verify-email');
            } else {
                // Mark as verified and log them in using model
                $this->userModel->markEmailAsVerified($userId);

                $_SESSION['success'] = 'Account created successfully! You can now log in.';
                $this->redirect('/login');
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to create account: ' . $e->getMessage();
            $this->redirect('/register');
        }
    }

    /**
     * Show email verification page
     */
    public function showVerifyEmail()
    {
        $token = $_GET['token'] ?? null;
        
        if ($token) {
            // Verify the token
            $this->verifyEmail($token);
            return;
        }

        // Show pending verification page
        $email = $_SESSION['pending_verification_email'] ?? 'your email';
        $this->view('auth/verify-email', [
            'title' => 'Verify Email',
            'email' => $email
        ]);
    }

    /**
     * Verify email with token
     */
    private function verifyEmail($token)
    {
        try {
            // Debug logging
            $this->logger->info("Email verification attempt with token: " . substr($token, 0, 10) . "...");
            
            // Find user by verification token using model
            $user = $this->userModel->findByVerificationToken($token);

            if (!$user) {
                $this->logger->warning("No user found with verification token: " . substr($token, 0, 10) . "...");
                
                // Debug: Check if any user has this token (regardless of verification status)
                $debugUser = $this->userModel->findByVerificationTokenDebug($token);
                if ($debugUser) {
                    $this->logger->info("Debug: Found user with token - ID: {$debugUser['id']}, Email: {$debugUser['email']}, Verified: {$debugUser['email_verified']}");
                } else {
                    $this->logger->warning("Debug: No user found with this token at all");
                }
                
                $this->view('auth/verify-email', [
                    'title' => 'Verification Failed',
                    'error' => true,
                    'errorMessage' => 'Invalid or expired verification link.'
                ]);
                return;
            }

            // Check if token is expired (24 hours)
            $sentAt = strtotime($user['email_verification_sent_at']);
            if (time() - $sentAt > 86400) {
                $this->view('auth/verify-email', [
                    'title' => 'Verification Failed',
                    'error' => true,
                    'errorMessage' => 'Verification link has expired. Please request a new one.'
                ]);
                return;
            }

            // Mark email as verified using model
            $this->userModel->verifyEmailByToken($user['id']);

            $this->view('auth/verify-email', [
                'title' => 'Email Verified',
                'verified' => true
            ]);

        } catch (\Exception $e) {
            $this->view('auth/verify-email', [
                'title' => 'Verification Failed',
                'error' => true,
                'errorMessage' => 'An error occurred during verification.'
            ]);
        }
    }

    /**
     * Resend verification email
     */
    public function resendVerification()
    {
        // Only allow resend if email is in session (from registration or login attempt)
        $email = $_SESSION['pending_verification_email'] ?? '';

        if (empty($email)) {
            $_SESSION['error'] = 'Please try logging in first to resend verification email';
            $this->redirect('/login');
            return;
        }

        try {
            $users = $this->userModel->where('email', $email);
            
            if (empty($users)) {
                $_SESSION['error'] = 'Email address not found';
                $this->redirect('/verify-email');
                return;
            }

            $user = $users[0];

            if ($user['email_verified']) {
                $_SESSION['info'] = 'Email is already verified. You can log in now.';
                $this->redirect('/login');
                return;
            }

            // Generate new verification token
            $token = bin2hex(random_bytes(32));
            
            // Update verification token using model
            $this->userModel->updateEmailVerificationToken($user['id'], $token);

            // Send verification email
            $this->sendVerificationEmail($user['email'], $user['full_name'], $token);

            $_SESSION['success'] = 'Verification email sent! Please check your inbox.';
            $_SESSION['pending_verification_email'] = $email;
            $this->redirect('/verify-email');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to resend verification email. Please try again.';
            $this->redirect('/verify-email');
        }
    }

    /**
     * Show forgot password form
     */
    public function showForgotPassword()
    {
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/');
            return;
        }

        // Get CAPTCHA settings
        $captchaSettings = $this->settingModel->getCaptchaSettings();

        $this->view('auth/forgot-password', [
            'title' => 'Forgot Password',
            'captchaSettings' => $captchaSettings
        ]);
    }

    /**
     * Process forgot password request
     */
    public function forgotPassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/forgot-password');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/forgot-password');

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = $_POST['captcha_response'] ?? '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/forgot-password');
            return;
        }

        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address';
            $this->redirect('/forgot-password');
            return;
        }

        try {
            $users = $this->userModel->where('email', $email);
            
            // Always show success message to prevent email enumeration
            $_SESSION['success'] = 'If an account exists with that email, you will receive password reset instructions.';

            if (!empty($users)) {
                $user = $users[0];

                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Save token to database using model
                $this->userModel->createPasswordResetToken($user['id'], $token, $expiresAt);

                // Send reset email
                $this->sendPasswordResetEmail($user['email'], $user['full_name'], $token);
            }

            $this->redirect('/forgot-password');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'An error occurred. Please try again.';
            $this->redirect('/forgot-password');
        }
    }

    /**
     * Show reset password form
     */
    public function showResetPassword()
    {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            $_SESSION['error'] = 'Invalid reset link';
            $this->redirect('/login');
            return;
        }

        // Verify token exists and is not expired using model
        $resetToken = $this->userModel->findPasswordResetToken($token);

        if (!$resetToken) {
            $_SESSION['error'] = 'Invalid or expired reset link';
            $this->redirect('/forgot-password');
            return;
        }

        // Get CAPTCHA settings
        $captchaSettings = $this->settingModel->getCaptchaSettings();

        $this->view('auth/reset-password', [
            'title' => 'Reset Password',
            'token' => $token,
            'captchaSettings' => $captchaSettings
        ]);
    }

    /**
     * Process password reset
     */
    public function resetPassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/login');
            return;
        }

        // CSRF Protection
        $token = $_POST['token'] ?? '';
        $this->verifyCsrf('/reset-password?token=' . urlencode($token));

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = $_POST['captcha_response'] ?? '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $token = $_POST['token'] ?? '';
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Validate inputs
        if (empty($token) || empty($password) || empty($passwordConfirm)) {
            $_SESSION['error'] = 'All fields are required';
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters long';
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'Passwords do not match';
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        try {
            // Verify token using model
            $resetToken = $this->userModel->findPasswordResetToken($token);

            if (!$resetToken) {
                $_SESSION['error'] = 'Invalid or expired reset link';
                $this->redirect('/forgot-password');
                return;
            }

            // Update password
            $this->userModel->changePassword($resetToken['user_id'], $password);

            // Mark token as used using model
            $this->userModel->markPasswordResetTokenAsUsed($resetToken['id']);

            $_SESSION['success'] = 'Password reset successfully! You can now log in.';
            $this->redirect('/login');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to reset password. Please try again.';
            $this->redirect('/reset-password?token=' . urlencode($token));
        }
    }

    /**
     * Create remember me token linked to current session
     */
    private function createRememberToken($userId)
    {
        try {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $sessionId = session_id();

            // Create remember token using model
            $this->userModel->createRememberToken($userId, $sessionId, $token, $expiresAt);

            // Set cookie
            setcookie('remember_token', $token, [
                'expires' => strtotime('+30 days'),
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

        } catch (\Exception $e) {
            // Silently fail - remember me is not critical
            $logger = new \App\Services\Logger();
            $logger->error("Failed to create remember token", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check and process remember me token
     */
    public function checkRememberToken()
    {
        $token = $_COOKIE['remember_token'] ?? null;

        if (!$token) {
            return false;
        }

        try {
            // Find user by remember token using model
            $rememberToken = $this->userModel->findByRememberToken($token);

            if ($rememberToken) {
                $user = $this->userModel->find($rememberToken['user_id']);
                
                if ($user && $user['is_active']) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    // Session is automatically tracked by DatabaseSessionHandler
                    // No need to manually create session record

                    return true;
                }
            }

            // Invalid token - clear cookie
            setcookie('remember_token', '', time() - 3600, '/');

        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    /**
     * Send verification email
     */
    private function sendVerificationEmail($email, $fullName, $token)
    {
        $result = EmailHelper::sendVerificationEmail($email, $fullName, $token);
        
        if (!$result['success']) {
            // Log error but don't fail the registration
            $this->logger->error('Failed to send verification email', [
                'email' => $email,
                'full_name' => $fullName,
                'debug_info' => $result['debug_info'] ?? null,
                'error' => $result['error'] ?? null
            ]);
        } else {
            $this->logger->info('Verification email sent successfully', [
                'email' => $email,
                'full_name' => $fullName
            ]);
        }
    }

    /**
     * Send password reset email
     */
    private function sendPasswordResetEmail($email, $fullName, $token)
    {
        $result = EmailHelper::sendPasswordResetEmail($email, $fullName, $token);
        
        if (!$result['success']) {
            // Log error
            $this->logger->error('Failed to send password reset email', [
                'email' => $email,
                'full_name' => $fullName,
                'debug_info' => $result['debug_info'] ?? null,
                'error' => $result['error'] ?? null
            ]);
        } else {
            $this->logger->info('Password reset email sent successfully', [
                'email' => $email,
                'full_name' => $fullName
            ]);
        }
    }


    /**
     * Logout
     */
    public function logout()
    {
        // Clear remember me token if exists
        if (isset($_COOKIE['remember_token'])) {
            $token = $_COOKIE['remember_token'];
            
            try {
                // Delete remember token using model
                $this->userModel->deleteRememberToken($token);
            } catch (\Exception $e) {
                // Silently fail
            }

            setcookie('remember_token', '', time() - 3600, '/');
        }

        // Destroy session (DatabaseSessionHandler automatically deletes from DB)
        session_destroy();
        session_start();

        $_SESSION['success'] = 'You have been logged out successfully';
        $this->redirect('/login');
    }
}
