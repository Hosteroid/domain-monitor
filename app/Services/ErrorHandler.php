<?php

namespace App\Services;

use App\Models\ErrorLog;

/**
 * ErrorHandler Service
 * 
 * Centralized error handling system:
 * - Captures all errors and exceptions
 * - Logs to files and database
 * - Generates unique error IDs
 * - Displays appropriate error pages
 * - Sanitizes sensitive data
 */
class ErrorHandler
{
    private Logger $logger;
    private ?ErrorLog $errorLogModel = null;
    private bool $isDevelopment;
    private bool $handling = false; // Recursion guard

    public function __construct()
    {
        $this->logger = new Logger('errors');
        // Default to development if APP_ENV not set (show debug info for config errors)
        $this->isDevelopment = ($_ENV['APP_ENV'] ?? 'development') === 'development';
        
        // Initialize ErrorLog model if database is available
        try {
            $this->errorLogModel = new ErrorLog();
        } catch (\Throwable $e) {
            // Database not available, will only use file logging
        }
    }

    /**
     * Handle an exception
     */
    public function handleException(\Throwable $exception): void
    {
        // Prevent infinite recursion if error handling itself triggers an error
        if ($this->handling) {
            // Fallback: just log to file and stop
            try {
                $this->logger->critical('Recursive error detected', [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine()
                ]);
            } catch (\Throwable $e) {
                // Last resort
            }
            return;
        }
        $this->handling = true;

        $errorData = $this->captureError($exception);
        
        // Log to file
        $this->logToFile($errorData);
        
        // Log to database if available
        $dbErrorId = $this->logToDatabase($errorData);
        
        // Display error page
        $this->displayError($errorData, $dbErrorId);
    }

