<?php

namespace Core;

/**
 * CSRF Protection
 * 
 * Provides Cross-Site Request Forgery (CSRF) protection for forms
 */
class Csrf
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    
    /**
     * Generate a new CSRF token and store in session
     *
     * @return string The generated token
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;
        
        return $token;
    }
    
    /**
     * Get the current CSRF token (generates if doesn't exist)
     *
     * @return string The CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return self::generateToken();
        }
        
        return $_SESSION[self::TOKEN_NAME];
    }
    
    /**
     * Validate CSRF token from request
     *
     * @param string|null $token Token to validate (from POST/GET)
     * @return bool True if valid, false otherwise
     */
    public static function validateToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($token)) {
            return false;
        }
        
        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return false;
        }
        
        // Use hash_equals to prevent timing attacks
        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }
    
    /**
     * Verify CSRF token from POST request and redirect with error if invalid
     *
     * @param string $redirectUrl URL to redirect to on failure
     * @return bool True if valid, redirects on failure
     */
    public static function verifyOrFail(string $redirectUrl = '/'): bool
    {
        $token = $_POST[self::TOKEN_NAME] ?? '';
        
        // A token sent as an array (csrf_token[]=x) is no token
        if (!is_string($token) || !self::validateToken($token)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['error'] = 'Security token validation failed. Please try again.';
            header("Location: $redirectUrl");
            exit;
        }
        
        return true;
    }
    
    /**
     * Generate HTML for hidden CSRF token field
     *
     * @return string HTML input field
     */
    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="' . self::TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Regenerate CSRF token (useful after login/logout)
     */
    public static function regenerateToken(): string
    {
        return self::generateToken();
    }
}

