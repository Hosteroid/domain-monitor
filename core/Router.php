<?php

namespace Core;

class Router
{
    protected array $routes = [];

    public function get(string $path, $callback)
    {
        $this->routes['GET'][$path] = $callback;
    }

    public function post(string $path, $callback)
    {
        $this->routes['POST'][$path] = $callback;
    }

    public function resolve()
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'];

        // Remove query string
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        // Try exact match first
        $callback = $this->routes[$method][$path] ?? null;
        $params = [];

        // If no exact match, try pattern matching for dynamic segments
        if ($callback === null) {
            foreach ($this->routes[$method] ?? [] as $route => $handler) {
                // Convert route pattern to regex
                $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '([^/]+)', $route);
                $pattern = '#^' . $pattern . '$#';
                
                if (preg_match($pattern, $path, $matches)) {
                    $callback = $handler;
                    
                    // Extract parameter names from route
                    preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $route, $paramNames);
                    
                    // Map parameter names to values
                    array_shift($matches); // Remove full match
                    foreach ($paramNames[1] as $index => $name) {
                        $params[$name] = $matches[$index] ?? null;
                    }
                    break;
                }
            }
        }

        if ($callback === null) {
            http_response_code(404);
            
            // Log 404 errors for debugging
            try {
                $logger = new \App\Services\Logger('router');
                $logger->warning('404 Not Found', [
                    'path' => $path,
                    'method' => $method,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
                    'user_id' => $_SESSION['user_id'] ?? null
                ]);
            } catch (\Exception $e) {
                // Silently fail if logging is not available
            }
            
            require_once __DIR__ . '/../app/Views/errors/404.php';
            return;
        }

        if (is_array($callback)) {
            $controller = new $callback[0]();
            $callback[0] = $controller;
        }

        // Pass params to the callback
        if (!empty($params)) {
            call_user_func($callback, $params);
        } else {
            call_user_func($callback);
        }
    }
}

