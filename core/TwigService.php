<?php

namespace Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

class TwigService
{
    private static ?self $instance = null;
    private Environment $twig;

    private function __construct()
    {
        $viewsPath = PATH_ROOT . 'app/Views';
        $loader = new FilesystemLoader($viewsPath);

        $isDev = ($_ENV['APP_ENV'] ?? 'development') === 'development';
        $cachePath = $isDev ? false : PATH_ROOT . 'cache/twig';

        $this->twig = new Environment($loader, [
            'cache' => $cachePath,
            'debug' => $isDev,
            'auto_reload' => $isDev,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        if ($isDev) {
            $this->twig->addExtension(new DebugExtension());
        }

        $this->registerFunctions();
        $this->registerFilters();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getEnvironment(): Environment
    {
        return $this->twig;
    }

    /**
     * Render a Twig template with data + automatically injected globals.
     */
    public function render(string $template, array $data = []): string
    {
        $globals = $this->getGlobals();
        $context = array_merge($globals, $data);

        return $this->twig->render($template, $context);
    }

    /**
     * Collect layout-level data that every template may need.
     * Computed on each render so values are always fresh.
     */
    private function getGlobals(): array
    {
        // Session flash messages (read & clear) — always safe
        $flash = [];
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            if (isset($_SESSION[$type])) {
                $flash[$type] = $_SESSION[$type];
                unset($_SESSION[$type]);
            }
        }

        // Database-dependent globals are wrapped in try/catch so standalone
        // pages (installer, error pages) still render when the DB is absent.
        try {
            $userId = Auth::id();

            if ($userId) {
                $notificationData = \App\Helpers\LayoutHelper::getNotifications($userId);
                $recentNotifications = $notificationData['items'];
                $unreadNotifications = $notificationData['unread_count'];
                $updateBadge = Auth::isAdmin()
                    ? \App\Helpers\LayoutHelper::getUpdateBadgeInfo()
                    : ['show' => false, 'available' => false, 'label' => ''];
            } else {
                $recentNotifications = [];
                $unreadNotifications = 0;
                $updateBadge = ['show' => false, 'available' => false, 'label' => ''];
            }

            $domainStats = \App\Helpers\LayoutHelper::getDomainStats();
            $appSettings = \App\Helpers\LayoutHelper::getAppSettings();

            $avatar = null;
            if ($userId) {
                $userModel = new \App\Models\User();
                $user = $userModel->find($userId);
                if ($user) {
                    $avatar = \App\Helpers\AvatarHelper::getAvatar($user, 36);
                }
            }

            return [
                'auth' => [
                    'check'    => Auth::check(),
                    'id'       => $userId,
                    'username' => Auth::username(),
                    'fullName' => Auth::fullName(),
                    'role'     => Auth::role(),
                    'isAdmin'  => Auth::isAdmin(),
                ],
                'session'             => $_SESSION ?? [],
                'flash'               => $flash,
                'recentNotifications' => $recentNotifications,
                'unreadNotifications' => $unreadNotifications,
                'updateBadge'         => $updateBadge,
                'domainStats'         => $domainStats,
                'appName'             => $appSettings['app_name'],
                'appTimezone'         => $appSettings['app_timezone'],
                'appVersion'          => $appSettings['app_version'],
                'avatar'              => $avatar,
                'currentUrl'          => $_SERVER['REQUEST_URI'] ?? '/',
                'appEnv'              => $_ENV['APP_ENV'] ?? 'development',
            ];
        } catch (\Throwable $e) {
            return [
                'auth'                => ['check' => false, 'id' => null, 'username' => '', 'fullName' => '', 'role' => '', 'isAdmin' => false],
                'session'             => $_SESSION ?? [],
                'flash'               => $flash,
                'recentNotifications' => [],
                'unreadNotifications' => 0,
                'updateBadge'         => ['show' => false, 'available' => false, 'label' => ''],
                'domainStats'         => [],
                'appName'             => 'Domain Monitor',
                'appTimezone'         => 'UTC',
                'appVersion'          => '',
                'avatar'              => null,
                'currentUrl'          => $_SERVER['REQUEST_URI'] ?? '/',
                'appEnv'              => $_ENV['APP_ENV'] ?? 'development',
            ];
        }
    }

    private function registerFunctions(): void
    {
        $this->twig->addFunction(new TwigFunction('csrf_field', function (): string {
            return \Core\Csrf::field();
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return \Core\Csrf::getToken();
        }));

        $this->twig->addFunction(new TwigFunction('old', function (string $key, string $default = ''): string {
            return htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES, 'UTF-8');
        }));

        $this->twig->addFunction(new TwigFunction('asset', function (string $path): string {
            return '/' . ltrim($path, '/');
        }));

        $this->twig->addFunction(new TwigFunction('is_active', function (string $path): bool {
            $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            return $current === $path;
        }));

        $this->twig->addFunction(new TwigFunction('is_active_prefix', function (string $prefix): bool {
            $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            return str_starts_with($current, $prefix);
        }));

        $this->twig->addFunction(new TwigFunction('sort_url', function (string $column, string $currentSort, string $currentOrder, array $filters = []): string {
            return \App\Helpers\ViewHelper::sortUrl($column, $currentSort, $currentOrder, $filters);
        }));

        $this->twig->addFunction(new TwigFunction('sort_icon', function (string $column, string $currentSort, string $currentOrder): string {
            return \App\Helpers\ViewHelper::sortIcon($column, $currentSort, $currentOrder);
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('pagination_url', function (int $page, array $filters, int $perPage): string {
            return \App\Helpers\ViewHelper::paginationUrl($page, $filters, $perPage);
        }));

        $this->twig->addFunction(new TwigFunction('status_badge', function (string $status): string {
            return \App\Helpers\ViewHelper::statusBadge($status);
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('alert', function (string $type, string $message): string {
            return \App\Helpers\ViewHelper::alert($type, $message);
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('breadcrumbs', function (array $items): string {
            return \App\Helpers\ViewHelper::breadcrumbs($items);
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('format_login_dropdown', function (array $loginData): string {
            return \App\Helpers\LayoutHelper::formatLoginDropdown($loginData);
        }));

        $this->twig->addFunction(new TwigFunction('max_upload_size', function (): string {
            return \App\Helpers\ViewHelper::getMaxUploadSize();
        }));

        $this->twig->addFunction(new TwigFunction('role_badge', function (string $role, string $size = 'sm'): string {
            $isAdmin = $role === 'admin';
            $color = $isAdmin ? 'amber' : 'blue';
            $icon = $isAdmin ? 'crown' : 'user';
            $label = ucfirst($role);

            if ($size === 'xs') {
                $padding = 'px-2 py-0.5';
            } else {
                $padding = 'px-2.5 py-1';
            }

            return '<span class="inline-flex items-center ' . $padding . ' rounded-full text-xs font-semibold '
                . 'bg-' . $color . '-100 dark:bg-' . $color . '-500/10 '
                . 'text-' . $color . '-700 dark:text-' . $color . '-400 '
                . 'border border-' . $color . '-200 dark:border-' . $color . '-500/20">'
                . '<i class="fas fa-' . $icon . ' mr-1"></i>'
                . htmlspecialchars($label)
                . '</span>';
        }, ['is_safe' => ['html']]));
    }

    private function registerFilters(): void
    {
        $this->twig->addFilter(new TwigFilter('truncate', function (string $text, int $length = 50, string $suffix = '...'): string {
            return \App\Helpers\ViewHelper::truncate($text, $length, $suffix);
        }));

        $this->twig->addFilter(new TwigFilter('format_bytes', function (int $bytes, int $precision = 2): string {
            return \App\Helpers\ViewHelper::formatBytes($bytes, $precision);
        }));

        $this->twig->addFilter(new TwigFilter('from_json', function ($value) {
            if ($value === null || $value === '') {
                return [];
            }
            if (is_array($value) || is_object($value)) {
                return $value;
            }
            $decoded = json_decode((string) $value, true);
            return $decoded ?? [];
        }));
    }
}
