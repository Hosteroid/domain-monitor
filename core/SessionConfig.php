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
                $handler = new DatabaseSessionHandler(self::lifetime());
                session_set_save_handler($handler, true);
            }
            // If not available, PHP will use default file-based sessions (no action needed)
        } catch (\Exception $e) {
            // Fall back to default file-based sessions
            error_log("Database session handler not available, using file sessions: " . $e->getMessage());
        }
    }

    /**
     * Minutes a session may stay idle before it expires: SESSION_LIFETIME from .env, or 24 hours
     * when that is missing or not a number above 0.
     *
     * The one place that reads it. The session handler and the clean-up of old rows
     * (SessionManager::cleanOldSessions()) both go by it, so a row is deleted when the handler
     * stops accepting it, not earlier and not later.
     */
    public static function lifetime(): int
    {
        $minutes = (int)($_ENV['SESSION_LIFETIME'] ?? 0);

        return $minutes > 0 ? $minutes : 1440;
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

            // Check if sessions table exists (shared app connection, so DB_PORT etc. are honored)
            $stmt = Database::getConnection()->query("SHOW TABLES LIKE 'sessions'");
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

    /**
     * Move the running session to the database, keeping its data
     *
     * For the request that creates the sessions table: it began with PHP's own session storage,
     * while the next request reads the session from the database and would find nothing there.
     * The copy in the old storage (a session file) is deleted.
     */
    public static function moveToDatabase(): void
    {
        // Nothing to move without a session, or when it is in the database already
        if (session_status() !== PHP_SESSION_ACTIVE || session_module_name() === 'user') {
            return;
        }

        $data = $_SESSION;

        // Suppressed: a session file that can't be deleted must not end the request with an error page
        @session_destroy();

        self::configure();
        self::start();

        $_SESSION = $data;
    }
}

