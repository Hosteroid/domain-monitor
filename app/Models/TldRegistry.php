<?php

namespace App\Models;

use Core\Model;

class TldRegistry extends Model
{
    protected static string $table = 'tld_registry';

    /**
     * Get TLD by domain extension
     */
    public function getByTld(string $tld): ?array
    {
        // Ensure TLD starts with dot
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        $stmt = $this->db->prepare("SELECT * FROM tld_registry WHERE tld = ? AND is_active = 1");
        $stmt->execute([$tld]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all active TLDs
     */
    public function getAllActive(): array
    {
        $stmt = $this->db->query("SELECT * FROM tld_registry WHERE is_active = 1 ORDER BY tld ASC");
        return $stmt->fetchAll();
    }

    /**
     * Get TLDs that need updating (older than specified days)
     */
    public function getTldsNeedingUpdate(int $daysOld = 30): array
    {
        $sql = "SELECT * FROM tld_registry 
                WHERE is_active = 1 
                AND (updated_at < DATE_SUB(NOW(), INTERVAL ? DAY) 
                     OR updated_at IS NULL)
                ORDER BY updated_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$daysOld]);
        return $stmt->fetchAll();
    }

    /**
     * Create or update TLD registry entry
     */
    public function createOrUpdate(array $data): int
    {
        $tld = $data['tld'];
        
        // Check if TLD already exists
        $existing = $this->getByTld($tld);
        
        if ($existing) {
            // Update existing record
            $this->update($existing['id'], $data);
            return $existing['id'];
        } else {
            // Create new record
            return $this->create($data);
        }
    }

    /**
     * Get TLD statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total' => 0,
            'active' => 0,
            'with_rdap' => 0,
            'with_whois' => 0,
            'recently_updated' => 0,
            'needs_update' => 0
        ];

        // Total TLDs
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry");
        $stats['total'] = $stmt->fetch()['count'];

        // Active TLDs
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry WHERE is_active = 1");
        $stats['active'] = $stmt->fetch()['count'];

        // TLDs with RDAP servers
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry WHERE rdap_servers IS NOT NULL AND rdap_servers != '[]' AND is_active = 1");
        $stats['with_rdap'] = $stmt->fetch()['count'];

        // TLDs with WHOIS servers
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry WHERE whois_server IS NOT NULL AND whois_server != '' AND is_active = 1");
        $stats['with_whois'] = $stmt->fetch()['count'];

        // Recently updated (last 7 days)
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry WHERE updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_active = 1");
        $stats['recently_updated'] = $stmt->fetch()['count'];

        // Needs update (older than 30 days)
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tld_registry WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active = 1");
        $stats['needs_update'] = $stmt->fetch()['count'];

        return $stats;
    }

    /**
     * Get TLDs by search term
     */
    public function search(string $search): array
    {
        $search = '%' . $search . '%';
        $sql = "SELECT * FROM tld_registry 
                WHERE (LOWER(tld) LIKE LOWER(?) OR LOWER(whois_server) LIKE LOWER(?) OR LOWER(registry_url) LIKE LOWER(?)) 
                ORDER BY tld ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$search, $search, $search]);
        return $stmt->fetchAll();
    }

    /**
     * Get TLDs with pagination and sorting
     */
    public function getPaginated(int $page = 1, int $perPage = 50, string $search = '', string $sort = 'tld', string $order = 'asc', string $status = '', string $dataType = ''): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Validate sort column
        $allowedSorts = ['tld', 'rdap_servers', 'whois_server', 'updated_at', 'is_active'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'tld';
        }
        
        // Validate order
        $order = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        // Search filter
        if (!empty($search)) {
            $searchParam = '%' . $search . '%';
            $whereConditions[] = "(LOWER(tld) LIKE LOWER(?) OR LOWER(whois_server) LIKE LOWER(?) OR LOWER(registry_url) LIKE LOWER(?))";
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
        }
        
        // Status filter
        if ($status === 'active') {
            $whereConditions[] = "is_active = 1";
        } elseif ($status === 'inactive') {
            $whereConditions[] = "is_active = 0";
        }
        
        // Data type filter
        if ($dataType === 'with_rdap') {
            $whereConditions[] = "(rdap_servers IS NOT NULL AND rdap_servers != '' AND rdap_servers != '[]')";
        } elseif ($dataType === 'with_whois') {
            $whereConditions[] = "(whois_server IS NOT NULL AND whois_server != '')";
        } elseif ($dataType === 'with_registry') {
            $whereConditions[] = "(registry_url IS NOT NULL AND registry_url != '')";
        } elseif ($dataType === 'missing_data') {
            $whereConditions[] = "((rdap_servers IS NULL OR rdap_servers = '' OR rdap_servers = '[]') AND (whois_server IS NULL OR whois_server = '') AND (registry_url IS NULL OR registry_url = ''))";
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Build ORDER BY clause
        $orderBy = "ORDER BY $sort $order";
        if ($sort === 'tld') {
            $orderBy .= ", tld ASC"; // Secondary sort for consistent results
        }
        
        // Build main query
        $sql = "SELECT * FROM tld_registry $whereClause $orderBy LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $tlds = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as count FROM tld_registry $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['count'];

        return [
            'tlds' => $tlds,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'showing_from' => $total > 0 ? $offset + 1 : 0,
                'showing_to' => min($offset + $perPage, $total)
            ]
        ];
    }

    /**
     * Toggle TLD active status
     */
    public function toggleActive(int $id): bool
    {
        $sql = "UPDATE tld_registry SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Get TLDs that have RDAP servers
     */
    public function getTldsWithRdap(): array
    {
        $sql = "SELECT * FROM tld_registry 
                WHERE rdap_servers IS NOT NULL 
                AND rdap_servers != '[]' 
                AND is_active = 1 
                ORDER BY tld ASC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get TLDs that have WHOIS servers
     */
    public function getTldsWithWhois(): array
    {
        $sql = "SELECT * FROM tld_registry 
                WHERE whois_server IS NOT NULL 
                AND whois_server != '' 
                AND is_active = 1 
                ORDER BY tld ASC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Find TLD by extension (regardless of active status)
     */
    public function findByTld(string $tld): ?array
    {
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        $stmt = $this->db->prepare("SELECT * FROM tld_registry WHERE tld = ?");
        $stmt->execute([$tld]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all TLDs (regardless of active status) for export
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM tld_registry ORDER BY tld ASC");
        return $stmt->fetchAll();
    }

    /**
     * Execute a custom SQL query
     */
    public function query(string $sql): array
    {
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
}
