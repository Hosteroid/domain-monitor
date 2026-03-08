<?php

namespace App\Models;

use Core\Model;

class DnsRecord extends Model
{
    protected static string $table = 'dns_records';

    /**
     * Get all DNS records for a domain, grouped by type
     */
    public function getByDomainGrouped(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM dns_records WHERE domain_id = ? ORDER BY record_type ASC, host ASC, priority ASC"
        );
        $stmt->execute([$domainId]);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $type = $row['record_type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $row;
        }

        return $grouped;
    }

    /**
     * Get all DNS records for a domain (flat list)
     */
    public function getByDomain(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM dns_records WHERE domain_id = ? ORDER BY record_type ASC, host ASC"
        );
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    /**
     * Count DNS records for a domain
     */
    public function countByDomain(int $domainId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ? AND record_type != 'SOA'");
        $stmt->execute([$domainId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get distinct non-root host labels for a domain.
     * Used to preserve previously discovered subdomains across refreshes.
     */
    public function getDistinctHosts(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT host FROM dns_records WHERE domain_id = ? AND host != '@'"
        );
        $stmt->execute([$domainId]);
        return array_column($stmt->fetchAll(), 'host');
    }

    /**
     * Check if a domain has any Cloudflare-proxied records
     */
    public function hasCloudflare(int $domainId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM dns_records WHERE domain_id = ? AND is_cloudflare = 1"
        );
        $stmt->execute([$domainId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Save a snapshot of DNS records for a domain.
     * Updates existing records, inserts new ones, removes stale ones.
     * @return array{added: int, updated: int, removed: int}
     */
    public function saveSnapshot(int $domainId, array $groupedRecords): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $now = date('Y-m-d H:i:s');
        $seenIds = [];

        foreach ($groupedRecords as $type => $records) {
            foreach ($records as $record) {
                $host = $record['host'] ?? '@';
                $value = $record['value'] ?? '';
                $ttl = $record['ttl'] ?? null;
                $priority = $record['priority'] ?? null;
                $isCloudflare = !empty($record['is_cloudflare']) ? 1 : 0;
                $rawData = isset($record['raw']) ? json_encode($record['raw']) : null;

                $existing = $this->findExisting($domainId, $type, $host, $value, $priority);

                if ($existing) {
                    $this->db->prepare(
                        "UPDATE dns_records SET ttl = ?, is_cloudflare = ?, raw_data = ?, last_seen_at = ?, updated_at = ? WHERE id = ?"
                    )->execute([$ttl, $isCloudflare, $rawData, $now, $now, $existing['id']]);
                    $seenIds[] = $existing['id'];
                    $stats['updated']++;
                } else {
                    $stmt = $this->db->prepare(
                        "INSERT INTO dns_records (domain_id, record_type, host, value, ttl, priority, is_cloudflare, raw_data, first_seen_at, last_seen_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$domainId, $type, $host, $value, $ttl, $priority, $isCloudflare, $rawData, $now, $now, $now, $now]);
                    $seenIds[] = (int)$this->db->lastInsertId();
                    $stats['added']++;
                }
            }
        }

        // Remove records that no longer exist
        if (!empty($seenIds)) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $deleteStmt = $this->db->prepare(
                "DELETE FROM dns_records WHERE domain_id = ? AND id NOT IN ({$placeholders})"
            );
            $deleteStmt->execute(array_merge([$domainId], $seenIds));
            $stats['removed'] = $deleteStmt->rowCount();
        } else {
            // No records found at all — remove everything
            $deleteStmt = $this->db->prepare("DELETE FROM dns_records WHERE domain_id = ?");
            $deleteStmt->execute([$domainId]);
            $stats['removed'] = $deleteStmt->rowCount();
        }

        return $stats;
    }

    /**
     * Find an existing record by its natural key
     */
    private function findExisting(int $domainId, string $type, string $host, string $value, ?int $priority): ?array
    {
        if ($priority !== null) {
            $stmt = $this->db->prepare(
                "SELECT * FROM dns_records WHERE domain_id = ? AND record_type = ? AND host = ? AND value = ? AND priority = ? LIMIT 1"
            );
            $stmt->execute([$domainId, $type, $host, $value, $priority]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM dns_records WHERE domain_id = ? AND record_type = ? AND host = ? AND value = ? AND priority IS NULL LIMIT 1"
            );
            $stmt->execute([$domainId, $type, $host, $value]);
        }

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Delete all DNS records for a domain
     */
    public function deleteByDomain(int $domainId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM dns_records WHERE domain_id = ?");
        return $stmt->execute([$domainId]);
    }

    /**
     * Get record counts grouped by type for a domain
     */
    public function getCountsByType(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT record_type, COUNT(*) as count FROM dns_records WHERE domain_id = ? GROUP BY record_type ORDER BY record_type"
        );
        $stmt->execute([$domainId]);
        $rows = $stmt->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['record_type']] = (int)$row['count'];
        }
        return $counts;
    }

    /**
     * Get previous snapshot as grouped records (for diff comparison).
     * Reconstructs the same format that DnsService::lookup() returns.
     */
    public function getPreviousSnapshot(int $domainId): array
    {
        $records = $this->getByDomainGrouped($domainId);
        $grouped = [];

        foreach ($records as $type => $rows) {
            $grouped[$type] = [];
            foreach ($rows as $row) {
                $entry = [
                    'host'  => $row['host'],
                    'value' => $row['value'],
                    'ttl'   => $row['ttl'] ? (int)$row['ttl'] : null,
                ];
                if ($row['priority'] !== null) {
                    $entry['priority'] = (int)$row['priority'];
                }
                if ($row['is_cloudflare']) {
                    $entry['is_cloudflare'] = true;
                }
                $grouped[$type][] = $entry;
            }
        }

        return $grouped;
    }
}
