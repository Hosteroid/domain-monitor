<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Application;
use Core\Router;
use Dotenv\Dotenv;
use App\Services\ErrorHandler;

define('PATH_ROOT', __DIR__ . '/../');

// Register global error handlers FIRST (before anything else can fail)
ErrorHandler::register();

// === EARLY REQUEST VALIDATION ===
// Block malformed requests before they cause issues
// This prevents null pointer errors and logs suspicious activity
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Validate REQUEST_URI format - reject if parse_url fails
$parsedPath = parse_url($requestUri, PHP_URL_PATH);
if ($parsedPath === null || $parsedPath === false) {
    // Log the suspicious request
    $logger = new \App\Services\Logger();
    $logger->warning('Malformed REQUEST_URI blocked', [
        'uri' => $requestUri,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    // Return 400 Bad Request
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Bad Request: Invalid URI format');
}

// Additional validation: REQUEST_URI should start with /
if (!empty($requestUri) && $requestUri[0] !== '/') {
    $logger = new \App\Services\Logger();
    $logger->warning('Invalid REQUEST_URI - must start with /', [
        'uri' => $requestUri,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Bad Request: Invalid URI format');
}

// Load environment variables (using safeLoad to not throw if missing)
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
try {
    $dotenv->load();
} catch (\Throwable $e) {
    // If .env is missing, create a minimal one or use defaults
    if (!file_exists(__DIR__ . '/../.env')) {
        // Show helpful error about missing .env file
        throw new \Exception(
            ".env file not found! Please copy env.example.txt to .env and configure your settings.\n\n" .
            "Quick fix:\n" .
            "1. Copy env.example.txt to .env\n" .
            "2. Update database credentials in .env\n" .
            "3. Set APP_ENV=development or production\n\n" .
            "Original error: " . $e->getMessage()
        );
    }
    throw $e;
}

// Configure and start session (with database sessions if available)
Core\SessionConfig::configure();
Core\SessionConfig::start();

// Load CSRF helper functions
require_once __DIR__ . '/../app/Helpers/CsrfHelper.php';

// Check if system is installed (using flag file - no DB queries!)
// Note: REQUEST_URI has already been validated above, so parse_url won't return null
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$isInstallerPath = strpos($currentPath, '/install') === 0;
$installedFlagFile = __DIR__ . '/../.installed';

if (!$isInstallerPath) {
    // Check if .installed flag file exists
    if (!file_exists($installedFlagFile)) {
        header('Location: /install');
        exit;
    }
}

// Check remember me token if user is not logged in
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && !$isInstallerPath) {
    $authController = new \App\Controllers\AuthController();
    $authController->checkRememberToken();
}

// Set application timezone early (before any date operations)
if (!$isInstallerPath && file_exists($installedFlagFile)) {
    try {
        $settingModel = new \App\Models\Setting();
        $timezone = $settingModel->getValue('app_timezone', 'UTC');
        date_default_timezone_set($timezone);
    } catch (\Exception $e) {
        // Database not available, use UTC as fallback
        date_default_timezone_set('UTC');
    }
} else {
    // Default to UTC during installation
    date_default_timezone_set('UTC');
}

// Initialize application
$app = new Application();

// Load routes
require_once __DIR__ . '/../routes/web.php';

// Run application
$app->run();

