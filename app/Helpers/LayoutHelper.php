<?php

namespace App\Helpers;

use App\Models\Notification;
use App\Models\Setting;

class LayoutHelper
{
    /**
     * Get notifications for the top nav dropdown
     */
    public static function getNotifications(int $userId): array
    {
        try {
            $notificationModel = new Notification();
            $notifications = $notificationModel->getRecentUnread($userId, 4);
            $unreadCount = $notificationModel->getUnreadCount($userId);
            
            // Format each notification
            foreach ($notifications as &$notif) {
                $notif['time_ago'] = self::timeAgo($notif['created_at']);
                $notif['icon'] = self::getNotificationIcon($notif['type']);
                $notif['color'] = self::getNotificationColor($notif['type']);
                $notif['login_data'] = self::parseLoginData($notif);
            }
            
            return [
                'items' => $notifications,
                'unread_count' => $unreadCount
            ];
        } catch (\Exception $e) {
            // If table doesn't exist yet
            return ['items' => [], 'unread_count' => 0];
        }
    }
    
    
    /**
     * Get domain statistics (centralized function for views)
     */
    public static function getDomainStats(): array
    {
        $domainModel = new \App\Models\Domain();
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        if ($isolationMode === 'isolated') {
            return $domainModel->getStatistics($userId);
        } else {
            return $domainModel->getStatistics();
        }
    }

    /**
     * Parse session_new notification message (JSON)
     * Returns structured data for rich display, or null if not parseable
     */
    public static function parseLoginData(array $notification): ?array
    {
        if ($notification['type'] !== 'session_new' && $notification['type'] !== 'session_failed') {
            return null;
        }
        
        $data = json_decode($notification['message'] ?? '', true);
        
        if (is_array($data) && isset($data['ip'])) {
            return $data;
        }
        
        return null;
    }

    /**
     * Format session_new notification for dropdown display (compact)
     */
    public static function formatLoginDropdown(array $loginData): string
    {
        $parts = [];
        if ($loginData['city'] !== 'Unknown' && $loginData['city'] !== 'Local') {
            $parts[] = $loginData['city'];
        }
        if ($loginData['country'] !== 'Unknown' && $loginData['country'] !== 'Local') {
            $parts[] = $loginData['country'];
        }
        $location = !empty($parts) ? implode(', ', $parts) : $loginData['ip'];
        
        $browser = $loginData['browser'] ?? 'Unknown';
        return "{$location} · {$browser}";
    }

    /**
     * Convert timestamp to "time ago" format
     */
    private static function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        if ($diff < 60) return 'just now';
        
        if ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        }
        
        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }
        
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
    
    /**
     * Get notification icon based on type
     */
    private static function getNotificationIcon(string $type): string
    {
        return match($type) {
            'domain_expiring' => 'exclamation-triangle',
            'domain_expired', 'domain_expired_status' => 'times-circle',
            'domain_available' => 'check-circle',
            'domain_registered' => 'globe',
            'domain_redemption' => 'hourglass-half',
            'domain_pending_delete' => 'trash-alt',
            'domain_updated' => 'sync-alt',
            'session_new' => 'sign-in-alt',
            'session_failed' => 'shield-alt',
            'whois_failed' => 'exclamation-circle',
            'system_welcome' => 'hand-sparkles',
            'system_upgrade' => 'arrow-up',
            default => 'bell'
        };
    }
    
    /**
     * Get notification color based on type
     */
    private static function getNotificationColor(string $type): string
    {
        return match($type) {
            'domain_expiring' => 'orange',
            'domain_expired', 'domain_expired_status' => 'red',
            'domain_available' => 'blue',
            'domain_registered' => 'green',
            'domain_redemption' => 'amber',
            'domain_pending_delete' => 'rose',
            'domain_updated' => 'green',
            'session_new' => 'blue',
            'session_failed' => 'red',
            'whois_failed' => 'gray',
            'system_welcome' => 'purple',
            'system_upgrade' => 'indigo',
            default => 'gray'
        };
    }
    
    /**
     * Get application settings
     */
    public static function getAppSettings(): array
    {
        try {
            $settingModel = new Setting();
            $appSettings = $settingModel->getAppSettings();
            
            return [
                'app_name' => htmlspecialchars($appSettings['app_name']),
                'app_timezone' => $appSettings['app_timezone'],
                'app_version' => $appSettings['app_version']
            ];
        } catch (\Exception $e) {
            // Fallback defaults
            $settingModel = new Setting();
            return [
                'app_name' => 'Domain Monitor',
                'app_timezone' => 'UTC',
                'app_version' => $settingModel->getAppVersion()
            ];
        }
    }
}

