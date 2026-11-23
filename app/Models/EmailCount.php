<?php

namespace App\Models;

use Core\Model;

/**
 * Password reset and verification emails per address, for the account email limit.
 *
 * One row per address and kind holds when its time window began and how many emails went out
 * since. The email column has the collation of users.email, so every spelling the users table
 * treats as one address is one row here too.
 */
class EmailCount extends Model
{
    protected static string $table = 'email_counts';

    /**
     * Whether the table is there: the update that adds it may not have run yet
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('window_start');
    }

    /**
     * Count one more email for an address, unless its count is full. One statement decides and
     * counts, so of requests arriving together only as many pass as there is room for.
     *
     * @param int $limit Emails allowed per window
     * @param int $now Unix time
     * @param int $cutoff Unix time: a window that began at or before it has ended
     * @return bool Whether the email may go out
     */
    public function take(string $email, string $kind, int $limit, int $now, int $cutoff): bool
    {
        // Makes sure the row is there. An existing one is left as it is.
        $stmt = $this->db->prepare(
            "INSERT INTO email_counts (email, kind, window_start, sent) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE email = email"
        );
        $stmt->execute([$email, $kind, $now]);

        // sent is set first: it has to see the window_start from before this statement
        $stmt = $this->db->prepare(
            "UPDATE email_counts
             SET sent = IF(window_start <= ?, 1, sent + 1),
                 window_start = IF(window_start <= ?, ?, window_start)
             WHERE email = ? AND kind = ? AND (window_start <= ? OR sent < ?)"
        );
        $stmt->execute([$cutoff, $cutoff, $now, $email, $kind, $cutoff, $limit]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The count of an address
     *
     * @return array{window_start: int, sent: int}|null Null when it has no row
     */
    public function state(string $email, string $kind): ?array
    {
        $stmt = $this->db->prepare("SELECT window_start, sent FROM email_counts WHERE email = ? AND kind = ?");
        $stmt->execute([$email, $kind]);
        $row = $stmt->fetch();

        return $row ? ['window_start' => (int)$row['window_start'], 'sent' => (int)$row['sent']] : null;
    }

    /**
     * Take one email back out of the count it went into
     *
     * @param int $windowStart The window it was counted in: a later one is left alone
     */
    public function giveBack(string $email, string $kind, int $windowStart): void
    {
        $stmt = $this->db->prepare(
            "UPDATE email_counts SET sent = sent - 1
             WHERE email = ? AND kind = ? AND window_start = ? AND sent > 0"
        );
        $stmt->execute([$email, $kind, $windowStart]);
    }

    /**
     * Delete the counts whose window has ended
     *
     * @param int $cutoff Unix time: windows that began at or before it are deleted
     * @return int Rows deleted
     */
    public function purge(int $cutoff): int
    {
        $stmt = $this->db->prepare("DELETE FROM email_counts WHERE window_start <= ?");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
