<?php

namespace App\Models;

use Core\Model;

/**
 * Billing Price Model
 *
 * The price list: registration, renewal and transfer price per TLD, optionally per registrar
 */
class BillingPrice extends Model
{
    protected static string $table = 'billing_prices';

    /**
     * Registrar name reduced to lower-case letters and digits, so "GoDaddy.com, LLC" and "GoDaddy.com LLC" match.
     * An unread registrar ("Unknown") counts as none.
     */
    public static function registrarKey(?string $registrar): string
    {
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string)$registrar)));
        if (!is_string($key) || $key === 'unknown') {
            return '';
        }

        return mb_substr($key, 0, 191);
    }

    /**
     * The price rows a user may see: all of them in shared mode, their own in isolated mode
     */
    public function allVisible(?int $userId): array
    {
        $sql = 'SELECT * FROM billing_prices' . ($userId !== null ? ' WHERE user_id = ?' : '') . ' ORDER BY tld, registrar, id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? [$userId] : []);

        return $stmt->fetchAll();
    }

    public function findVisible(int $id, ?int $userId): ?array
    {
        $sql = 'SELECT * FROM billing_prices WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? [$id, $userId] : [$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The row a user already has for this TLD and registrar
     */
    public function findSame(string $tld, string $registrarKey, ?int $userId): ?array
    {
        $sql = 'SELECT * FROM billing_prices WHERE tld = ? AND registrar_key = ?'
            . ($userId !== null ? ' AND user_id = ?' : '') . ' ORDER BY id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? [$tld, $registrarKey, $userId] : [$tld, $registrarKey]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Delete the given rows as far as the user may see them
     */
    public function deleteVisible(array $ids, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return 0;
        }
        $sql = 'DELETE FROM billing_prices WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            . ($userId !== null ? ' AND user_id = ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? array_merge($ids, [$userId]) : $ids);

        return $stmt->rowCount();
    }

    /**
     * Whether a price row or a domain's own price uses the currency
     */
    public function usesCurrency(string $code): bool
    {
        $stmt = $this->db->prepare(
            'SELECT (SELECT 1 FROM billing_prices WHERE currency = ? LIMIT 1) IS NOT NULL
                 OR (SELECT 1 FROM domains WHERE renewal_currency = ? LIMIT 1) IS NOT NULL'
        );
        $stmt->execute([$code, $code]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Give rows without an owner to a user, for the switch to isolated mode
     */
    public function assignUnownedTo(int $userId): int
    {
        $stmt = $this->db->prepare('UPDATE IGNORE billing_prices SET user_id = ? WHERE user_id IS NULL');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }
}
