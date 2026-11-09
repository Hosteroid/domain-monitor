<?php

namespace App\Models;

use Core\Model;

/**
 * DNS history (#75): the records a domain had before each change a DNS check or scan found, and
 * its last records when it was deleted. An entry outlives its domain: domain_id goes NULL then,
 * domain_name and user_id stay.
 */
class DnsHistory extends Model
{
    protected static string $table = 'dns_history';

    /** Entries kept per domain: a zone whose records rotate on every check adds one each time */
    public const MAX_PER_DOMAIN = 300;

    /** What the lists read: everything but the saved records, which can be large */
    private const LIST_COLUMNS = 'h.id, h.domain_id, h.domain_name, h.user_id, h.source, h.added_count, '
        . 'h.removed_count, h.changed_count, h.record_count, h.changes, h.created_at';

    private const NO_CHANGES = ['added' => [], 'removed' => [], 'changed' => []];

    /**
     * Whether the table is there: it is missing until the update that adds it has run
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('records');
    }

    /**
     * Save an entry, then drop the domain's oldest ones over the limit.
     *
     * @param array      $domain  Domain row (id, domain_name, user_id)
     * @param array      $records The domain's records by type, as DnsRecord::getPreviousSnapshot() returns them
     * @param array|null $changes added / removed / changed records (type, host, value, priority), null when nothing changed
     * @return int|null Entry id, or null when the newest entry already holds the same records and changes
     */
    public function add(array $domain, string $source, array $records, ?array $changes): ?int
    {
        $domainId = (int)$domain['id'];

        // The cron and a Refresh that overlap both find the same change
        $newest = $this->db->prepare("SELECT records, changes FROM dns_history WHERE domain_id = ? ORDER BY id DESC LIMIT 1");
        $newest->execute([$domainId]);
        $row = $newest->fetch();
        if ($row && json_decode($row['records'], true) == $records && json_decode((string)$row['changes'], true) == $changes) {
            return null;
        }

        $id = $this->create([
            'domain_id'     => $domainId,
            'domain_name'   => $domain['domain_name'],
            'user_id'       => !empty($domain['user_id']) ? (int)$domain['user_id'] : null,
            'source'        => $source,
            'added_count'   => count($changes['added'] ?? []),
            'removed_count' => count($changes['removed'] ?? []),
            'changed_count' => count($changes['changed'] ?? []),
            // SOA is left out, as in DnsRecord::countByDomain()
            'record_count'  => array_sum(array_map('count', $records)) - count($records['SOA'] ?? []),
            'changes'       => $changes === null ? null : self::encode($changes),
            'records'       => self::encode($records),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->trimDomain($domainId);

        return $id;
    }

    /**
     * Newest entries of a domain, without the saved records
     */
    public function getForDomain(int $domainId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT " . self::LIST_COLUMNS . " FROM dns_history h WHERE h.domain_id = ? ORDER BY h.id DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$domainId]);

        return array_map([self::class, 'decodeChanges'], $stmt->fetchAll());
    }

    public function countForDomain(int $domainId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM dns_history WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Entries of every domain, deleted ones included, newest first and without the saved records.
     *
     * @param array    $filters search (part of a domain name), state ('existing' or 'deleted'), source
     * @param int|null $userId  Set in isolated mode: only this user's entries
     */
    public function search(array $filters, ?int $userId, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters, $userId);

        $stmt = $this->db->prepare(
            "SELECT " . self::LIST_COLUMNS . " FROM dns_history h LEFT JOIN domains d ON d.id = h.domain_id
             WHERE {$where} ORDER BY h.id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset)
        );
        $stmt->execute($params);

