<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\Notification;

class NotificationController extends Controller
{
    private Notification $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new Notification();
    }

    /**
     * Show all notifications page
     */
    public function index()
    {
        $userId = Auth::id();
        
        // Get filter parameters
        $statusFilter = $_GET['status'] ?? '';
        $typeFilter = $_GET['type'] ?? '';
        $dateRange = $_GET['date_range'] ?? '';
        $perPage = (int)($_GET['per_page'] ?? 10);
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        // Build filters array
        $filters = [
            'status' => $statusFilter,
            'type' => $typeFilter,
            'date_range' => $dateRange
        ];
        
        // Count total records
        $totalRecords = $this->notificationModel->countForUser($userId, $filters);
        
        // Calculate pagination
        $totalPages = ceil($totalRecords / $perPage);
        $page = min($page, max(1, $totalPages));
        $offset = ($page - 1) * $perPage;
        $showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
        $showingTo = min($offset + $perPage, $totalRecords);
        
        // Get notifications
        $notifications = $this->notificationModel->getForUser($userId, $filters, $perPage, $offset);
        
        // Get unread count
        $unreadCount = $this->notificationModel->getUnreadCount($userId);
        
        // Format notifications for display
        foreach ($notifications as &$notification) {
            $notification['time_ago'] = $this->timeAgo($notification['created_at']);
            $notification['icon'] = $this->getNotificationIcon($notification['type']);
            $notification['color'] = $this->getNotificationColor($notification['type']);
        }
        
        $this->view('notifications/index', [
            'title' => 'Notifications',
            'pageTitle' => 'Notifications',
            'pageDescription' => 'View and manage your notifications',
            'pageIcon' => 'fas fa-bell',
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'filters' => $filters,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'showing_from' => $showingFrom,
                'showing_to' => $showingTo
            ]
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($params = [])
    {
        $userId = Auth::id();
        $notificationId = (int)($params['id'] ?? 0);
        
        if ($notificationId <= 0) {
            $_SESSION['error'] = 'Invalid notification';
            $this->redirect('/notifications');
            return;
        }
        
        $this->notificationModel->markAsRead($notificationId, $userId);
        $_SESSION['success'] = 'Notification marked as read';
        $this->redirect('/notifications');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $userId = Auth::id();
        $this->notificationModel->markAllAsRead($userId);
        $_SESSION['success'] = 'All notifications marked as read';
        $this->redirect('/notifications');
    }

    /**
     * Delete notification
     */
    public function delete($params = [])
    {
        $userId = Auth::id();
        $notificationId = (int)($params['id'] ?? 0);
        
        if ($notificationId <= 0) {
            $_SESSION['error'] = 'Invalid notification';
            $this->redirect('/notifications');
            return;
        }
        
        $this->notificationModel->deleteNotification($notificationId, $userId);
        $_SESSION['success'] = 'Notification deleted';
        $this->redirect('/notifications');
    }

    /**
     * Clear all notifications
     */
    public function clearAll()
    {
        $userId = Auth::id();
        $this->notificationModel->clearAll($userId);
        $_SESSION['success'] = 'All notifications cleared';
        $this->redirect('/notifications');
    }

    /**
     * Get unread count (for bell icon badge - AJAX)
     */
    public function getUnreadCount()
    {
        $userId = Auth::id();
        $count = $this->notificationModel->getUnreadCount($userId);
        $this->json(['count' => $count]);
    }
    
    /**
     * Get recent notifications for dropdown (AJAX)
     */
    public function getRecent()
    {
        $userId = Auth::id();
        $notifications = $this->notificationModel->getRecentUnread($userId, 5);
        
        // Format for display
        foreach ($notifications as &$notification) {
            $notification['time_ago'] = $this->timeAgo($notification['created_at']);
            $notification['icon'] = $this->getNotificationIcon($notification['type']);
            $notification['color'] = $this->getNotificationColor($notification['type']);
        }
        
        $this->json([
            'notifications' => $notifications,
            'unread_count' => count($notifications)
        ]);
    }
    
    /**
     * Get notification icon based on type
     */
    private function getNotificationIcon(string $type): string
    {
        return match($type) {
            'domain_expiring' => 'exclamation-triangle',
            'domain_expired' => 'times-circle',
            'domain_updated' => 'sync-alt',
            'session_new' => 'sign-in-alt',
            'whois_failed' => 'exclamation-circle',
            'system_welcome' => 'hand-sparkles',
            'system_upgrade' => 'arrow-up',
            default => 'bell'
        };
    }
    
    /**
     * Get notification color based on type
     */
    private function getNotificationColor(string $type): string
    {
        return match($type) {
            'domain_expiring' => 'orange',
            'domain_expired' => 'red',
            'domain_updated' => 'green',
            'session_new' => 'blue',
            'whois_failed' => 'gray',
            'system_welcome' => 'purple',
            'system_upgrade' => 'indigo',
            default => 'gray'
        };
    }
    
    /**
     * Convert timestamp to "time ago" format
     */
    private function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', $timestamp);
        }
    }
}

