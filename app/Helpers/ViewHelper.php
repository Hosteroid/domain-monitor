<?php

namespace App\Helpers;

class ViewHelper
{
    /**
     * Generate sort URL for table headers
     */
    public static function sortUrl(string $column, string $currentSort, string $currentOrder, array $currentFilters = []): string
    {
        $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
        $params = $currentFilters;
        $params['sort'] = $column;
        $params['order'] = $newOrder;
        
        $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return $currentPath . '?' . http_build_query($params);
    }

    /**
     * Generate sort icon HTML for table headers
     */
    public static function sortIcon(string $column, string $currentSort, string $currentOrder): string
    {
        if ($currentSort !== $column) {
            return '<i class="fas fa-sort text-gray-400 ml-1 text-xs"></i>';
        }
        
        $icon = $currentOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        return '<i class="fas ' . $icon . ' text-primary ml-1 text-xs"></i>';
    }

    /**
     * Generate pagination URL
     */
    public static function paginationUrl(int $page, array $filters, int $perPage): string
    {
        $params = $filters;
        $params['page'] = $page;
        $params['per_page'] = $perPage;
        
        $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return $currentPath . '?' . http_build_query($params);
    }

    /**
     * Format status badge
     */
    public static function statusBadge(string $status): string
    {
        $statusClasses = [
            'active' => 'bg-green-100 text-green-800 border-green-200',
            'expiring_soon' => 'bg-orange-100 text-orange-800 border-orange-200',
            'expired' => 'bg-red-100 text-red-800 border-red-200',
            'available' => 'bg-blue-100 text-blue-800 border-blue-200',
            'redemption_period' => 'bg-amber-100 text-amber-800 border-amber-200',
            'pending_delete' => 'bg-rose-100 text-rose-800 border-rose-200',
            'inactive' => 'bg-gray-100 text-gray-800 border-gray-200',
        ];

        $statusLabels = [
            'active' => 'Active',
            'expiring_soon' => 'Expiring Soon',
            'expired' => 'Expired',
            'available' => 'Available',
            'redemption_period' => 'Redemption Period',
            'pending_delete' => 'Pending Delete',
            'inactive' => 'Inactive',
        ];

        $class = $statusClasses[$status] ?? $statusClasses['inactive'];
        $label = $statusLabels[$status] ?? ucfirst($status);

        return '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border ' . $class . '">' . htmlspecialchars($label) . '</span>';
    }

    /**
     * Truncate text with ellipsis
     */
    public static function truncate(string $text, int $length = 50, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return htmlspecialchars($text);
        }

        return htmlspecialchars(mb_substr($text, 0, $length)) . $suffix;
    }

    /**
     * Format bytes to human readable size
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Generate breadcrumb navigation
     */
    public static function breadcrumbs(array $items): string
    {
        $html = '<nav class="flex mb-4" aria-label="Breadcrumb"><ol class="inline-flex items-center space-x-1 md:space-x-3">';

        foreach ($items as $index => $item) {
            $isLast = $index === count($items) - 1;
            
            if ($index > 0) {
                $html .= '<li><div class="flex items-center"><i class="fas fa-chevron-right text-gray-400 text-xs"></i></div></li>';
            }

            $html .= '<li class="inline-flex items-center">';
            
            if (!$isLast && isset($item['url'])) {
                $html .= '<a href="' . htmlspecialchars($item['url']) . '" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-primary">';
                if (isset($item['icon'])) {
                    $html .= '<i class="' . htmlspecialchars($item['icon']) . ' mr-2 text-xs"></i>';
                }
                $html .= htmlspecialchars($item['label']) . '</a>';
            } else {
                $html .= '<span class="text-sm font-medium text-gray-500">';
                if (isset($item['icon'])) {
                    $html .= '<i class="' . htmlspecialchars($item['icon']) . ' mr-2 text-xs"></i>';
                }
                $html .= htmlspecialchars($item['label']) . '</span>';
            }
            
            $html .= '</li>';
        }

        $html .= '</ol></nav>';
        return $html;
    }

    /**
     * Get the PHP max upload size from ini settings.
     * Returns the lower of upload_max_filesize and post_max_size as a human-readable string.
     *
     * @return string Human-readable size (e.g. "128 MB")
     */
    public static function getMaxUploadSize(): string
    {
        $phpUploadMax = self::parseIniSize(ini_get('upload_max_filesize') ?: '2M');
        $phpPostMax = self::parseIniSize(ini_get('post_max_size') ?: '8M');
        $phpLimit = min($phpUploadMax, $phpPostMax);

        return self::formatBytes($phpLimit, 0);
    }

    /**
     * Parse a PHP ini size value (e.g. "2M", "128K", "1G") into bytes.
     */
    private static function parseIniSize(string $size): int
    {
        $value = (int) $size;
        $unit = strtolower(substr(trim($size), -1));

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Generate alert message HTML
     */
    public static function alert(string $type, string $message): string
    {
        $classes = [
            'success' => 'bg-green-50 border-green-200 text-green-800',
            'error' => 'bg-red-50 border-red-200 text-red-800',
            'warning' => 'bg-orange-50 border-orange-200 text-orange-800',
            'info' => 'bg-blue-50 border-blue-200 text-blue-800',
        ];

        $icons = [
            'success' => 'fa-check-circle',
            'error' => 'fa-exclamation-circle',
            'warning' => 'fa-exclamation-triangle',
            'info' => 'fa-info-circle',
        ];

        $class = $classes[$type] ?? $classes['info'];
        $icon = $icons[$type] ?? $icons['info'];

        return '<div class="border rounded-lg p-4 ' . $class . ' flex items-start">
            <i class="fas ' . $icon . ' mr-3 mt-0.5"></i>
            <div class="flex-1">' . htmlspecialchars($message) . '</div>
        </div>';
    }
}

