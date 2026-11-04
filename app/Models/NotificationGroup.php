<?php

namespace App\Models;

use Core\Model;

class NotificationGroup extends Model
{
    protected static string $table = 'notification_groups';

    /**
     * Get User model instance
     */
    private function getUserModel(): \App\Models\User
    {
        return new \App\Models\User();
    }

    /**
     * Get all groups with channel count
     */
    public function getAllWithChannelCount(?int $userId = null): array
    {
        $sql = "SELECT ng.*, 
                COUNT(DISTINCT nc.id) as channel_count,
                COUNT(DISTINCT d.id) as domain_count
                FROM notification_groups ng
                LEFT JOIN notification_channels nc ON ng.id = nc.notification_group_id
                LEFT JOIN domains d ON ng.id = d.notification_group_id";
        
        if ($userId) {
            $sql .= " WHERE ng.user_id = ? GROUP BY ng.id ORDER BY ng.name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
        } else {
            $sql .= " GROUP BY ng.id ORDER BY ng.name ASC";
            $stmt = $this->db->query($sql);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Get group with channels and domains
     */
    public function getWithDetails(int $id, ?int $userId = null): ?array
    {
        $group = $this->find($id);

        if (!$group) {
            return null;
        }

        // Check if user has access to this group
        if ($userId && $group['user_id'] != $userId) {
            return null;
        }

        // Get channels
        $channelModel = new NotificationChannel();
        $group['channels'] = $channelModel->getByGroupId($id);

        // Get domains (filtered by user if needed)
        $domainModel = new Domain();
        if ($userId) {
            $sql = "SELECT * FROM domains WHERE notification_group_id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id, $userId]);
            $group['domains'] = $stmt->fetchAll();
        } else {
            $group['domains'] = $domainModel->where('notification_group_id', $id);
        }

        return $group;
    }

    /**
     * Delete group and handle relationships
     */
    public function deleteWithRelations(int $id): bool
    {
        // The database CASCADE will handle channels
        // But we need to set domains to NULL
        $sql = "UPDATE domains SET notification_group_id = NULL WHERE notification_group_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        return $this->delete($id);
    }


    /**
     * Assign all notification groups without user_id to a specific user
     */
    public function assignUnassignedGroupsToUser(int $userId): int
    {
        $stmt = $this->db->prepare("UPDATE notification_groups SET user_id = ? WHERE user_id IS NULL");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Find a notification group by name
     */
    public function findByName(string $name, ?int $userId = null): ?array
    {
        if ($userId) {
            $stmt = $this->db->prepare("SELECT * FROM notification_groups WHERE name = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$name, $userId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM notification_groups WHERE name = ? LIMIT 1");
            $stmt->execute([$name]);
        }
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Check whether a group sends a type of alert (expiration, status, dns, ssl).
     * Domains without a group are not restricted.
     */
    public function allowsAlert(?int $groupId, string $type): bool
    {
        if (!$groupId) {
            return true;
        }

        $group = $this->find($groupId);
        $column = 'notify_' . $type;

        // Missing group or column (migration not run yet): keep sending as before
        return !$group || !array_key_exists($column, $group) || (bool)$group[$column];
    }

    /**
     * Whether a group is a watchlist: domains someone wants to acquire rather than owns, so its
     * alerts are about the domain approaching deletion or becoming available (#95)
     */
    public function isWatchlist(?int $groupId): bool
    {
        if (!$groupId) {
            return false;
        }

        $group = $this->find($groupId);
        return ($group['purpose'] ?? 'own') === 'watch';
    }
}