    /**
     * Handle PHP errors (convert to exception)
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Don't handle suppressed errors (@)
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // Prevent recursive handling (e.g. if logToDatabase triggers a warning)
        if ($this->handling) {
            return false;
        }

        // Convert to ErrorException and handle it
        $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->handleException($exception);
        
        return true;
    }

    /**
     * Handle fatal errors on shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );
            
            $this->handleException($exception);
        }
    }

    /**
     * Capture complete error context
     */
    private function captureError(\Throwable $exception): array
    {
        // Generate unique error ID
        $errorId = $this->generateErrorId();
        
        // Sanitize request data (remove passwords, tokens, etc.)
        $requestData = $this->sanitizeArray(array_merge($_GET, $_POST));
        
        // Sanitize session data
        $sessionData = $this->sanitizeArray($_SESSION ?? []);
        
        return [
            'error_id' => $errorId,
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'stack_trace' => json_encode($exception->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'request_data' => json_encode($requestData),
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address' => $this->getIpAddress(),
            'session_data' => json_encode($sessionData),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'occurred_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate unique error ID for reference
     */
    private function generateErrorId(): string
    {
        return strtoupper(substr(md5(uniqid('error_', true)), 0, 12));
    }

    /**
     * Get client IP address
     */
    private function getIpAddress(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Proxy
            'HTTP_X_REAL_IP',         // Nginx
            'REMOTE_ADDR'             // Direct
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle multiple IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return 'Unknown';
    }

    /**
     * Sanitize array to remove sensitive data
     */
    private function sanitizeArray(array $data): array
    {
        $sensitive = ['password', 'password_confirm', 'current_password', 'new_password', 
                     'token', 'csrf_token', 'api_key', 'secret', 'bot_token', 
                     'mail_password', 'webhook_url', 'captcha_secret_key'];
        
        $sanitized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;
            
            foreach ($sensitive as $pattern) {
                if (strpos($lowerKey, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Log error to file
     */
    private function logToFile(array $errorData): void
    {
        $this->logger->separator('ERROR CAPTURED');
        $this->logger->critical('Error occurred', [
            'error_id' => $errorData['error_id'],
            'type' => $errorData['error_type'],
            'message' => $errorData['error_message'],
            'file' => $errorData['error_file'],
            'line' => $errorData['error_line'],
            'uri' => $errorData['request_uri'],
            'user_id' => $errorData['user_id'],
            'ip' => $errorData['ip_address']
        ]);
        
        // Log stack trace separately for readability
        $this->logger->error('Stack Trace', ['trace' => $errorData['stack_trace']]);
        $this->logger->separator('END ERROR');
    }

    /**
     * Log error to database
     */
    private function logToDatabase(array $errorData): ?int
    {
        if ($this->errorLogModel === null) {
            return null;
        }
        
        try {
            return $this->errorLogModel->logError($errorData);
        } catch (\Throwable $e) {
            // Database logging failed — log to file so it's visible in the app's /logs folder
            try {
                $this->logger->error('Failed to log error to database', [
                    'db_error' => $e->getMessage(),
                    'db_error_file' => $e->getFile(),
                    'db_error_line' => $e->getLine(),
                    'original_error_id' => $errorData['error_id'] ?? 'unknown'
                ]);
            } catch (\Throwable $e2) {
                // Last resort — use PHP's native error_log
                error_log("ErrorHandler: DB log failed: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Display appropriate error page
     */
    private function displayError(array $errorData, ?int $dbErrorId): void
    {
        // Set HTTP status code
        http_response_code(500);
        
        // Clean any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Extract variables for view (avoid using extract() which might fail)
        $error_id = $errorData['error_id'];
        $error_type = $errorData['error_type'];
        $error_message = $errorData['error_message'];
        $error_file = $errorData['error_file'];
        $error_line = $errorData['error_line'];
        
        // Convert JSON stack trace back to string format for display
        $traceArray = json_decode($errorData['stack_trace'], true) ?? [];
        $stack_trace = $this->formatStackTraceAsString($traceArray);
        
        $request_method = $errorData['request_method'];
        $request_uri = $errorData['request_uri'];
        $user_agent = $errorData['user_agent'];
        $ip_address = $errorData['ip_address'];
        $php_version = $errorData['php_version'];
        $memory_usage = $errorData['memory_usage'];
        $occurred_at = $errorData['occurred_at'];
        $user_info = $this->getUserInfo($errorData['user_id']);
        $request_data = json_decode($errorData['request_data'], true);
        $session_data = json_decode($errorData['session_data'], true);
        
        // Display debug page in development, clean 500 in production
        if ($this->isDevelopment) {
            require __DIR__ . '/../Views/errors/debug.php';
        } else {
            require __DIR__ . '/../Views/errors/500.php';
        }
        
        exit;
    }

    /**
     * Get user info for error context
     */
    private function getUserInfo(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }
        
        return [
            'id' => $userId,
            'username' => $_SESSION['username'] ?? 'Unknown',
            'role' => $_SESSION['role'] ?? 'guest',
            'email' => $_SESSION['email'] ?? 'Unknown'
        ];
    }

    /**
     * Format stack trace array as string (similar to getTraceAsString())
     */
    private function formatStackTraceAsString(array $trace): string
    {
        if (empty($trace)) {
            return 'No stack trace available';
        }

        $result = [];
        foreach ($trace as $index => $frame) {
            $line = "#{$index} ";
            
            if (isset($frame['file'])) {
                $line .= $frame['file'];
                if (isset($frame['line'])) {
                    $line .= "({$frame['line']})";
                }
                $line .= ': ';
            }
            
            if (isset($frame['class'])) {
                $line .= $frame['class'];
                $line .= $frame['type'] ?? '->';
            }
            
            if (isset($frame['function'])) {
                $line .= $frame['function'] . '()';
            }
            
            $result[] = $line;
        }
        
        return implode("\n", $result);
    }

    /**
     * Static helper to register global handlers
     */
    public static function register(): self
    {
        $handler = new self();
        
        // Set exception handler
        set_exception_handler([$handler, 'handleException']);
        
        // Set error handler (converts errors to exceptions)
        set_error_handler([$handler, 'handleError']);
        
        // Set shutdown handler (catch fatal errors)
        register_shutdown_function([$handler, 'handleShutdown']);
        
        return $handler;
    }
}

