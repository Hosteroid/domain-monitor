<?php

namespace App\Models;

use Core\Model;

/**
 * Personal API tokens. Only the SHA-256 hash of a token is stored.
 *
 * A token is never handed from one function to another, so it cannot land in a stack trace:
 * issue() makes it and returns it once, and a request is matched by a hash worked out right
 * where its header is read.
 */
class ApiToken extends Model
{
    protected static string $table = 'api_tokens';

    public const PREFIX = 'dmk_';

    /** What a token looks like. Anything else is refused before the database is asked. */
    public const PATTERN = '/^dmk_[0-9a-f]{40}$/';

    /** Most tokens one user can hold */
    public const MAX_PER_USER = 25;

    /** Seconds of one rate limit window */
    public const WINDOW = 60;

    /**
     * Whether the table is there: the update that adds it may not have run yet
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('token_hash');
    }

    /**
     * Create a token. The token itself is returned here once and cannot be read again.
     *
     * @param int|null $expiresAt Unix time, null for no expiry
     * @return array{id: int, token: string}
     */
    public function issue(int $userId, string $name, bool $canWrite, ?int $expiresAt): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));

        $id = $this->create([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, -4),
            'can_write' => $canWrite ? 1 : 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * The token with this hash, together with its owner
     *
     * @param string $hash SHA-256 of the token as it was sent
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.user_id, t.name, t.can_write, t.expires_at, t.window_start, t.window_count,
                    u.username, u.role, u.is_active
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?"
        );
        $stmt->execute([$hash]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Count a request against the token's limit and note its use. One statement decides and
     * counts, so requests arriving together cannot all pass.
     *
     * @param int $cost  What the request counts as: 1, or more for one that starts a lookup
     * @param int $limit Requests allowed per window
     * @return bool False when this window is used up
     */
    public function hit(int $id, int $cost, int $limit, string $ip): bool
    {
        $now = time();
        $window = $now - $now % self::WINDOW;

        // window_count is set first: it still reads the window_start of before the update
        $stmt = $this->db->prepare(
            "UPDATE api_tokens
             SET window_count = IF(window_start = ?, window_count + ?, ?),
                 window_start = ?, last_used_at = ?, last_used_ip = ?
             WHERE id = ? AND (window_start <> ? OR window_count + ? <= ?)"
        );
        $stmt->execute([$window, $cost, $cost, $window, $now, $ip, $id, $window, $cost, $limit]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether this is the first refused request of a token in the current minute. It keeps the
     * log to one line a minute for a token that keeps being refused.
     */
    public function firstInWindow(int $id): bool
    {
        $now = time();
        $window = $now - $now % self::WINDOW;

        $stmt = $this->db->prepare(
            "UPDATE api_tokens SET window_start = ?, window_count = 0 WHERE id = ? AND window_start <> ?"
        );
        $stmt->execute([$window, $id, $window]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Seconds until the current rate limit window ends
     */
    public static function secondsToNextWindow(): int
    {
        return self::WINDOW - time() % self::WINDOW;
    }

    /**
     * A user's tokens, newest first
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, token_hint, can_write, expires_at, last_used_at, last_used_ip, created_at
             FROM api_tokens
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM api_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Every token with its owner, for admins
     */
    public function allWithOwners(): array
    {
        return $this->db->query(
            "SELECT t.id, t.user_id, t.name, t.token_hint, t.can_write, t.expires_at, t.last_used_at,
                    t.last_used_ip, t.created_at, u.username, u.role, u.is_active
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             ORDER BY u.username, t.created_at DESC, t.id DESC"
        )->fetchAll();
    }

    /**
     * Token rows as the lists on the pages show them: each with a flag saying whether it has expired
     */
    public static function withState(array $tokens): array
    {
        $now = time();
        foreach ($tokens as $i => $token) {
            $tokens[$i]['expired'] = $token['expires_at'] !== null && (int)$token['expires_at'] <= $now;
        }

        return $tokens;
    }

    /**
     * Delete one of a user's own tokens
     *
     * @return array|null The deleted token's id, name and owner, or null when it is not theirs
     */
    public function revokeOwn(int $id, int $userId): ?array
    {
        $token = $this->summary($id);
        if (!$token || (int)$token['user_id'] !== $userId) {
            return null;
        }

        $stmt = $this->db->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0 ? $token : null;
    }

    /**
     * Delete any token (admins)
     *
     * @return array|null The deleted token's id, name and owner, or null when there was none
     */
    public function revoke(int $id): ?array
    {
        $token = $this->summary($id);
        if (!$token) {
            return null;
        }
        $this->delete($id);

        return $token;
    }

    /**
     * Delete every token of every user (admins)
     *
     * @return int Tokens deleted
     */
    public function revokeAll(): int
    {
        return (int)$this->db->exec("DELETE FROM api_tokens");
    }

    /**
     * What the log says about a token: never its hash
     */
    private function summary(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, user_id, name, can_write FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }
}
