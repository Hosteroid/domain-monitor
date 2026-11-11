<?php

namespace App\Models;

use Core\Model;

/**
 * Billing Transaction Model
 *
 * One row per payment or income. A row keeps its domain's name and its owner, so it outlives
 * the domain. Amounts are positive: the type decides whether money went out or came in.
 */
class BillingTransaction extends Model
{
    protected static string $table = 'billing_transactions';

    public const EXPENSE_TYPES = ['purchase', 'renewal', 'transfer', 'other'];
    public const INCOME_TYPES = ['sale', 'income'];

    public const TYPE_LABELS = [
        'purchase' => 'Purchase',
        'renewal' => 'Renewal',
        'transfer' => 'Transfer',
        'other' => 'Other',
        'sale' => 'Sale',
        'income' => 'Income',
    ];

    public const STATUS_LABELS = ['paid' => 'Paid', 'pending' => 'Pending'];

    /** Columns a caller may set */
    private const FIELDS = [
        'domain_id', 'domain_name', 'user_id', 'type', 'amount', 'currency', 'amount_base', 'transaction_date',
        'vendor', 'invoice_number', 'invoice_url', 'payment_method', 'status', 'source', 'needs_review',
        'term_years', 'expires_on', 'notes',
    ];

    /** Only paid rows count in totals */
    private const COUNTED = "t.status = 'paid'";

    /**
     * Whether the billing tables exist (they do not between a file update and its migration)
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('amount_base');
    }

    /**
     * Save a payment
     *
     * @param array $data Column values. Leave amount_base out and pass a rate to have it converted
     * @param string|null $rate Value of one unit of the payment's currency in the base currency
     */
    public function add(array $data, ?string $rate = null): int
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        if ($rate !== null) {
            unset($data['amount_base']);
        }
        $columns = array_keys($data);
        $marks = array_fill(0, count($columns), '?');
        $values = array_values($data);
        if ($rate !== null) {
            $columns[] = 'amount_base';
            $marks[] = BillingCurrency::CONVERT;
            array_push($values, $data['amount'], $rate);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO billing_transactions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')'
        );
        $stmt->execute($values);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Change a payment the user may see
     */
    public function updateVisible(int $id, array $data, ?int $userId, ?string $rate = null): bool
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        if ($rate !== null) {
            unset($data['amount_base']);
        }
        $sets = array_map(fn(string $column): string => "$column = ?", array_keys($data));
        $values = array_values($data);
        if ($rate !== null) {
            $sets[] = 'amount_base = ' . BillingCurrency::CONVERT;
            array_push($values, $data['amount'], $rate);
        }
        if (!$sets) {
            return false;
        }

