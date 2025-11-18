<?php

namespace Core;

class Auth
{
    /**
     * Check if user is authenticated
     */
    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && !isset($_SESSION['2fa_required']);
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current username
     */
    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Get current user's full name
     */
    public static function fullName(): ?string
    {
        return $_SESSION['full_name'] ?? null;
    }

    /**
     * Require authentication (redirect to login if not authenticated)
     */
    public static function require(): void
    {
        // Get current path - handle malformed URIs gracefully
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $currentPath = parse_url($requestUri, PHP_URL_PATH);
        
        // If parse_url fails (malformed URI), treat as root path
        // This prevents null from being passed to strpos()
        if ($currentPath === null || $currentPath === false) {
            $currentPath = '/';
        }
        
        // Public paths that don't require authentication
        $publicPaths = [
            '/login',
            '/logout',
            '/register',
            '/forgot-password',
            '/reset-password',
            '/verify-email',
            '/resend-verification',
            '/install',
            '/2fa/verify',
            '/2fa/send-email-code'
        ];
        
        // Don't redirect if on a public path
        foreach ($publicPaths as $path) {
            if (strpos($currentPath, $path) === 0) {
                return;
            }
        }
        
        if (!self::check()) {
            if (isset($_SESSION['user_id']) && self::requiresTwoFactor()) {
                $_SESSION['error'] = 'Please complete two-factor authentication';
                header('Location: /2fa/verify');
            } else {
                $_SESSION['error'] = 'Please login to continue';
                header('Location: /login');
            }
            exit;
        }
    }

    /**
     * Require admin role (redirect with error if not admin)
     */
    public static function requireAdmin(): void
    {
        // First ensure user is authenticated
        self::require();
        
        // Then check for admin role
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            $_SESSION['error'] = 'Access denied. Admin privileges required.';
            header('Location: /');
            exit;
        }
    }

    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * Get current user's role
     */
    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /**
     * Check if 2FA verification is required
     */
    public static function requiresTwoFactor(): bool
    {
        return isset($_SESSION['2fa_required']) && $_SESSION['2fa_required'];
    }
}

