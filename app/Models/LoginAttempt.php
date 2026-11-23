<?php

namespace App\Models;

use Core\Model;

/**
 * Failed logins, kept for as long as the login attempt limit counts them.
 *
 * login_name holds the name as it was typed. The column has the collation of users.username and
 * users.email, so "=" groups the same spellings here that reach one account there: a count
 * keyed by a name tidied up in PHP would give "Admin", "àdmin" and the like a count each.
 */
class LoginAttempt extends Model
{
    protected static string $table = 'login_attempts';

    /**
     * Longest name that is counted, in bytes. Usernames and email addresses are plain ASCII of at
     * most 255 characters, so nothing longer can be an account.
     */
    public const NAME_BYTES = 255;

    /**
     * Whether the table is there: the update that adds it may not have run yet
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('login_name');
    }

    /**
     * Note an attempt
     *
     * @return int Its id
     */
    public function add(string $name, string $ip, int $time): int
    {
        return $this->create(['login_name' => $name, 'ip_address' => $ip, 'attempted_at' => $time]);
    }

    /**
     * Attempts noted for a name since a time: from all addresses, and from one of them
     *
     * @return array{total: int, address: int}
     */
    public function counts(string $name, string $ip, int $since): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(ip_address = ?), 0) AS address
             FROM login_attempts
             WHERE login_name = ? AND attempted_at > ?"
        );
        $stmt->execute([$ip, $name, $since]);
        $row = $stmt->fetch();

        return ['total' => (int)$row['total'], 'address' => (int)$row['address']];
    }

    /**
     * Time of the nth newest attempt for a name since a time, from one address or from all.
     * Once that one is older than the window, fewer than n are left.
     *
     * @param string|null $ip Null for all addresses
     * @return int|null Unix time, null when there are fewer than n
     */
    public function nthNewest(string $name, ?string $ip, int $since, int $n): ?int
    {
        $sql = "SELECT attempted_at FROM login_attempts WHERE login_name = ? AND attempted_at > ?";
        $params = [$name, $since];
        if ($ip !== null) {
            $sql .= " AND ip_address = ?";
            $params[] = $ip;
        }
        $sql .= " ORDER BY attempted_at DESC, id DESC LIMIT 1 OFFSET " . max(0, $n - 1);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $time = $stmt->fetchColumn();

        return $time === false ? null : (int)$time;
    }

    /**
     * Take one attempt back
     */
    public function remove(int $id): void
    {
        $this->delete($id);
    }

    /**
     * Delete every attempt noted for these names, from all addresses
     *
     * @param string[] $names
     * @return int Rows deleted
     */
    public function forget(array $names): int
    {
        if (!$names) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE login_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')'
        );
        $stmt->execute(array_values($names));

        return $stmt->rowCount();
    }

    /**
     * Delete the attempts that no longer count
     *
     * @param int $until Unix time: attempts up to it are deleted
     * @return int Rows deleted
     */
    public function purge(int $until): int
    {
        $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE attempted_at <= ?");
        $stmt->execute([$until]);

        return $stmt->rowCount();
    }
}
