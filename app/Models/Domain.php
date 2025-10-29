<?php

namespace App\Models;

use Core\Model;

class Domain extends Model
{
    protected static string $table = 'domains';

    /**
     * Get User model instance
     */
    private function getUserModel(): \App\Models\User
    {
        return new \App\Models\User();
    }

    /**
     * Get all domains with their notification group
     */
    public function getAllWithGroups(?int $userId = null): array
    {
        $sql = "SELECT d.*, ng.name as group_name,
                       GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') as tags,
                       GROUP_CONCAT(t.color ORDER BY t.name SEPARATOR '|') as tag_colors
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id
                LEFT JOIN domain_tags dt ON d.id = dt.domain_id
                LEFT JOIN tags t ON dt.tag_id = t.id";
        
        if ($userId) {
            // In isolated mode: only show tags that belong to this user or are global
            $sql .= " WHERE d.user_id = ? AND (t.user_id = ? OR t.user_id IS NULL) GROUP BY d.id ORDER BY d.status DESC, d.expiration_date ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $userId]);
        } else {
            // In shared mode: show all tags
            $sql .= " GROUP BY d.id ORDER BY d.status DESC, d.expiration_date ASC";
            $stmt = $this->db->query($sql);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Get domains expiring within days
     */
    public function getExpiringDomains(int $days, ?int $userId = null): array
    {
        $sql = "SELECT d.*, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE d.is_active = 1 
                AND d.expiration_date IS NOT NULL 
                AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ?+1 DAY)
                AND d.expiration_date > CURDATE()";
        
        $params = [$days];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.expiration_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get domains by status
     */
    public function getByStatus(string $status, ?int $userId = null): array
    {
        $sql = "SELECT d.*, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE d.status = ?";
        
        $params = [$status];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.expiration_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get domain with notification channels
     */
    public function getWithChannels(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT d.*, ng.name as group_name, ng.id as group_id
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE d.id = ?";
        
        $params = [$id];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $domain = $stmt->fetch();

        if (!$domain) {
            return null;
        }

        // Get notification channels for this domain's group
        if ($domain['group_id']) {
            $channelModel = new NotificationChannel();
            $domain['channels'] = $channelModel->getByGroupId($domain['group_id']);
        } else {
            $domain['channels'] = [];
        }

        return $domain;
    }

    /**
     * Find domain by ID with user isolation support
     */
    public function findWithIsolation(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT * FROM domains WHERE id = ?";
        $params = [$id];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Check if domain exists
     */
    public function existsByDomain(string $domainName): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM domains WHERE domain_name = ?");
        $stmt->execute([$domainName]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }

    /**
     * Get recent domains
     */
    public function getRecent(int $limit = 5, ?int $userId = null): array
    {
        $sql = "SELECT d.*, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE d.is_active = 1";
        
        $params = [];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.created_at DESC, d.id DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get dashboard statistics
     */
    public function getStatistics(?int $userId = null): array
    {
        $stats = [
            'total' => 0,
            'active' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
            'inactive' => 0,
            'expiring_threshold' => 30,
        ];

        // Build WHERE clause for user filtering
        $whereClause = "WHERE is_active = 1";
        $params = [];
        
        if ($userId) {
            $whereClause .= " AND user_id = ?";
            $params[] = $userId;
        }

        // Get status counts for active domains only
        $sql = "SELECT status, COUNT(*) as count FROM domains $whereClause GROUP BY status";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        $stats['total'] = array_sum(array_column($results, 'count'));

        foreach ($results as $row) {
            $stats[strtolower($row['status'])] = $row['count'];
        }

        // Get count of inactive domains (is_active = 0)
        $inactiveWhereClause = "WHERE is_active = 0";
        $inactiveParams = [];
        
        if ($userId) {
            $inactiveWhereClause .= " AND user_id = ?";
            $inactiveParams[] = $userId;
        }
        
        $inactiveStmt = $this->db->prepare("SELECT COUNT(*) as count FROM domains $inactiveWhereClause");
        $inactiveStmt->execute($inactiveParams);
        $inactiveResult = $inactiveStmt->fetch();
        $stats['inactive'] = $inactiveResult['count'] ?? 0;

        // Add inactive count to total
        $stats['total'] += $stats['inactive'];

        // Get expiring soon count
        $settingModel = new \App\Models\Setting();
        $notificationDays = $settingModel->getNotificationDays();
        $threshold = !empty($notificationDays) ? max($notificationDays) : 30;
        $stats['expiring_threshold'] = $threshold;

        $expiringWhereClause = "WHERE is_active = 1 AND expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(NOW(), INTERVAL ?+1 DAY) AND expiration_date > NOW()";
        $expiringParams = [$threshold];
        
        if ($userId) {
            $expiringWhereClause .= " AND user_id = ?";
            $expiringParams[] = $userId;
        }
        
        $expiringStmt = $this->db->prepare("SELECT COUNT(*) as count FROM domains $expiringWhereClause");
        $expiringStmt->execute($expiringParams);
        $expiringResult = $expiringStmt->fetch();
        $stats['expiring_soon'] = $expiringResult['count'] ?? 0;

        return $stats;
    }

    /**
     * Get filtered, sorted, and paginated domains
     */
    public function getFilteredPaginated(array $filters, string $sortBy, string $sortOrder, int $page, int $perPage, int $expiringThreshold = 30, ?int $userId = null): array
    {
        // Get all domains with groups
        $domains = $this->getAllWithGroups($userId);

        // Apply search filter
        if (!empty($filters['search'])) {
            $domains = array_filter($domains, function($domain) use ($filters) {
                return stripos($domain['domain_name'], $filters['search']) !== false ||
                       stripos($domain['registrar'] ?? '', $filters['search']) !== false;
            });
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $domains = array_filter($domains, function($domain) use ($filters, $expiringThreshold) {
                if ($filters['status'] === 'expiring_soon') {
                    // Check if domain expires within configured threshold
                    if (!empty($domain['expiration_date'])) {
                        $daysLeft = floor((strtotime($domain['expiration_date']) - time()) / 86400);
                        return $daysLeft <= $expiringThreshold && $daysLeft >= 0;
                    }
                    return false;
                }
                // Handle inactive filter (based on is_active field)
                if ($filters['status'] === 'inactive') {
                    return $domain['is_active'] == 0;
                }
                // Handle available and error status filters
                if ($filters['status'] === 'available' || $filters['status'] === 'error') {
                    return $domain['status'] === $filters['status'];
                }
                return $domain['status'] === $filters['status'];
            });
        }

        // Apply group filter
        if (!empty($filters['group'])) {
            $domains = array_filter($domains, function($domain) use ($filters) {
                return $domain['notification_group_id'] == $filters['group'];
            });
        }

        // Apply tag filter
        if (!empty($filters['tag'])) {
            // Get domain IDs that have the specified tag
            $tagSql = "SELECT DISTINCT dt.domain_id 
                       FROM domain_tags dt 
                       JOIN tags t ON dt.tag_id = t.id 
                       WHERE t.name = ?";
            $tagParams = [$filters['tag']];
            
            if ($userId) {
                $tagSql .= " AND dt.domain_id IN (SELECT id FROM domains WHERE user_id = ?)";
                $tagParams[] = $userId;
            }
            
            $tagStmt = $this->db->prepare($tagSql);
            $tagStmt->execute($tagParams);
            $taggedDomainIds = array_column($tagStmt->fetchAll(), 'domain_id');
            
            $domains = array_filter($domains, function($domain) use ($taggedDomainIds) {
                return in_array($domain['id'], $taggedDomainIds);
            });
        }

        // Get total count after filtering
        $totalDomains = count($domains);

        // Apply sorting
        usort($domains, function($a, $b) use ($sortBy, $sortOrder) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            
            $comparison = strcasecmp($aVal, $bVal);
            return $sortOrder === 'desc' ? -$comparison : $comparison;
        });

        // Calculate pagination
        $totalPages = ceil($totalDomains / $perPage);
        $page = min($page, max(1, $totalPages)); // Ensure page is within valid range
        $offset = ($page - 1) * $perPage;

        // Slice array for current page
        $paginatedDomains = array_slice($domains, $offset, $perPage);

        return [
            'domains' => $paginatedDomains,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalDomains,
                'total_pages' => $totalPages,
                'showing_from' => $totalDomains > 0 ? $offset + 1 : 0,
                'showing_to' => min($offset + $perPage, $totalDomains)
            ]
        ];
    }

    /**
     * Get all unique tags from all domains
     */
    public function getAllTags(?int $userId = null): array
    {
        $sql = "SELECT DISTINCT t.name 
                FROM tags t
                JOIN domain_tags dt ON t.id = dt.tag_id
                JOIN domains d ON d.id = dt.domain_id";
        $params = [];
        
        if ($userId) {
            // In isolated mode: only show tags that belong to this user or are global
            $sql .= " WHERE d.user_id = ? AND (t.user_id = ? OR t.user_id IS NULL)";
            $params[] = $userId;
            $params[] = $userId;
        }
        // In shared mode: show all tags (no additional filtering needed)
        
        $sql .= " ORDER BY t.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        return array_column($results, 'name');
    }

    /**
     * Get tags that are assigned to specific domains
     */
    public function getTagsForDomains(array $domainIds, ?int $userId = null): array
    {
        if (empty($domainIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($domainIds) - 1) . '?';
        $sql = "SELECT DISTINCT t.id, t.name, t.color
                FROM tags t
                JOIN domain_tags dt ON t.id = dt.tag_id
                WHERE dt.domain_id IN ($placeholders)";
        
        $params = $domainIds;
        
        if ($userId) {
            // In isolated mode: only show tags that belong to this user or are global
            $sql .= " AND (t.user_id = ? OR t.user_id IS NULL)";
            $params[] = $userId;
        }
        // In shared mode: show all tags (no additional filtering needed)
        
        $sql .= " ORDER BY t.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }


    /**
     * Assign all domains without user_id to a specific user
     */
    public function assignUnassignedDomainsToUser(int $userId): int
    {
        $stmt = $this->db->prepare("UPDATE domains SET user_id = ? WHERE user_id IS NULL");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Search domains for suggestions (quick search)
     */
    public function searchSuggestions(string $query, int $limit = 5, ?int $userId = null): array
    {
        $sql = "SELECT d.id, d.domain_name, d.registrar, d.expiration_date, d.status, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE (d.domain_name LIKE ? 
                   OR d.registrar LIKE ?)";
        
        $params = ['%' . $query . '%', '%' . $query . '%'];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.domain_name ASC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Search domains with user isolation support
     */
    public function searchDomains(string $query, ?int $userId = null, int $limit = 50): array
    {
        $sql = "SELECT d.*, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE (d.domain_name LIKE ? 
                   OR d.registrar LIKE ?
                   OR ng.name LIKE ?)";
        
        $params = ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%'];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.domain_name ASC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Update multiple domains based on WHERE conditions
     */
    public function updateWhere(array $conditions, array $data): int
    {
        if (empty($conditions) || empty($data)) {
            return 0;
        }

        // Build WHERE clause
        $whereClause = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $whereClause[] = "{$field} = ?";
            $params[] = $value;
        }

        // Build SET clause
        $setClause = [];
        foreach ($data as $field => $value) {
            $setClause[] = "{$field} = ?";
            $params[] = $value;
        }

        $sql = "UPDATE domains SET " . implode(', ', $setClause) . " WHERE " . implode(' AND ', $whereClause);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }

    /**
     * Get a single domain with tags and groups
     */
    public function getWithTagsAndGroups(int $id, ?int $userId = null): ?array
    {
        $sql = "SELECT d.*, ng.name as group_name, ng.id as group_id,
                       GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') as tags,
                       GROUP_CONCAT(t.color ORDER BY t.name SEPARATOR '|') as tag_colors
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                LEFT JOIN domain_tags dt ON d.id = dt.domain_id
                LEFT JOIN tags t ON dt.tag_id = t.id
                WHERE d.id = ?";

        // First parameter corresponds to d.id
        $params = [$id];

        if ($userId) {
            // In isolated mode: only show tags that belong to this user or are global
            $sql .= " AND (t.user_id = ? OR t.user_id IS NULL)";
            $params[] = $userId;
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }

        $sql .= " GROUP BY d.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $domain = $stmt->fetch();

        if (!$domain) {
            return null;
        }

        // Get notification channels for this domain's group
        if ($domain['group_id']) {
            $channelModel = new NotificationChannel();
            $domain['channels'] = $channelModel->getByGroupId($domain['group_id']);
        } else {
            $domain['channels'] = [];
        }

        return $domain;
    }
}

