<?php

namespace Core;

use App\Services\ErrorHandler;

class Application
{
    public static Router $router;
    public static Database $db;
    private ErrorHandler $errorHandler;

    public function __construct()
    {
        // Configure error reporting based on environment
        self::configureErrorReporting();
        
        self::$router = new Router();
        self::$db = new Database();
        
        // Initialize error handler
        $this->errorHandler = new ErrorHandler();
    }
    
    /**
     * Also called by the API entry, which runs without this class
     */
    public static function configureErrorReporting(): void
    {
        $env = $_ENV['APP_ENV'] ?? 'development';
        
        if ($env === 'production') {
            // In production, suppress deprecation warnings to prevent header issues
            error_reporting(E_ALL & ~E_DEPRECATED);
            ini_set('display_errors', '0');
        } else {
            // In development, show all errors including deprecations
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
    }

    /**
     * Whether the system is installed. The .installed file says so without a database query.
     * When the file is missing (it could not be written, or the files were copied again without
     * it) the database decides: a system that has users is installed.
     * Also called by the API entry.
     */
    public static function isInstalled(): bool
    {
        if (file_exists(dirname(__DIR__) . '/.installed')) {
            return true;
        }

        // No second try after a failed connection: it would only make the error page wait again
        if (Database::hasFailed()) {
            return false;
        }

        try {
            return Database::getConnection()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn() !== false;
        } catch (\Throwable $e) {
            // No connection or no users table: nothing is installed
            return false;
        }
    }

    public function run()
    {
        try {
            self::$router->resolve();
        } catch (\Throwable $e) {
            // Use centralized error handler
            $this->errorHandler->handleException($e);
        }
    }
}

