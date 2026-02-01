<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\User;
use App\Models\SessionManager;
use App\Models\RememberToken;
use App\Services\Logger;
use App\Helpers\AvatarHelper;

class ProfileController extends Controller
{
    private User $userModel;
    private SessionManager $sessionModel;
    private RememberToken $rememberTokenModel;
    private Logger $logger;

    public function __construct()
    {
        $this->userModel = new User();
        $this->sessionModel = new SessionManager();
        $this->rememberTokenModel = new RememberToken();
        $this->logger = new Logger('profile');
    }

    /**
     * Show profile page
     */
    public function index()
    {
        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/');
            return;
        }

        // Clean old sessions when user views their profile (perfect time!)
        // This happens naturally when users check their sessions
        try {
            $this->sessionModel->cleanOldSessions();
        } catch (\Exception $e) {
            // Silent fail - don't break the page
            $logger = new \App\Services\Logger();
            $logger->error('Session cleanup failed', [
                'user_id' => \Core\Auth::id(),
                'error' => $e->getMessage()
            ]);
        }

        // Get all active sessions
        $sessions = $this->sessionModel->getByUserId($userId);
        
        // Mark current session and check for remember tokens
        $currentSessionId = session_id();
        foreach ($sessions as &$session) {
            $session['is_current'] = ($session['id'] === $currentSessionId);
            // Format timestamps for display
            $session['last_activity'] = date('Y-m-d H:i:s', $session['last_activity']);
            $session['created_at'] = date('Y-m-d H:i:s', $session['created_at']);
            
            // Check if this session has a remember token
            $rememberToken = $this->rememberTokenModel->getBySessionId($session['id']);
            $session['has_remember_token'] = !empty($rememberToken);
        }
        
        // Format sessions for display (adds deviceIcon, browserInfo, timeAgo, sessionAge)
        $formattedSessions = \App\Helpers\SessionHelper::formatForDisplay($sessions);

