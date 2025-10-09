<?php

namespace Core;

abstract class Controller
{
    protected function view(string $view, array $data = []): void
    {
        extract($data);
        $viewPath = __DIR__ . "/../app/Views/$view.php";

        if (!file_exists($viewPath)) {
            throw new \Exception("View not found: $view");
        }

        require_once $viewPath;
    }

    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function redirect(string $path): void
    {
        header("Location: $path");
        exit;
    }

    /**
     * Verify CSRF token and redirect with error if invalid
     *
     * @param string $redirectUrl URL to redirect to on failure
     * @return bool True if valid, redirects on failure
     */
    protected function verifyCsrf(string $redirectUrl = '/'): bool
    {
        return Csrf::verifyOrFail($redirectUrl);
    }

    /**
     * Get CSRF token for forms
     *
     * @return string The CSRF token
     */
    protected function getCsrfToken(): string
    {
        return Csrf::getToken();
    }
}

