<?php

namespace App\Models;

use Core\Model;

class SslCertificate extends Model
{
    protected static string $table = 'ssl_certificates';

    /**
     * Get all monitored SSL certificates for a domain.
     */
    public function getByDomain(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ssl_certificates WHERE domain_id = ? ORDER BY hostname ASC, port ASC"
        );
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    /**
     * Get SSL certificates of active domains with SSL monitoring enabled,
     * soonest expiry first (certificates without an expiry date last).
     */
    public function getAllMonitored(?int $userId = null): array
    {
        $sql = "SELECT sc.id, sc.domain_id, sc.hostname, sc.port, sc.status, sc.is_trusted,
                       sc.valid_to, sc.issuer_name, sc.last_checked, sc.last_error,
                       JSON_UNQUOTE(JSON_EXTRACT(sc.raw_data, '$.issuer.O')) AS issuer_organization,
                       d.domain_name
                FROM ssl_certificates sc
                JOIN domains d ON sc.domain_id = d.id
                WHERE d.is_active = 1 AND d.ssl_monitoring_enabled = 1";

        $params = [];

        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }

        $sql .= " ORDER BY sc.valid_to IS NULL, sc.valid_to ASC, sc.hostname ASC, sc.port ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get the fields needed for a live SSL status for several domains at once.
     */
    public function getStatusByDomainIds(array $domainIds): array
    {
        if (empty($domainIds)) {
            return [];
        }

        $domainIds = array_values(array_unique(array_map('intval', $domainIds)));
        $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, domain_id, hostname, port, is_trusted, valid_to
             FROM ssl_certificates WHERE domain_id IN ({$placeholders})"
        );
        $stmt->execute($domainIds);
        return $stmt->fetchAll();
    }

    /**
     * Count monitored SSL certificates for a domain.
     */
    public function countByDomain(int $domainId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ssl_certificates WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get distinct monitored hostnames for a domain.
     *
     * @return string[]
     */
    public function getDistinctHosts(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT hostname FROM ssl_certificates WHERE domain_id = ? ORDER BY hostname ASC"
        );
        $stmt->execute([$domainId]);
        return array_column($stmt->fetchAll(), 'hostname');
    }

    /**
     * Get distinct monitored SSL targets for a domain.
     *
     * @return array<int,array{hostname:string,port:int}>
     */
    public function getDistinctTargets(int $domainId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT hostname, port FROM ssl_certificates WHERE domain_id = ? ORDER BY hostname ASC, port ASC"
        );
        $stmt->execute([$domainId]);

        return array_map(
            static fn(array $row): array => [
                'hostname' => strtolower($row['hostname']),
                'port' => (int)$row['port'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Find a monitored SSL certificate by domain and host.
     */
    public function findByDomainAndHost(int $domainId, string $hostname, int $port = 443): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ssl_certificates WHERE domain_id = ? AND hostname = ? AND port = ? LIMIT 1"
        );
        $stmt->execute([$domainId, strtolower($hostname), $port]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a monitored SSL certificate by domain and id.
     */
    public function findByDomainAndId(int $domainId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ssl_certificates WHERE domain_id = ? AND id = ? LIMIT 1"
        );
        $stmt->execute([$domainId, $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Save the latest SSL snapshot for a monitored host.
     * Creates the row if it does not exist.
     */
    public function saveSnapshot(int $domainId, string $hostname, array $snapshot, int $port = 443): int
    {
        $hostname = strtolower($hostname);
        $existing = $this->findByDomainAndHost($domainId, $hostname, $port);
        $now = date('Y-m-d H:i:s');

        $data = [
            'domain_id' => $domainId,
            'hostname' => $hostname,
            'port' => $port,
            'status' => $snapshot['status'] ?? 'invalid',
            'is_trusted' => !empty($snapshot['is_trusted']) ? 1 : 0,
            'is_self_signed' => !empty($snapshot['is_self_signed']) ? 1 : 0,
            'valid_from' => $snapshot['valid_from'] ?? null,
            'valid_to' => $snapshot['valid_to'] ?? null,
            'days_remaining' => $snapshot['days_remaining'] ?? null,
            'issuer_name' => $snapshot['issuer_name'] ?? null,
            'subject_name' => $snapshot['subject_name'] ?? null,
            'serial_number' => $snapshot['serial_number'] ?? null,
            'signature_algorithm' => $snapshot['signature_algorithm'] ?? null,
            'key_bits' => $snapshot['key_bits'] ?? null,
            'key_type' => $snapshot['key_type'] ?? null,
            'certificate_version' => $snapshot['certificate_version'] ?? null,
            'san_list' => isset($snapshot['san_list']) ? json_encode($snapshot['san_list']) : null,
            'last_checked' => $snapshot['last_checked'] ?? $now,
            'last_error' => $snapshot['last_error'] ?? null,
            'raw_data' => isset($snapshot['raw_data']) ? json_encode($snapshot['raw_data']) : null,
            'updated_at' => $now,
        ];

        if ($existing) {
            $update = $data;
            unset($update['domain_id'], $update['hostname'], $update['port']);
            $this->update($existing['id'], $update);
            return (int)$existing['id'];
        }

        $data['created_at'] = $now;
        return $this->create($data);
    }

    /**
     * Delete a monitored SSL certificate by domain and id.
     */
    public function deleteByDomainAndId(int $domainId, int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM ssl_certificates WHERE domain_id = ? AND id = ?");
        return $stmt->execute([$domainId, $id]);
    }

    /**
     * Delete multiple monitored SSL certificates by domain and ids.
     * @return int Number of deleted rows.
     */
    public function deleteByDomainAndIds(int $domainId, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM ssl_certificates WHERE domain_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$domainId], $ids));
        return $stmt->rowCount();
    }
}