        return array_map([self::class, 'decodeChanges'], $stmt->fetchAll());
    }

    public function countSearch(array $filters, ?int $userId): int
    {
        [$where, $params] = $this->buildWhere($filters, $userId);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM dns_history h LEFT JOIN domains d ON d.id = h.domain_id WHERE {$where}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * One entry with its saved records, or null when it does not exist or is not this user's.
     *
     * @param int|null $userId Set in isolated mode
     */
    public function findVisible(int $id, ?int $userId): ?array
    {
        [$where, $params] = $this->buildWhere([], $userId);

        $stmt = $this->db->prepare(
            "SELECT h.* FROM dns_history h LEFT JOIN domains d ON d.id = h.domain_id WHERE h.id = ? AND {$where}"
        );
        $stmt->execute(array_merge([$id], $params));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['records'] = json_decode($row['records'], true) ?: [];
        return self::decodeChanges($row);
    }

    /**
     * Delete entries by id, only the ones this user may see in isolated mode.
     *
     * @return int Entries deleted
     */
    public function deleteVisible(array $ids, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return 0;
        }

        [$where, $params] = $this->buildWhere([], $userId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // MySQL can't delete from a table it also reads in a subquery, so the ids are read first
        $stmt = $this->db->prepare(
            "SELECT h.id FROM dns_history h LEFT JOIN domains d ON d.id = h.domain_id WHERE h.id IN ({$placeholders}) AND {$where}"
        );
        $stmt->execute(array_merge($ids, $params));
        $visible = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (empty($visible)) {
            return 0;
        }

        $delete = $this->db->prepare(
            "DELETE FROM dns_history WHERE id IN (" . implode(',', array_fill(0, count($visible), '?')) . ")"
        );
        $delete->execute($visible);

        return $delete->rowCount();
    }

    /**
     * Delete entries saved before a time.
     *
     * @return int Entries deleted
     */
    public function deleteOlderThan(string $before): int
    {
        $stmt = $this->db->prepare("DELETE FROM dns_history WHERE created_at < ?");
        $stmt->execute([$before]);
        return $stmt->rowCount();
    }

    /**
     * Give a domain's entries its current name and owner, right before the domain is deleted:
     * from then on they are all that says whose entries these are.
     */
    public function stampForDeletedDomain(int $domainId, string $domainName, ?int $userId): void
    {
        $this->db->prepare("UPDATE dns_history SET domain_name = ?, user_id = ? WHERE domain_id = ?")
            ->execute([$domainName, $userId, $domainId]);
    }

    /**
     * WHERE clause for the lists. In isolated mode an entry is the user's when its domain is, or,
     * once the domain is deleted, when the domain was.
     *
     * @return array{0: string, 1: array} SQL and its parameters
     */
    private function buildWhere(array $filters, ?int $userId): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($userId !== null) {
            $where[] = '((h.domain_id IS NOT NULL AND d.user_id = ?) OR (h.domain_id IS NULL AND h.user_id = ?))';
            $params[] = $userId;
            $params[] = $userId;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'h.domain_name LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        if (($filters['state'] ?? '') === 'existing') {
            $where[] = 'h.domain_id IS NOT NULL';
        } elseif (($filters['state'] ?? '') === 'deleted') {
            $where[] = 'h.domain_id IS NULL';
        }

        if (!empty($filters['source'])) {
            $where[] = 'h.source = ?';
            $params[] = $filters['source'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Drop a domain's oldest entries over MAX_PER_DOMAIN
     */
    private function trimDomain(int $domainId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM dns_history WHERE domain_id = ? ORDER BY id DESC LIMIT 1 OFFSET " . self::MAX_PER_DOMAIN
        );
        $stmt->execute([$domainId]);
        $firstOver = $stmt->fetchColumn();

        if ($firstOver !== false) {
            $this->db->prepare("DELETE FROM dns_history WHERE domain_id = ? AND id <= ?")->execute([$domainId, $firstOver]);
        }
    }

    private static function decodeChanges(array $row): array
    {
        $row['changes'] = array_merge(self::NO_CHANGES, json_decode((string)($row['changes'] ?? ''), true) ?: []);
        return $row;
    }

    /**
     * JSON for a column. DNS answers are bytes, so text that isn't valid UTF-8 is substituted
     * rather than failing the whole entry.
     */
    private static function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
