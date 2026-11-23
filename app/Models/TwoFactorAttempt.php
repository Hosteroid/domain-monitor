<?php

namespace App\Models;

use Core\Model;

/**
 * Wrong 2FA codes, kept for as long as the 2FA code limit counts them.
 *
 * Every time here is the database's own clock: created_at is filled in by the database, and
 * UNIX_TIMESTAMP() of that column is the same number whatever the time zones of the app, the
 * connection and the server are. A time made in PHP must never be compared with created_at, and
 * the bare column has to stay inside UNIX_TIMESTAMP(): through another function its value would
 * pass the connection's time zone again.
 */
class TwoFactorAttempt extends Model
{
    protected static string $table = 'two_factor_verification_attempts';

    /** Longest address the column holds */
    private const ADDRESS_LENGTH = 45;

    /**
     * Note a code that is about to be checked
     *
     * @return int Its id
     */
    public function add(int $userId, string $ip): int
    {
        return $this->create([
            'user_id' => $userId,
            'ip_address' => substr($ip, 0, self::ADDRESS_LENGTH),
            'success' => 0,
        ]);
    }

    /**
     * Wrong codes noted for an account in the last seconds, from all addresses together. Rows of
     * correct codes, which older versions kept too, are not counted.
     */
    public function failures(int $userId, int $seconds): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM two_factor_verification_attempts
             WHERE user_id = ? AND success = 0 AND UNIX_TIMESTAMP(created_at) > UNIX_TIMESTAMP() - ?"
        );
        $stmt->execute([$userId, $seconds]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Seconds until an account has fewer than n wrong codes in the last seconds: until the nth
     * newest of them is older than that
     *
     * @return int 0 when there are fewer than n now
     */
    public function secondsUntilBelow(int $userId, int $seconds, int $n): int
    {
        // Two whole numbers, subtracted in PHP: the database may treat them as unsigned
        $stmt = $this->db->prepare(
            "SELECT UNIX_TIMESTAMP(created_at) AS noted_at, UNIX_TIMESTAMP() AS db_time
             FROM two_factor_verification_attempts
             WHERE user_id = ? AND success = 0 AND UNIX_TIMESTAMP(created_at) > UNIX_TIMESTAMP() - ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1 OFFSET " . max(0, $n - 1)
        );
        $stmt->execute([$userId, $seconds]);
        $row = $stmt->fetch();

        return $row ? max(1, (int)$row['noted_at'] + $seconds - (int)$row['db_time']) : 0;
    }

    /**
     * Take one attempt back
     */
    public function remove(int $id): void
    {
        $this->delete($id);
    }

    /**
     * Delete every attempt noted for an account
     *
     * @return int Rows deleted
     */
    public function forget(int $userId): int
    {
        $stmt = $this->db->prepare("DELETE FROM two_factor_verification_attempts WHERE user_id = ?");
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    /**
     * Delete the attempts of all accounts that no longer count
     *
     * @param int $seconds Attempts at least this old are deleted
     * @return int Rows deleted
     */
    public function purge(int $seconds): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM two_factor_verification_attempts WHERE UNIX_TIMESTAMP(created_at) <= UNIX_TIMESTAMP() - ?"
        );
        $stmt->execute([$seconds]);

        return $stmt->rowCount();
    }
}