        $sql = 'UPDATE billing_transactions SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $values[] = $id;
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $values[] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return true;
    }

    public function findVisible(int $id, ?int $userId): ?array
    {
        $sql = 'SELECT t.* FROM billing_transactions t WHERE t.id = ?' . ($userId !== null ? ' AND t.user_id = ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? [$id, $userId] : [$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Delete the given payments as far as the user may see them
     */
    public function deleteVisible(array $ids, ?int $userId): int
    {
        return $this->runOnVisible('DELETE FROM billing_transactions WHERE id IN (%s)', $ids, $userId);
    }

    /**
     * Confirm automatic payments. A row whose amount is still missing stays as it is.
     */
    public function confirmVisible(array $ids, ?int $userId): int
    {
        return $this->runOnVisible(
            "UPDATE billing_transactions SET needs_review = 0, status = 'paid' WHERE needs_review = 1 AND amount > 0 AND id IN (%s)",
            $ids,
            $userId
        );
    }

    /**
     * Payments of one domain, newest first
     */
    public function getForDomain(int $domainId, ?int $userId): array
    {
        return $this->search(['domain_id' => $domainId], $userId, 1000, 0);
    }

    /**
     * Payments matching the filters, newest first. Each row says whether its domain still exists.
     */
    public function search(array $filters, ?int $userId, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters, $userId);
        // In isolated mode a domain that now belongs to someone else counts as gone, so no link leads to it
        $ownDomain = $userId !== null ? ' AND d.user_id = ?' : '';
        $stmt = $this->db->prepare(
            "SELECT t.*, d.id AS live_domain_id FROM billing_transactions t
             LEFT JOIN domains d ON d.id = t.domain_id$ownDomain
             WHERE $where ORDER BY t.transaction_date DESC, t.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($userId !== null ? array_merge([$userId], $params) : $params);

        return $stmt->fetchAll();
    }

    public function countSearch(array $filters, ?int $userId): int
    {
        [$where, $params] = $this->buildWhere($filters, $userId);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM billing_transactions t WHERE $where");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * WHERE clause for the filters: search, type, status (paid, pending, review), from, to,
     * state (existing, deleted, none) and domain_id. In isolated mode a user sees only their own rows.
     */
    private function buildWhere(array $filters, ?int $userId): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($userId !== null) {
            $where[] = 't.user_id = ?';
            $params[] = $userId;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_' . chr(92)) . '%';
            $where[] = '(t.domain_name LIKE ? OR t.vendor LIKE ? OR t.invoice_number LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (isset(self::TYPE_LABELS[$filters['type'] ?? ''])) {
            $where[] = 't.type = ?';
            $params[] = $filters['type'];
        }
        $status = $filters['status'] ?? '';
        if (isset(self::STATUS_LABELS[$status])) {
            $where[] = 't.status = ?';
            $params[] = $status;
        } elseif ($status === 'review') {
            $where[] = 't.needs_review = 1';
        }
        if (!empty($filters['from'])) {
            $where[] = 't.transaction_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 't.transaction_date <= ?';
            $params[] = $filters['to'];
        }
        $state = $filters['state'] ?? '';
        if ($state === 'existing') {
            $where[] = 't.domain_id IS NOT NULL';
        } elseif ($state === 'deleted') {
            $where[] = "t.domain_id IS NULL AND t.domain_name <> ''";
        } elseif ($state === 'none') {
            $where[] = "t.domain_name = ''";
        }
        if (!empty($filters['domain_id'])) {
            $where[] = 't.domain_id = ?';
            $params[] = (int)$filters['domain_id'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Run a statement with an id list, limited to the rows the user may see
     */
    private function runOnVisible(string $sql, array $ids, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return 0;
        }
        $sql = sprintf($sql, implode(',', array_fill(0, count($ids), '?'))) . ($userId !== null ? ' AND user_id = ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? array_merge($ids, [$userId]) : $ids);

        return $stmt->rowCount();
    }

    // ---------------------------------------------------------------------
    // Totals. Every sum is done by the database on amount_base.
    // ---------------------------------------------------------------------

    /**
     * Counted payments per type
     *
     * @return array Type => ['total' => decimal string, 'count' => int]
     */
    public function sumsByType(array $filters, ?int $userId): array
    {
        [$where, $params] = $this->buildWhere($filters, $userId);
        $stmt = $this->db->prepare(
            'SELECT t.type, SUM(t.amount_base) AS total, COUNT(*) AS payments FROM billing_transactions t
             WHERE ' . $where . ' AND ' . self::COUNTED . ' GROUP BY t.type'
        );
        $stmt->execute($params);

        $sums = [];
        foreach ($stmt->fetchAll() as $row) {
            $sums[$row['type']] = ['total' => (string)$row['total'], 'count' => (int)$row['payments']];
        }

        return $sums;
    }

    /**
     * Counted payments per year and type, oldest year first
     *
     * @return array Year => [type => decimal string]
     */
    public function sumsByYear(array $filters, ?int $userId): array
    {
        [$where, $params] = $this->buildWhere($filters, $userId);
        $stmt = $this->db->prepare(
            'SELECT YEAR(t.transaction_date) AS y, t.type, SUM(t.amount_base) AS total FROM billing_transactions t
             WHERE ' . $where . ' AND ' . self::COUNTED . ' GROUP BY y, t.type ORDER BY y'
        );
        $stmt->execute($params);

        $years = [];
        foreach ($stmt->fetchAll() as $row) {
            $years[(int)$row['y']][$row['type']] = (string)$row['total'];
        }

        return $years;
    }

    /**
     * What each existing domain has cost so far
     *
     * @return array Domain id => decimal string
     */
    public function expensesByDomain(array $filters, ?int $userId): array
    {
        [$where, $params] = $this->buildWhere($filters, $userId);
        $types = "'" . implode("', '", self::EXPENSE_TYPES) . "'";
        $stmt = $this->db->prepare(
            'SELECT t.domain_id, SUM(t.amount_base) AS total FROM billing_transactions t
             WHERE ' . $where . ' AND ' . self::COUNTED . " AND t.domain_id IS NOT NULL AND t.type IN ($types)
             GROUP BY t.domain_id"
        );
        $stmt->execute($params);

        return array_map('strval', array_column($stmt->fetchAll(), 'total', 'domain_id'));
    }

    /**
     * The yearly amount of each domain's last confirmed renewal, in the currency it was paid in
     *
     * @return array Domain id => ['amount' => decimal string, 'currency' => code]
     */
    public function lastRenewals(?int $userId): array
    {
        $usable = "%s.type = 'renewal' AND %s.status = 'paid' AND %s.needs_review = 0 AND %s.amount > 0 AND %s.domain_id IS NOT NULL";
        $owner = $userId !== null ? ' AND %s.user_id = ?' : '';
        $sql = 'SELECT t.domain_id, t.currency, ROUND(t.amount / COALESCE(NULLIF(t.term_years, 0), 1), 2) AS yearly
                FROM billing_transactions t
                WHERE ' . str_replace('%s', 't', $usable . $owner) . '
                  AND NOT EXISTS (
                      SELECT 1 FROM billing_transactions n
                      WHERE n.domain_id = t.domain_id AND ' . str_replace('%s', 'n', $usable . $owner) . '
                        AND (n.transaction_date > t.transaction_date OR (n.transaction_date = t.transaction_date AND n.id > t.id))
                  )';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($userId !== null ? [$userId, $userId] : []);

        $last = [];
        foreach ($stmt->fetchAll() as $row) {
            $last[(int)$row['domain_id']] = ['amount' => (string)$row['yearly'], 'currency' => $row['currency']];
        }

        return $last;
    }

    /**
     * What is still pending: saved, but not counted in any total
     */
    public function pendingTotal(?int $userId): string
    {
        [$where, $params] = $this->buildWhere(['status' => 'pending'], $userId);
        $types = "'" . implode("', '", self::EXPENSE_TYPES) . "'";
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(t.amount_base), 0) FROM billing_transactions t WHERE $where AND t.type IN ($types)");
        $stmt->execute($params);

        return (string)$stmt->fetchColumn();
    }

    /**
     * Ids of the domains a sale is recorded for
     */
    public function soldDomainIds(?int $userId): array
    {
        [$where, $params] = $this->buildWhere(['type' => 'sale', 'state' => 'existing'], $userId);
        $stmt = $this->db->prepare("SELECT DISTINCT t.domain_id FROM billing_transactions t WHERE $where");
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many automatic payments wait for confirmation
     */
    public function countReview(?int $userId, ?int $domainId = null): int
    {
        return $this->countSearch(['status' => 'review', 'domain_id' => $domainId], $userId);
    }

    /**
     * Whether any payment at all exists (the base currency is locked then)
     */
    public function hasAny(): bool
    {
        return (bool)$this->db->query('SELECT 1 FROM billing_transactions LIMIT 1')->fetchColumn();
    }

    /**
     * How often the same payment is saved already, so an import does not double it
     */
    public function countSame(array $row, ?int $userId): int
    {
        $sql = "SELECT COUNT(*) FROM billing_transactions WHERE domain_name = ? AND transaction_date = ? AND type = ?
                AND amount = CAST(? AS DECIMAL(12,2)) AND currency = ? AND COALESCE(invoice_number, '') = ? AND COALESCE(vendor, '') = ?";
        $params = [
            $row['domain_name'], $row['transaction_date'], $row['type'], $row['amount'], $row['currency'],
            (string)($row['invoice_number'] ?? ''), (string)($row['vendor'] ?? ''),
        ];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    // ---------------------------------------------------------------------
    // Renewals found by a check, and rows that outlive their domain
    // ---------------------------------------------------------------------

    /**
     * Log a payment a check detected, unless that renewal is logged already. Check and insert are
     * one statement, so a cron run and a manual refresh cannot both log it.
     *
     * @param array $row domain_id, domain_name, user_id, type, currency, transaction_date, vendor, status,
     *                   term_years, expires_on, notes
     * @param string $yearly Yearly price in the row's currency
     * @param string $rate Value of one unit of that currency in the base currency
     * @return bool Whether a row was added
     */
    public function addDetected(array $row, string $yearly, string $rate): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO billing_transactions
                (domain_id, domain_name, user_id, type, amount, currency, amount_base, transaction_date, vendor,
                 status, source, needs_review, term_years, expires_on, notes)
             SELECT ?, ?, ?, ?, x.amount, ?, ROUND(x.amount * CAST(? AS DECIMAL(18,8)), 2), ?, ?, ?, 'auto', 1, ?, ?, ?
             FROM (SELECT CAST(? AS DECIMAL(12,2)) * CAST(? AS UNSIGNED) AS amount) x
             WHERE NOT EXISTS (
                 SELECT 1 FROM billing_transactions e
                 WHERE e.domain_id = ? AND e.type IN ('renewal', 'transfer') AND e.expires_on IS NOT NULL
                   AND e.expires_on BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
             )"
        );
        $stmt->execute([
            $row['domain_id'], $row['domain_name'], $row['user_id'], $row['type'], $row['currency'], $rate,
            $row['transaction_date'], $row['vendor'], $row['status'], $row['term_years'], $row['expires_on'], $row['notes'],
            $yearly, $row['term_years'],
            $row['domain_id'], $row['expires_on'], $row['expires_on'],
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether a renewal reaching this expiry date (three days either way) is logged for the domain
     */
    public function renewalLogged(int $domainId, string $expiresOn): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM billing_transactions
             WHERE domain_id = ? AND type IN ('renewal', 'transfer') AND expires_on IS NOT NULL
               AND expires_on BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY) LIMIT 1"
        );
        $stmt->execute([$domainId, $expiresOn, $expiresOn]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Take a renewal the user entered by hand in the last 30 days as the detected one: it gets the
     * expiry date, so the detection does not log the same payment again
     */
    public function claimManual(int $domainId, ?int $userId, string $expiresOn, string $today): bool
    {
        $sql = "UPDATE billing_transactions SET expires_on = ?
                WHERE domain_id = ? AND type IN ('renewal', 'transfer') AND source <> 'auto' AND expires_on IS NULL
                  AND transaction_date BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND ?";
        $params = [$expiresOn, $domainId, $today, $today];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY transaction_date DESC, id DESC LIMIT 1');
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * How many payments a domain has, whatever their status
     */
    public function countForDomain(int $domainId, ?int $userId): int
    {
        return $this->countSearch(['domain_id' => $domainId], $userId);
    }

    /**
     * Whether a sale is recorded for the domain: from then on someone else pays for it
     */
    public function hasSale(int $domainId, ?int $userId): bool
    {
        return $this->countSearch(['domain_id' => $domainId, 'type' => 'sale'], $userId) > 0;
    }

    /**
     * Attach payments saved under a domain's name to the domain, after it was added (again)
     */
    public function relinkByName(int $domainId, string $domainName, ?int $userId): int
    {
        $sql = 'UPDATE billing_transactions SET domain_id = ? WHERE domain_id IS NULL AND domain_name = ?';
        $params = [$domainId, $domainName];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * For the switch to isolated mode. While everything was shared, a payment was stamped with whoever
     * was there. Now each payment of an existing domain goes to that domain's owner, and a payment
     * that still has no owner goes to the given user.
     */
    public function assignForIsolation(int $userId): int
    {
        $byDomain = $this->db->exec(
            'UPDATE billing_transactions t JOIN domains d ON d.id = t.domain_id
             SET t.user_id = d.user_id WHERE d.user_id IS NOT NULL AND NOT (t.user_id <=> d.user_id)'
        );
        $stmt = $this->db->prepare('UPDATE billing_transactions SET user_id = ? WHERE user_id IS NULL');
        $stmt->execute([$userId]);

        return (int)$byDomain + $stmt->rowCount();
    }
}
