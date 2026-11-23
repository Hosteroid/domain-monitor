<?php

namespace App\Models;

use Core\Model;

class RememberToken extends Model
{
    protected static string $table = 'remember_tokens';

    /**
     * Delete remember tokens by session ID
     * Called when a session is terminated
     */
    public function deleteBySessionId(string $sessionId): int
    {
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        return $stmt->rowCount();
    }

    /**
     * Delete a user's own remember token by the session it belongs to.
     * For a session whose row is gone, so that nothing but the token says whose it was:
     * a session ID from a request must not reach another user's token.
     */
    public function deleteOwnBySessionId(string $sessionId, int $userId): int
    {
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE session_id = ? AND user_id = ?");
        $stmt->execute([$sessionId, $userId]);
        return $stmt->rowCount();
    }

    /**
     * Get remember token by session ID
     */
    public function getBySessionId(string $sessionId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM remember_tokens WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Clean old expired tokens
     */
    public function cleanExpired(): int
    {
        $stmt = $this->db->query("DELETE FROM remember_tokens WHERE expires_at < NOW()");
        return $stmt->rowCount();
    }

    /**
     * Forget the remembered logins of a user, all but one.
     * Used by "Logout Others" and after a password change. It goes by user and not by session:
     * a remembered browser has no session row any more once its session has expired.
     *
     * @param string $keepToken The token to keep, usually this browser's own ('' keeps none)
     */
    public function deleteOthers(int $userId, string $keepToken = ''): int
    {
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND token <> ?");
        $stmt->execute([$userId, $keepToken]);
        return $stmt->rowCount();
    }

    /**
     * Count the remembered logins of a user that the session list does not show:
     * browsers whose session has expired and that can still log in by themselves.
     *
     * @param string $ownToken This browser's own token, which is not counted
     */
    public function countWithoutSession(int $userId, string $ownToken = ''): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM remember_tokens t
             WHERE t.user_id = ? AND t.expires_at > NOW() AND t.token <> ?
               AND NOT EXISTS (SELECT 1 FROM sessions s WHERE s.id = t.session_id AND s.user_id = t.user_id)"
        );
        $stmt->execute([$userId, $ownToken]);
        return (int)$stmt->fetchColumn();
    }
}

