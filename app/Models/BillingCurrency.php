<?php

namespace App\Models;

use Core\Model;

/**
 * Billing Currency Model
 *
 * Currencies of the billing pages with their rate to the base currency
 */
class BillingCurrency extends Model
{
    protected static string $table = 'billing_currencies';

    /** Amount times rate with exact decimal arithmetic */
    public const CONVERT = 'ROUND(CAST(? AS DECIMAL(12,2)) * CAST(? AS DECIMAL(18,8)), 2)';

    /**
     * Every currency, keyed by code
     */
    public function allByCode(): array
    {
        $rows = $this->db->query('SELECT code, symbol, rate FROM billing_currencies ORDER BY code')->fetchAll();

        return array_column($rows, null, 'code');
    }

    /**
     * Add a currency or change its symbol and rate
     */
    public function save(string $code, ?string $symbol, string $rate): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO billing_currencies (code, symbol, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE symbol = ?, rate = ?'
        );
        $stmt->execute([$code, $symbol, $rate, $symbol, $rate]);
    }

    public function remove(string $code): bool
    {
        $stmt = $this->db->prepare('DELETE FROM billing_currencies WHERE code = ?');
        $stmt->execute([$code]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Convert many amounts in the database, so no money is multiplied in PHP
     *
     * @param array $pairs Key => [amount, rate]
     * @return array Key => amount times rate, rounded to two places
     */
    public function convertMany(array $pairs): array
    {
        $converted = [];
        foreach (array_chunk($pairs, 200, true) as $chunk) {
            $selects = [];
            $params = [];
            foreach ($chunk as $key => [$amount, $rate]) {
                $selects[] = 'SELECT ? AS k, ' . self::CONVERT . ' AS v';
                array_push($params, (string)$key, $amount, $rate);
            }
            $stmt = $this->db->prepare(implode(' UNION ALL ', $selects));
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $converted[$row['k']] = $row['v'];
            }
        }

        return $converted;
    }
}