        $this->view('profile/index', [
            'user' => $user,
            'sessions' => $formattedSessions,
            'userModel' => $this->userModel,
            'title' => 'My Profile'
        ]);
    }

    /**
     * Update profile
     */
    public function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // Validate
        if (empty($fullName) || empty($email)) {
            $_SESSION['error'] = 'Full name and email are required';
            $this->redirect('/profile');
            return;
        }

        // Validate full name length
        $nameError = \App\Helpers\InputValidator::validateLength($fullName, 255, 'Full name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect('/profile');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address';
            $this->redirect('/profile');
            return;
        }

        // Get current user data to check if email changed
        $currentUser = $this->userModel->find($userId);
        $emailChanged = $currentUser['email'] !== $email;

        // Check if email is already taken by another user
        $existingUsers = $this->userModel->where('email', $email);
        foreach ($existingUsers as $existingUser) {
            if ($existingUser['id'] != $userId) {
            $_SESSION['error'] = 'Email address is already in use';
            $this->redirect('/profile');
            return;
        }
        }

        // Prepare update data
        $updateData = [
            'full_name' => $fullName,
            'email' => $email,
        ];

        // If email changed, mark as unverified and send verification email
        if ($emailChanged) {
            $updateData['email_verified'] = null;
            
            // Generate new verification token
            $verificationToken = bin2hex(random_bytes(32));
            $updateData['email_verification_token'] = $verificationToken;
        }

        // Update user
        $this->userModel->update($userId, $updateData);

        // Update session
        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;

        // Send verification email if email changed
        if ($emailChanged) {
            try {
                \App\Helpers\EmailHelper::sendVerificationEmail($email, $fullName, $verificationToken);
                $_SESSION['success'] = 'Profile updated successfully. Please check your new email address for a verification link.';
            } catch (\Exception $e) {
                $_SESSION['success'] = 'Profile updated successfully, but verification email could not be sent. Please try resending verification.';
                $this->logger->error("Failed to send verification email after profile update", [
                    'user_id' => $userId,
                    'email' => $email,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $_SESSION['success'] = 'Profile updated successfully';
        }
        
        $this->redirect('/profile');
    }

    /**
     * Change password
     */
    public function changePassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';

        // Validate
        if (empty($currentPassword) || empty($newPassword) || empty($newPasswordConfirm)) {
            $_SESSION['error'] = 'All fields are required';
            $this->redirect('/profile');
            return;
        }

        if (strlen($newPassword) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters long';
            $this->redirect('/profile');
            return;
        }

        if ($newPassword !== $newPasswordConfirm) {
            $_SESSION['error'] = 'New passwords do not match';
            $this->redirect('/profile');
            return;
        }

        // Get user
            $user = $this->userModel->find($userId);

            // Verify current password
            if (!$this->userModel->verifyPassword($currentPassword, $user['password'])) {
                $_SESSION['error'] = 'Current password is incorrect';
                $this->redirect('/profile');
                return;
            }

            // Update password
            $this->userModel->changePassword($userId, $newPassword);

            $_SESSION['success'] = 'Password changed successfully';
            $this->redirect('/profile');
    }

    /**
     * Delete account
     */
    public function delete()
    {
        // Ensure POST method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        // Don't allow admins to delete their own account
        if ($user['role'] === 'admin') {
            $_SESSION['error'] = 'Admin accounts cannot be deleted';
            $this->redirect('/profile');
            return;
        }

        // Delete user (cascade will handle related records)
        $this->userModel->delete($userId);

        // Logout
        session_destroy();
        session_start();

        $_SESSION['success'] = 'Your account has been deleted';
        $this->redirect('/login');
    }

    /**
     * Resend email verification
     */
    public function resendVerification()
    {
        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if ($user['email_verified']) {
            $_SESSION['info'] = 'Your email is already verified';
            $this->redirect('/profile');
            return;
        }

        try {
            // Generate new verification token
            $token = bin2hex(random_bytes(32));
            
            // Debug logging
            $this->logger->info("Generated new verification token for user {$userId}: " . substr($token, 0, 10) . "...");
            
            // Update verification token in database
            $this->userModel->updateEmailVerificationToken($userId, $token);

            // Send verification email
            \App\Helpers\EmailHelper::sendVerificationEmail($user['email'], $user['full_name'], $token);

            $_SESSION['success'] = 'Verification email sent! Please check your inbox.';
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to resend verification email. Please try again.';
            $this->logger->error("Failed to resend verification email", [
                'user_id' => $userId,
                'email' => $user['email'],
                'error' => $e->getMessage()
            ]);
        }
        
        $this->redirect('/profile');
    }

    /**
     * Logout other sessions (actually terminates them!)
     */
    public function logoutOtherSessions()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $currentSessionId = session_id();

        if (!$currentSessionId) {
            $_SESSION['error'] = 'No active session found';
            $this->redirect('/profile');
            return;
        }

        try {
            // Get all other sessions first to delete their remember tokens
            $allSessions = $this->sessionModel->getByUserId($userId);
            $deletedTokens = 0;
            foreach ($allSessions as $session) {
                if ($session['id'] !== $currentSessionId) {
                    $deletedTokens += $this->rememberTokenModel->deleteBySessionId($session['id']);
                }
            }
            
            // Delete all other sessions (this actually logs them out!)
            $count = $this->sessionModel->deleteOtherSessions($userId, $currentSessionId);
            
            // Perfect time to clean all old sessions (user is security-conscious)
            $this->sessionModel->cleanOldSessions();
            
            $message = "Terminated {$count} other session(s) - those devices are now logged out";
            if ($deletedTokens > 0) {
                $message .= " ({$deletedTokens} remember tokens removed)";
            }
            $_SESSION['success'] = $message;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to terminate other sessions';
        }

        $this->redirect('/profile#sessions');
    }

    /**
     * Logout specific session (actually terminates it!)
     */
    public function logoutSession($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $sessionId = $params['sessionId'] ?? '';
        $userId = Auth::id();
        $currentSessionId = session_id();

        if (empty($sessionId)) {
            $_SESSION['error'] = 'Invalid session';
            $this->redirect('/profile');
            return;
        }

        try {
            // Get the session to verify ownership
            $session = $this->sessionModel->getById($sessionId);

            if (!$session) {
                $_SESSION['error'] = 'Session not found';
                $this->redirect('/profile');
                return;
            }

            // Verify session belongs to current user
            if ($session['user_id'] != $userId) {
                $_SESSION['error'] = 'Unauthorized action';
                $this->redirect('/profile');
                return;
            }

            // Prevent deleting current session
            if ($session['id'] === $currentSessionId) {
                $_SESSION['error'] = 'Cannot delete your current session. Use logout instead.';
                $this->redirect('/profile');
                return;
            }

            // Delete the session (this actually logs out that device!)
            $this->sessionModel->deleteById($sessionId);
            
            // Also delete any remember token associated with this session
            $deletedTokens = $this->rememberTokenModel->deleteBySessionId($sessionId);
            
            $message = 'Session terminated - that device is now logged out';
            if ($deletedTokens > 0) {
                $message .= ' (remember me disabled)';
            }
            $_SESSION['success'] = $message;

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to terminate session';
        }

        $this->redirect('/profile#sessions');
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/profile');
            return;
        }

        // Check if file was uploaded
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
            $_SESSION['error'] = 'Please select a file to upload';
            $this->logger->warning("Avatar upload attempted without file", [
                'user_id' => $userId,
                'files' => $_FILES
            ]);
            $this->redirect('/profile');
            return;
        }

        $file = $_FILES['avatar'];

        // Log file details for debugging
        $this->logger->info("Avatar upload attempt", [
            'user_id' => $userId,
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'file_type' => $file['type'],
            'file_error' => $file['error'],
            'tmp_name' => $file['tmp_name']
        ]);

        // Validate the uploaded file
        $validation = AvatarHelper::validateAvatarFile($file);
        if (!$validation['valid']) {
            $_SESSION['error'] = $validation['error'];
            $this->logger->warning("Avatar upload validation failed", [
                'user_id' => $userId,
                'file_name' => $file['name'],
                'validation_error' => $validation['error']
            ]);
            $this->redirect('/profile');
            return;
        }

        try {
            // Ensure upload directory exists
            $this->logger->info("Ensuring upload directory exists", [
                'detected_web_root' => AvatarHelper::getDetectedWebRoot()
            ]);
            if (!AvatarHelper::ensureUploadDirectory()) {
                throw new \Exception('Failed to create upload directory: ' . AvatarHelper::getAvatarPath(''));
            }

            // Generate unique filename
            $newFilename = AvatarHelper::generateAvatarFilename($file['name'], $userId);
            $uploadPath = AvatarHelper::getAvatarPath($newFilename);
            
            $this->logger->info("Generated avatar filename", [
                'user_id' => $userId,
                'original_name' => $file['name'],
                'new_filename' => $newFilename,
                'upload_path' => $uploadPath
            ]);

            // Check if temp file exists and is readable
            if (!file_exists($file['tmp_name'])) {
                throw new \Exception('Temporary file does not exist: ' . $file['tmp_name']);
            }
            
            if (!is_readable($file['tmp_name'])) {
                throw new \Exception('Temporary file is not readable: ' . $file['tmp_name']);
            }

            // Move uploaded file
            $this->logger->info("Attempting to move uploaded file", [
                'from' => $file['tmp_name'],
                'to' => $uploadPath
            ]);
            
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new \Exception('Failed to save uploaded file from ' . $file['tmp_name'] . ' to ' . $uploadPath);
            }

            // Verify file was actually saved
            if (!file_exists($uploadPath)) {
                throw new \Exception('File was not saved to expected location: ' . $uploadPath);
            }

            // Delete old avatar if it exists
            if (!empty($user['avatar']) && $user['avatar'] !== 'gravatar' && $user['avatar'] !== 'no_gravatar') {
                $this->logger->info("Deleting old avatar", [
                    'user_id' => $userId,
                    'old_avatar' => $user['avatar']
                ]);
                AvatarHelper::deleteAvatarFile($user['avatar']);
            }

            // Update user record with new avatar filename
            $this->logger->info("Updating user record with new avatar", [
                'user_id' => $userId,
                'new_avatar' => $newFilename
            ]);
            
            $updateResult = $this->userModel->update($userId, ['avatar' => $newFilename]);
            
            if (!$updateResult) {
                throw new \Exception('Failed to update user record in database');
            }

            $_SESSION['success'] = 'Avatar updated successfully!';
            
            $this->logger->info("Avatar upload completed successfully", [
                'user_id' => $userId,
                'filename' => $newFilename,
                'file_size' => filesize($uploadPath)
            ]);

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to upload avatar: ' . $e->getMessage();
            $this->logger->error("Avatar upload failed", [
                'user_id' => $userId,
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        $this->redirect('/profile');
    }

    /**
     * Delete avatar
     */
    public function deleteAvatar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/profile');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/profile');

        $userId = Auth::id();
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/profile');
            return;
        }

        try {
            // Delete avatar file if it exists (only if it's an uploaded file)
            if (!empty($user['avatar']) && $user['avatar'] !== 'gravatar' && $user['avatar'] !== 'no_gravatar') {
                $this->logger->info("Deleting avatar file", [
                    'user_id' => $userId,
                    'avatar_file' => $user['avatar']
                ]);
                AvatarHelper::deleteAvatarFile($user['avatar']);
            }

            // Clear avatar field in database
            $this->logger->info("Clearing avatar field in database", [
                'user_id' => $userId,
                'current_avatar' => $user['avatar']
            ]);
            
            $updateResult = $this->userModel->update($userId, ['avatar' => null]);
            
            if (!$updateResult) {
                throw new \Exception('Failed to update user record in database');
            }

            $_SESSION['success'] = 'Avatar removed successfully!';
            
            $this->logger->info("Avatar deletion completed successfully", [
                'user_id' => $userId,
                'previous_avatar' => $user['avatar']
            ]);

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to delete avatar: ' . $e->getMessage();
            $this->logger->error("Avatar deletion failed", [
                'user_id' => $userId,
                'current_avatar' => $user['avatar'] ?? 'none',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        $this->redirect('/profile');
    }
}
