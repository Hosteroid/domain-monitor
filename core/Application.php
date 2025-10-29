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
        $this->configureErrorReporting();
        
        self::$router = new Router();
        self::$db = new Database();
        
        // Initialize error handler
        $this->errorHandler = new ErrorHandler();
    }
    
    private function configureErrorReporting()
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

