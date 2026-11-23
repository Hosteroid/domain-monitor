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
    private bool $errorLogTried = false;
    private bool $handling = false; // Recursion guard

    /** Places in the code whose deprecation was logged during this request */
    private array $loggedDeprecations = [];

    /** API requests get their errors as JSON instead of a page */
    private static bool $jsonOutput = false;

    /** User of the API token in use, for the error log */
    private static ?int $apiUserId = null;

    /** What is saved in place of a value that must not be */
    private const REDACTED = '***REDACTED***';

    /** Characters that the error_logs columns of limited length take */
    private const COLUMN_CHARS = [
        'error_type' => 100,
        'error_file' => 500,
        'request_method' => 10,
        'request_uri' => 500,
        'ip_address' => 45,
        'php_version' => 20,
    ];

    /** Bytes that a TEXT column takes */
    private const TEXT_BYTES = 65535;

    public function __construct()
    {
        // Nothing else is read here: the global handler is created before .env is loaded, so the
        // environment and the database are looked at when an error is handled
        $this->logger = new Logger('errors');
    }

    /**
     * Answer errors as JSON from here on (API requests)
     */
    public static function useJsonOutput(): void
    {
        self::$jsonOutput = true;
    }

    /**
     * The user an API request acts as, saved with its errors
     */
    public static function setApiUser(?int $userId): void
    {
        self::$apiUserId = $userId;
    }

    /**
     * Development unless APP_ENV says otherwise, so a missing or broken .env still shows why
     */
    private function isDevelopment(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'development') === 'development';
    }

    /**
     * The error log table, when the database can be reached
     */
    private function errorLog(): ?ErrorLog
    {
        if (!$this->errorLogTried) {
            $this->errorLogTried = true;
            // No second try after a failed connection: it would only make the error page wait again
            if (!\Core\Database::hasFailed()) {
                try {
                    $this->errorLogModel = new ErrorLog();
                } catch (\Throwable $e) {
                    // Database not available, file logging only
                }
            }
        }

        return $this->errorLogModel;
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

        // A deprecation says that something will stop working in a later PHP version: the code
        // still works. It is logged and the page carries on, so a PHP upgrade or an older package
        // cannot turn a working page into an error page (#111).
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            $this->logDeprecation($errstr, $errfile, $errline);
            return true;
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
     * Write a deprecation to the error log file, once for each place in the code per request. Only
     * the path of the request is written: the query string of a reset or verification link holds
     * its token.
     */
    private function logDeprecation(string $message, string $file, int $line): void
    {
        $place = $file . ':' . $line;
        if (isset($this->loggedDeprecations[$place])) {
            return;
        }
        $this->loggedDeprecations[$place] = true;

        try {
            // Muted: a log folder that cannot be written must not stop the page either
            @$this->logger->warning('PHP deprecation', [
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'uri' => isset($_SERVER['REQUEST_URI'])
                    ? (parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: 'N/A')
                    : 'CLI',
            ]);
        } catch (\Throwable $e) {
            // The log is not worth failing the page for
        }
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
        
        // Sanitize request data (remove passwords, tokens, etc.). Nothing is kept of an API request:
        // its query string may hold a token someone put there by mistake
        $requestData = self::$jsonOutput ? [] : $this->sanitizeArray(array_merge($_GET, $_POST));
        
        // Sanitize session data
        $sessionData = $this->sanitizeArray($_SESSION ?? []);
        
        $error = [
            'error_id' => $errorId,
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'stack_trace' => self::traceJson($exception->getTrace()),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'request_uri' => self::$jsonOutput
                ? (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'N/A')
                : $this->sanitizeUri($_SERVER['REQUEST_URI'] ?? 'N/A'),
            'request_data' => self::dataJson($requestData),
            'user_id' => self::$apiUserId ?? ($_SESSION['user_id'] ?? null),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address' => $this->getIpAddress(),
            'session_data' => self::dataJson($sessionData),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'occurred_at' => date('Y-m-d H:i:s')
        ];

        // A value that does not fit its column loses the whole row: in strict SQL mode the
        // insert fails, and the error is missing from the Error Logs page. The client decides
        // several of these values (address, User-Agent, X-Forwarded-For, form fields).
        foreach (self::COLUMN_CHARS as $column => $chars) {
            $error[$column] = self::fit((string)$error[$column], $chars);
        }
        foreach (['error_message', 'user_agent'] as $column) {
            $error[$column] = self::fitBytes((string)$error[$column], self::TEXT_BYTES);
        }

        return $error;
    }

    /**
     * A text that fits a column of so many characters: valid UTF-8, and cut with "..." at its
     * end when it is longer
     */
    private static function fit(string $text, int $chars): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return mb_strlen($text, 'UTF-8') > $chars ? mb_substr($text, 0, $chars - 3, 'UTF-8') . '...' : $text;
    }

    /**
     * The same for a column that counts bytes
     */
    private static function fitBytes(string $text, int $bytes): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes - 3, 'UTF-8') . '...' : $text;
    }

    /**
     * The frames of a stack trace as JSON that fits its column. A trace too long for it comes
     * from a runaway recursion: the frames nearest to the error are kept and a last entry says
     * how many were left out. The JSON itself is never cut, it has to stay readable.
     */
    private static function traceJson(array $trace): string
    {
        $frames = self::framesWithoutArguments($trace);
        $kept = count($frames);

        do {
            $part = array_slice($frames, 0, $kept);
            if ($kept < count($frames)) {
                $part[] = ['function' => '{' . (count($frames) - $kept) . ' more frames}'];
            }
            $json = json_encode($part, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
            $kept = intdiv($kept, 2);
        } while (strlen($json) > self::TEXT_BYTES);

        return $json;
    }

    /**
     * Request or session data as JSON that fits its column and can be read back. Data too
     * large for it gets its long values cut (a pasted zone file, an import), and what is still
     * too large is left out with a note.
     */
    private static function dataJson(array $data): string
    {
        foreach ([0, 2000, 200] as $longest) {
            $json = json_encode(
                $longest === 0 ? $data : self::withShortValues($data, $longest),
                JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($json !== false && strlen($json) <= self::TEXT_BYTES) {
                return $json;
            }
        }

        return json_encode(['note' => 'Too large to save: ' . count($data) . ' fields']);
    }

    /**
     * An array with every text in it cut to so many bytes
     */
    private static function withShortValues(array $data, int $longest): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withShortValues($value, $longest);
            } elseif (is_string($value) && strlen($value) > $longest) {
                $start = mb_strcut($value, 0, $longest, 'UTF-8');
                $data[$key] = $start . '... [' . (strlen($value) - strlen($start)) . ' more bytes]';
            }
        }

        return $data;
    }

    /**
     * The frames of a stack trace without the values the functions were called with.
     *
     * PHP keeps those values in every frame unless zend.exception_ignore_args is on, and they
     * are whatever was handed along: a new password, a 2FA secret, a token. Only what is named
     * here is kept of a frame, so nothing else PHP puts into one is saved either. The error
     * pages and the Error Logs page show file, line, class and function.
     */
    private static function framesWithoutArguments(array $trace): array
    {
        $kept = ['file' => true, 'line' => true, 'class' => true, 'type' => true, 'function' => true];

        $frames = [];
        foreach ($trace as $frame) {
            if (is_array($frame)) {
                $frames[] = array_intersect_key($frame, $kept);
            }
        }

        return $frames;
    }

    /**
     * The request address as it is saved. The links of password reset and verification emails
     * carry their token in the address, so the values sanitizeArray() hides in the request data
     * are hidden here as well. An address without such a value is saved as it came.
     */
    private function sanitizeUri(string $uri): string
    {
        $query = $this->sanitizeArray($_GET);
        if ($query === $_GET) {
            return $uri;
        }

        return explode('?', $uri, 2)[0] . '?'
            . str_replace(rawurlencode(self::REDACTED), self::REDACTED, http_build_query($query));
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
                     'mail_password', 'webhook_url', 'captcha_secret_key',
                     'verification_code', 'backup_code', 'user_key'];
        
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
                $sanitized[$key] = self::REDACTED;
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
        $errorLog = $this->errorLog();
        if ($errorLog === null) {
            return null;
        }
        
        try {
            return $errorLog->logError($errorData);
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
        if (self::$jsonOutput) {
            $this->displayJsonError($errorData['error_id']);
        }

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
        
        $twigTemplate = $this->isDevelopment() ? 'errors/debug.twig' : 'errors/500.twig';

        try {
            $memory_usage_mb = round(($memory_usage ?? 0) / 1024 / 1024, 2);
            $peak_memory_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            $errorContext = compact(
                'error_id', 'error_type', 'error_message', 'error_file', 'error_line',
                'stack_trace', 'request_method', 'request_uri', 'user_agent',
                'ip_address', 'php_version', 'memory_usage', 'memory_usage_mb',
                'peak_memory_mb', 'occurred_at', 'user_info', 'request_data', 'session_data'
            );
            echo \Core\TwigService::getInstance()->render($twigTemplate, $errorContext);
        } catch (\Throwable $e) {
            $safeId = htmlspecialchars($error_id, ENT_QUOTES, 'UTF-8');
            if ($this->isDevelopment()) {
                $safeMsg = htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8');
                $safeFile = htmlspecialchars($error_file, ENT_QUOTES, 'UTF-8');
                echo "<h1>Internal Server Error</h1><p><strong>ID:</strong> {$safeId}</p>"
                   . "<p>{$safeMsg} in {$safeFile}:{$error_line}</p>";
            } else {
                echo "<h1>Internal Server Error</h1><p>Reference: {$safeId}</p>";
            }
        }
        
        exit;
    }

    /**
     * The answer an API request gets. The message stays out of it, in development too: a database
     * error names the user and the host. The reference finds the error in the error log.
     */
    private function displayJsonError(string $errorId): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode(['error' => [
            'code' => 'server_error',
            'message' => 'Internal server error',
            'reference' => $errorId,
        ]]);

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
        // No call arguments in the stack traces of this request. PHP leaves them out when an
        // exception is made, so they are also missing wherever else a trace is written, such as
        // the getTraceAsString() lines in the logs. Where the server does not allow that to be
        // changed, the second setting cuts every string argument of a text trace down to '...',
        // and captureError() drops the arguments from what it saves in any case.
        ini_set('zend.exception_ignore_args', '1');
        ini_set('zend.exception_string_param_max_len', '0');

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

