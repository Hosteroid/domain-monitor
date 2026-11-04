<?php

namespace App\Models;

use Core\Model;

class NotificationLog extends Model
{
    protected static string $table = 'notification_logs';

    /**
     * Log a notification
     */
    public function log(int $domainId, string $type, string $channel, string $message, bool $success, ?string $error = null): int
    {
        return $this->create([
            'domain_id' => $domainId,
            'notification_type' => $type,
            'channel_type' => $channel,
            'message' => $message,
            'status' => $success ? 'sent' : 'failed',
            'error_message' => $error
        ]);
    }

    /**
     * Get logs for a domain
     */
    public function getByDomain(int $domainId, int $limit = 50): array
    {
        $sql = "SELECT * FROM notification_logs 
                WHERE domain_id = ? 
                ORDER BY sent_at DESC 
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domainId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get recent logs
     */
    public function getRecent(int $limit = 100): array
    {
        $sql = "SELECT nl.*, d.domain_name 
                FROM notification_logs nl
                JOIN domains d ON nl.domain_id = d.id
                ORDER BY nl.sent_at DESC 
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Check if notification was sent recently
     *
     * @param string|string[] $type One type, or several that announce the same thing
     */
    public function wasSentRecently(int $domainId, string|array $type, int $hoursAgo = 24): bool
    {
        $types = (array)$type;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = "SELECT COUNT(*) as count FROM notification_logs
                WHERE domain_id = ?
                AND notification_type IN ({$placeholders})
                AND status = 'sent'
                AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domainId, ...$types, $hoursAgo]);
        $result = $stmt->fetch();

        return $result['count'] > 0;
    }

    /**
     * Check if one of these notification types was sent since a date
     *
     * @param string[] $types
     */
    public function wasSentSince(int $domainId, array $types, string $since): bool
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM notification_logs
             WHERE domain_id = ? AND notification_type IN ({$placeholders}) AND status = 'sent' AND sent_at >= ?"
        );
        $stmt->execute([$domainId, ...$types, $since]);

        return (int)$stmt->fetchColumn() > 0;
    }
}

