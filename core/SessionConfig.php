<?php

namespace Core;

/**
 * Session Configuration
 * 
 * Handles session handler configuration and initialization
 */
class SessionConfig
{
    /**
     * Configure and initialize session handler
     * 
     * Attempts to use database sessions if available, falls back to file sessions
     */
    public static function configure(): void
    {
        try {
            // Check if database sessions are available
            if (self::isDatabaseSessionsAvailable()) {
                $sessionLifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1440);
                $handler = new DatabaseSessionHandler($sessionLifetime);
                session_set_save_handler($handler, true);
            }
            // If not available, PHP will use default file-based sessions (no action needed)
        } catch (\Exception $e) {
            // Fall back to default file-based sessions
            error_log("Database session handler not available, using file sessions: " . $e->getMessage());
        }
    }

    /**
     * Check if database sessions are available
     * 
     * @return bool True if sessions table exists and database is accessible
     */
    private static function isDatabaseSessionsAvailable(): bool
    {
        try {
            // Check if database credentials are configured
            if (empty($_ENV['DB_HOST']) || empty($_ENV['DB_DATABASE'])) {
                return false;
            }

            // Create PDO connection
            $pdo = new \PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_DATABASE']}",
                $_ENV['DB_USERNAME'],
                $_ENV['DB_PASSWORD'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ]
            );
            
            // Check if sessions table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'sessions'");
            return $stmt->rowCount() > 0;

        } catch (\Exception $e) {
            // Database not available or sessions table doesn't exist
            return false;
        }
    }

    /**
     * Start session with validation
     */
    public static function start(): void
    {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        
        // Validate session exists in database (for database-backed sessions)
        // This ensures deleted sessions are immediately invalidated
        SessionValidator::validate();
    }
}

