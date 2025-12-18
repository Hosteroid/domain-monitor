<?php

namespace App\Models;

use Core\Model;

class Notification extends Model
{
    protected static string $table = 'user_notifications';

    /**
     * Get notifications for a user with filters
     */
    public function getForUser(int $userId, array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $query = "SELECT * FROM user_notifications WHERE user_id = ?";
        $params = [$userId];
        
        // Apply status filter
        if (isset($filters['status']) && $filters['status'] !== '') {
            if ($filters['status'] === 'unread') {
                $query .= " AND is_read = 0";
            } elseif ($filters['status'] === 'read') {
                $query .= " AND is_read = 1";
            }
        }
        
        // Apply type filter
        if (!empty($filters['type'])) {
            $query .= " AND type = ?";
            $params[] = $filters['type'];
        }
        
        // Apply date range filter (future enhancement)
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'today':
                    $query .= " AND DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }
        }
        
        // Order by newest first
        $query .= " ORDER BY created_at DESC";
        
        // Apply pagination
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Count notifications for a user with filters
     */
    public function countForUser(int $userId, array $filters = []): int
    {
        $query = "SELECT COUNT(*) as total FROM user_notifications WHERE user_id = ?";
        $params = [$userId];
        
        // Apply status filter
        if (isset($filters['status']) && $filters['status'] !== '') {
            if ($filters['status'] === 'unread') {
                $query .= " AND is_read = 0";
            } elseif ($filters['status'] === 'read') {
                $query .= " AND is_read = 1";
            }
        }
        
        // Apply type filter
        if (!empty($filters['type'])) {
            $query .= " AND type = ?";
            $params[] = $filters['type'];
        }
        
        // Apply date range filter
        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'today':
                    $query .= " AND DATE(created_at) = CURDATE()";
                    break;
                case 'week':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }
    
    /**
     * Get unread count for a user
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['count'];
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Delete notification
     */
    public function deleteNotification(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_notifications WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Clear all notifications for a user
     */
    public function clearAll(int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_notifications WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Create a new notification
     */
    public function createNotification(int $userId, string $type, string $title, string $message, ?int $domainId = null): int
    {
        // Use PHP's current time (respects timezone setting) instead of database's CURRENT_TIMESTAMP
        // This ensures timezone consistency between cron job and web interface
        return $this->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'domain_id' => $domainId,
            'created_at' => date('Y-m-d H:i:s') // Use PHP timezone, not database timezone
        ]);
    }
    
    /**
     * Get recent unread notifications for dropdown (limit 5)
     */
    public function getRecentUnread(int $userId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_notifications 
             WHERE user_id = ? AND is_read = 0 
             ORDER BY created_at DESC 
             LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

