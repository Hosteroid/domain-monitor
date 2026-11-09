<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Helpers\LayoutHelper;
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
            $notification['icon'] = LayoutHelper::getNotificationIcon($notification['type']);
            $notification['color'] = LayoutHelper::getNotificationColor($notification['type'], $notification['title'] ?? '');
            $notification['login_data'] = LayoutHelper::parseLoginData($notification);
        }
        
        $this->view('notifications/index', [
            'title' => 'Notifications',
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
     * Supports optional redirect to domain if ?redirect=domain
     */
    public function markAsRead($params = [])
    {
        // A script asks with ?ajax=1 or for JSON, and gets JSON back also when the token is refused
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || !empty($_GET['ajax']);
        if ($isAjax) {
            $token = $_POST['csrf_token'] ?? null;
            if (!is_string($token) || !\Core\Csrf::validateToken($token)) {
                $this->json(['success' => false, 'error' => 'Security token validation failed. Please reload the page and try again.'], 403);
                return;
            }
        } else {
            $this->verifyCsrf('/notifications');
        }

        $userId = Auth::id();
        $notificationId = (int)($params['id'] ?? 0);

        if ($notificationId <= 0) {
            $_SESSION['error'] = 'Invalid notification';
            $this->redirect('/notifications');
            return;
        }

        $this->notificationModel->markAsRead($notificationId, $userId);

        // Optional redirect after marking read
        $redirect = $_GET['redirect'] ?? '';
        if ($redirect === 'domain') {
            $domainId = (int)($_GET['domain_id'] ?? 0);
            if ($domainId > 0) {
                $this->redirect('/domains/' . $domainId);
                return;
            }
        }
        if ($redirect === 'settings') {
            $this->redirect('/settings#updates');
            return;
        }

        if ($isAjax) {
            $unreadCount = $this->notificationModel->getUnreadCount($userId);
            $this->json(['success' => true, 'id' => $notificationId, 'unread_count' => $unreadCount]);
            return;
        }

        $_SESSION['success'] = 'Notification marked as read';
        $this->redirect('/notifications');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $this->verifyCsrf('/notifications');

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
        // Ensure POST method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/notifications');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/notifications');

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
        // Ensure POST method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/notifications');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/notifications');

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
            $notification['icon'] = LayoutHelper::getNotificationIcon($notification['type']);
            $notification['color'] = LayoutHelper::getNotificationColor($notification['type'], $notification['title'] ?? '');
        }
        
        $this->json([
            'notifications' => $notifications,
            'unread_count' => count($notifications)
        ]);
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

