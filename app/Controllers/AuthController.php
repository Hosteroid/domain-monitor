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

        // Text only: a field sent as an array reads as empty
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : ''; // Don't trim - passwords may have intentional spaces
        $remember = isset($_POST['remember']);

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = is_string($_POST['captcha_response'] ?? null) ? $_POST['captcha_response'] : '';
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

        // No account has a name like this, and it could not be counted
        if (!\App\Services\LoginLimiter::validName($username)) {
            $_SESSION['error'] = 'Invalid username or password';
            $this->redirect('/login');
            return;
        }

        // Too many wrong passwords for this name. It is refused before it is looked up, so the
        // answer is the same whether the name exists or not.
        $limiter = new \App\Services\LoginLimiter();
        $wait = $limiter->start($username, is_string($remoteIp) ? $remoteIp : '');
        if ($wait > 0) {
            $_SESSION['error'] = \App\Services\LoginLimiter::message($wait);
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
            $this->logger->warning("Login failed - User not found or not active", [
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            $limiter->failed();
            $_SESSION['error'] = 'Invalid username or password';
            $this->redirect('/login');
            return;
        }

        // Verify password
        if (!$this->userModel->verifyPassword($password, $user['password'])) {
            $limiter->failed();
            $this->logger->warning("Login failed - Wrong password", [
                'username' => $username,
                'user_id' => $user['id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);

            // Notify the target user about failed login attempt (wrong password)
            try {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $notificationService = new \App\Services\NotificationService();
                $notificationService->notifyFailedLogin($user['id'], 'Wrong password', $ipAddress, $userAgent, $username);
            } catch (\Exception $e) {
                // Don't block response if notification fails
            }

            $_SESSION['error'] = 'Invalid username or password';
            $this->redirect('/login');
            return;
        }

        // The password was right, whatever the steps below decide: this attempt is no failure
        $limiter->passed();
        
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
            // A new session ID from here on, as for a login without 2FA: an ID that somebody
            // planted in this browser must not become a login that waits for its code
            session_regenerate_id(true);

            // User has 2FA enabled - require verification
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['2fa_required'] = true;
            // The code page stays open only for as long as this password is the account's
            $_SESSION['2fa_password_mark'] = \App\Services\TwoFactorService::passwordMark($user);
            $_SESSION['pending_remember'] = $remember;
            
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

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Login successful - create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        // An earlier login of this browser that still waited for its 2FA code is over
        unset($_SESSION['2fa_required'], $_SESSION['2fa_password_mark'], $_SESSION['pending_remember']);

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

        // Create login notification
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $notificationService = new \App\Services\NotificationService();
            $notificationService->notifyNewLogin($user['id'], 'Direct login', $ipAddress, $userAgent);
        } catch (\Exception $e) {
            // Don't block login if notification fails
        }

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
        $captchaResponse = is_string($_POST['captcha_response'] ?? null) ? $_POST['captcha_response'] : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/register');
            return;
        }

        // Text only: a field sent as an array reads as empty
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

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

                // Send verification email, unless this address got too many already. One that
                // did not go out is not counted.
                $limiter = new \App\Services\EmailLimiter();
                $wait = $limiter->take($email, \App\Services\EmailLimiter::VERIFY);
                if ($wait === 0 && !$this->sendVerificationEmail($email, $fullName, $token)) {
                    $limiter->giveBack();
                }

                if ($wait === 0) {
                    $_SESSION['success'] = 'Account created successfully! Please check your email to verify your account.';
                } else {
                    $_SESSION['success'] = 'Account created successfully!';
                    $_SESSION['error'] = \App\Services\EmailLimiter::message($wait);
                }
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
        // Text only: a token sent as an array is no token
        $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
        
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
            // A link saved without a send time (by an earlier version) counts as expired
            $sentAt = strtotime((string)$user['email_verification_sent_at']);
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
        $this->verifyCsrf('/verify-email');

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

            // Too many emails to this address: nothing is sent and the last link keeps working
            $limiter = new \App\Services\EmailLimiter();
            $wait = $limiter->take((string)$user['email'], \App\Services\EmailLimiter::VERIFY);
            if ($wait > 0) {
                $_SESSION['error'] = \App\Services\EmailLimiter::message($wait);
                $this->redirect('/verify-email');
                return;
            }

            // Generate new verification token
            $token = bin2hex(random_bytes(32));
            
            // Update verification token using model
            $this->userModel->updateEmailVerificationToken($user['id'], $token);

            // Send verification email. One that did not go out is not counted.
            if (!$this->sendVerificationEmail($user['email'], (string)$user['full_name'], $token)) {
                $limiter->giveBack();
                $_SESSION['error'] = 'Failed to resend verification email. Please try again.';
                $this->redirect('/verify-email');
                return;
            }

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
        $captchaResponse = is_string($_POST['captcha_response'] ?? null) ? $_POST['captcha_response'] : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/forgot-password');
            return;
        }

        // Text only: a field sent as an array reads as empty
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address';
            $this->redirect('/forgot-password');
            return;
        }

        try {
            $users = $this->userModel->where('email', $email);
            
            // Always show success message to prevent email enumeration
            $_SESSION['success'] = 'If an account exists with that email, you will receive password reset instructions.';

            // Reset links that no longer work are deleted with every request
            try {
                $this->userModel->purgePasswordResetTokens();
            } catch (\Throwable $e) {
                // Tidying up only: the request goes on
            }

            // Too many reset emails to this address: nothing is sent, and the page says the same
            // as always. Links mailed before stay valid. The count is looked at for every
            // address, also one without an account, so that both take the same steps up to here.
            $limiter = new \App\Services\EmailLimiter();
            $full = $limiter->isFull(
                !empty($users) ? (string)$users[0]['email'] : $email,
                \App\Services\EmailLimiter::RESET
            );

            if (!empty($users) && !$full) {
                $user = $users[0];

                // Counted in one step with the decision: of requests sent together only as
                // many pass as there is room for
                if ($limiter->take((string)$user['email'], \App\Services\EmailLimiter::RESET) === 0) {
                    // Generate reset token
                    $token = bin2hex(random_bytes(32));

                    // Save token to database using model
                    $this->userModel->createPasswordResetToken((int)$user['id'], $token);

                    // Send reset email. One that did not go out is not counted, and its link
                    // is not kept.
                    if (!$this->sendPasswordResetEmail($user['email'], (string)$user['full_name'], $token)) {
                        $limiter->giveBack();
                        $this->userModel->deletePasswordResetToken($token);
                    }
                }
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
        // Text only: a token sent as an array is no token
        $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';

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
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $this->verifyCsrf('/reset-password?token=' . urlencode($token));

        // Verify CAPTCHA
        $captchaService = new \App\Services\CaptchaService();
        $captchaResponse = is_string($_POST['captcha_response'] ?? null) ? $_POST['captcha_response'] : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $captchaResult = $captchaService->verifyCaptcha($captchaResponse, $remoteIp);
        
        if (!$captchaResult['success']) {
            $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
            $_SESSION['error'] = $captchaResult['error'] ?? 'CAPTCHA verification failed';
            $this->redirect('/reset-password?token=' . urlencode($token));
            return;
        }

        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

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

            // Save the password. The link works once: it is deleted in the same step, with the
            // account's other links, and a request that finds it gone was too late.
            if (!$this->userModel->resetPasswordWithToken((int)$resetToken['id'], (int)$resetToken['user_id'], $password)) {
                $_SESSION['error'] = 'Invalid or expired reset link';
                $this->redirect('/forgot-password');
                return;
            }

            // A way out of a full login attempt count: only the owner of the mailbox gets here
            $resetUser = $this->userModel->find((int)$resetToken['user_id']);
            if ($resetUser) {
                (new \App\Services\LoginLimiter())->forget($resetUser);
                // The same for wrong 2FA codes. Logins of the old password that still wait for
                // a code end by themselves.
                (new \App\Services\TwoFactorLimiter())->forget((int)$resetUser['id']);
            }

            // Browsers that were remembered with the old password are forgotten
            (new \App\Models\RememberToken())->deleteOthers((int)$resetToken['user_id']);

            $_SESSION['success'] = 'Password reset successfully! You can now log in.';
            $this->redirect('/login');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to reset password. Please try again.';
            $this->redirect('/reset-password?token=' . urlencode($token));
        }
    }

    /**
     * Public wrapper for creating remember token (used by TwoFactorController after 2FA)
     */
    public function createRememberTokenPublic(int $userId): void
    {
        $this->createRememberToken($userId);
    }

    /**
     * Create remember me token linked to current session
     */
    private function createRememberToken($userId)
    {
        try {
            $token = bin2hex(random_bytes(32));
            $sessionId = session_id();

            // Tokens past their expiry are never used again: remove them whenever a new one is made
            (new \App\Models\RememberToken())->cleanExpired();

            // Create remember token using model, valid for 30 days like the cookie below
            $this->userModel->createRememberToken($userId, $sessionId, $token, 30);

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
        $token = \Core\Auth::rememberCookie();

        if ($token === '') {
            return false;
        }

        try {
            // Find user by remember token using model
            $rememberToken = $this->userModel->findByRememberToken($token);

            if ($rememberToken) {
                $user = $this->userModel->find($rememberToken['user_id']);
                
                if ($user && $user['is_active']) {
                    // A new session ID, as after a login with a password: an ID that somebody
                    // planted in this browser must not become a logged-in session
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    // Session is automatically tracked by DatabaseSessionHandler
                    // No need to manually create session record

                    // The token belongs to this session from now on, so ending the session
                    // from Profile ends the token with it
                    try {
                        $this->userModel->linkRememberToken($token, session_id());
                    } catch (\Exception $e) {
                        // Don't block login if the link fails
                    }

                    // Update last login timestamp
                    $this->userModel->updateLastLogin($user['id']);

                    // Create login notification for remember-me auto-login
                    try {
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                        $notificationService = new \App\Services\NotificationService();
                        $notificationService->notifyNewLogin($user['id'], 'Remember me', $ipAddress, $userAgent);
                    } catch (\Exception $e) {
                        // Don't block login if notification fails
                    }

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
     *
     * @return bool Whether the mail server took it
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

        return !empty($result['success']);
    }

    /**
     * Send password reset email
     *
     * @return bool Whether the mail server took it
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

        return !empty($result['success']);
    }


    /**
     * Logout
     */
    public function logout()
    {
        // Logging out takes the security token of the page's own form, so a link on another
        // site can't do it. The address by itself, typed or bookmarked, changes nothing.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect(\Core\Auth::check() ? '/' : '/login');
            return;
        }
        $this->verifyCsrf('/');

        // Clear remember me token if exists
        if (isset($_COOKIE['remember_token'])) {
            $token = \Core\Auth::rememberCookie();
            
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
