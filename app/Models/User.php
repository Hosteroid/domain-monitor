<?php

namespace App\Models;

use Core\Model;

class User extends Model
{
    protected static string $table = 'users';

    /** Minutes a password reset link is valid, as its email says */
    public const RESET_LINK_MINUTES = 60;

    /**
     * Find user by username
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Create user with hashed password
     */
    public function createUser(string $username, string $password, ?string $email = null, ?string $fullName = null, ?string $avatar = null): int
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        return $this->create([
            'username' => $username,
            'password' => $hashedPassword,
            'email' => $email,
            'full_name' => $fullName,
            'avatar' => $avatar,
            'is_active' => 1
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(int $userId, string $newPassword): bool
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $userId]);
    }
    
    /**
     * Get users with filters, sorting, and pagination
     */
    public function getFiltered(array $filters = [], string $sort = 'username', string $order = 'ASC', int $limit = 25, int $offset = 0): array
    {
        $query = "SELECT * FROM users WHERE 1=1";
        $params = [];
        
        // Apply search filter
        if (!empty($filters['search'])) {
            $query .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Apply role filter
        if (!empty($filters['role'])) {
            $query .= " AND role = ?";
            $params[] = $filters['role'];
        }
        
        // Apply status filter
        if (isset($filters['status']) && $filters['status'] !== '') {
            $isActive = ($filters['status'] === 'active') ? 1 : 0;
            $query .= " AND is_active = ?";
            $params[] = $isActive;
        }
        
        // Apply sorting
        $allowedSortColumns = ['username', 'email', 'full_name', 'role', 'is_active', 'email_verified', 'last_login', 'created_at'];
        if (!in_array($sort, $allowedSortColumns)) {
            $sort = 'username';
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $query .= " ORDER BY {$sort} {$order}";
        
        // Apply pagination
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Count users with filters
     */
    public function countFiltered(array $filters = []): int
    {
        $query = "SELECT COUNT(*) as total FROM users WHERE 1=1";
        $params = [];
        
        // Apply search filter
        if (!empty($filters['search'])) {
            $query .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Apply role filter
        if (!empty($filters['role'])) {
            $query .= " AND role = ?";
            $params[] = $filters['role'];
        }
        
        // Apply status filter
        if (isset($filters['status']) && $filters['status'] !== '') {
            $isActive = ($filters['status'] === 'active') ? 1 : 0;
            $query .= " AND is_active = ?";
            $params[] = $isActive;
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }

    /**
     * Update email verification token
     */
    public function updateEmailVerificationToken(int $userId, string $token): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$token, $userId]);
    }

    /**
     * Mark email as verified
     */
    public function markEmailAsVerified(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * Verify email by clearing token
     */
    public function verifyEmailByToken(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET email_verified = 1, email_verification_token = NULL WHERE id = ?"
        );
        return $stmt->execute([$userId]);
    }

    /**
     * Find user by email verification token
     */
    public function findByVerificationToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE email_verification_token = ? AND (email_verified IS NULL OR email_verified = 0)"
        );
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create password reset token. Its link is valid for RESET_LINK_MINUTES from created_at,
     * which the database fills in: see findPasswordResetToken().
     */
    public function createPasswordResetToken(int $userId, string $token): bool
    {
        // expires_at is for the record only, nothing decides by it. Rows of this table are
        // never updated: on MariaDB and older MySQL the column takes the current time on every
        // update that leaves it out.
        $stmt = $this->db->prepare(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at)
             VALUES (?, ?, FROM_UNIXTIME(UNIX_TIMESTAMP() + " . (self::RESET_LINK_MINUTES * 60) . "))"
        );
        return $stmt->execute([$userId, $token]);
    }

    /**
     * Delete a password reset token: its email did not go out, so nobody has the link
     */
    public function deletePasswordResetToken(string $token): bool
    {
        $stmt = $this->db->prepare("DELETE FROM password_reset_tokens WHERE token = ?");
        return $stmt->execute([$token]);
    }

    /**
     * Find valid password reset token: one that is younger than RESET_LINK_MINUTES.
     *
     * The age is measured on the database's clock on both sides: created_at is filled in by the
     * database, and UNIX_TIMESTAMP() of a TIMESTAMP column is its stored value. So the time
     * zones of the app and the database and daylight saving changes do not matter. A time made
     * in PHP (app time zone) must not be compared with the database's NOW().
     */
    public function findPasswordResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM password_reset_tokens
             WHERE token = ? AND used = 0 AND UNIX_TIMESTAMP(created_at) > UNIX_TIMESTAMP() - ?"
        );
        $stmt->execute([$token, self::RESET_LINK_MINUTES * 60]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Set a new password with a reset link. The link works once: it is deleted before the
     * password is saved, and a request that finds it gone stops. The account's other links are
     * deleted with it.
     *
     * @return bool False when the link was used or deleted in the meantime
     */
    public function resetPasswordWithToken(int $tokenId, int $userId, string $newPassword): bool
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM password_reset_tokens WHERE id = ? AND user_id = ?");
            $stmt->execute([$tokenId, $userId]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);

            $stmt = $this->db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete the password reset tokens that no longer work: older than RESET_LINK_MINUTES, or
     * marked as used by an earlier version
     *
     * @return int Rows deleted
     */
    public function purgePasswordResetTokens(): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM password_reset_tokens
             WHERE used = 1 OR UNIX_TIMESTAMP(created_at) <= UNIX_TIMESTAMP() - ?"
        );
        $stmt->execute([self::RESET_LINK_MINUTES * 60]);

        return $stmt->rowCount();
    }

    /**
     * Create remember token, valid for a number of days.
     * The expiry is set by the database's clock, the clock findByRememberToken() checks it by.
     */
    public function createRememberToken(int $userId, string $sessionId, string $token, int $days): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO remember_tokens (user_id, session_id, token, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? DAY)"
        );
        return $stmt->execute([$userId, $sessionId, $token, $days]);
    }

    /**
     * Link a remember token to the session it has just logged in
     */
    public function linkRememberToken(string $token, string $sessionId): bool
    {
        // expires_at is named so that it keeps its value: on MariaDB and older MySQL the column
        // takes the current time on every update that leaves it out
        $stmt = $this->db->prepare(
            "UPDATE remember_tokens SET session_id = ?, expires_at = expires_at WHERE token = ?"
        );
        return $stmt->execute([$sessionId, $token]);
    }

    /**
     * Find user by remember token
     */
    public function findByRememberToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id FROM remember_tokens WHERE token = ? AND expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Delete remember token
     */
    public function deleteRememberToken(string $token): bool
    {
        $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE token = ?");
        return $stmt->execute([$token]);
    }

    /**
     * Whether the last used authenticator app code is kept: the update that adds the column
     * (migration 041) may not have run yet
     */
    public function remembersTotpStep(): bool
    {
        return $this->hasColumn('two_factor_last_step');
    }

    /**
     * Use up the time step of an authenticator app code that was found right.
     *
     * One statement checks and stores, so of two requests with the same code only one changes
     * the row, however close together they come. Reading the step, comparing it in PHP and then
     * writing it would let both through. The secret is not part of the statement on purpose: the
     * arguments of a call that fails can end up in the error log.
     *
     * @param int $step Unix time / 30 of the code, as the authenticator app counted it
     * @return bool False when this step or a later one was used before, or 2FA is off by now
     */
    public function useTotpStep(int $userId, int $step): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET two_factor_last_step = ?
             WHERE id = ? AND two_factor_enabled = 1
               AND (two_factor_last_step IS NULL OR two_factor_last_step < ?)"
        );
        $stmt->execute([$step, $userId, $step]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Store what is left of the backup codes after one was used, but only if the list is still
     * the one that was read.
     *
     * One statement checks and stores, as in useTotpStep(): of two requests that take a code
     * from the list at the same moment only one changes the row, and the other has to read the
     * list again. Written back without the condition, one code works for both, and codes that
     * another request has just used or replaced come back.
     *
     * @param string $read The list as it was read. It is compared to the byte: "=" on the bare
     *                     column would ignore letter case.
     * @param string $left The list without the used code, as JSON
     * @return bool False when the list was changed in the meantime, or 2FA is off by now
     */
    public function takeBackupCode(int $userId, string $read, string $left): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET two_factor_backup_codes = ?
             WHERE id = ? AND two_factor_enabled = 1 AND CAST(two_factor_backup_codes AS BINARY) = ?"
        );
        $stmt->execute([$left, $userId, $read]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Get user's 2FA status
     */
    public function getTwoFactorStatus(int $userId): array
    {
        $user = $this->find($userId);
        if (!$user) {
            return ['enabled' => false, 'can_enable' => false, 'required' => false];
        }

        $twoFactorService = new \App\Services\TwoFactorService();
        
        return [
            'enabled' => (bool)$user['two_factor_enabled'],
            'can_enable' => $twoFactorService->canEnableTwoFactor($userId),
            'required' => $twoFactorService->isTwoFactorRequired($userId),
            'setup_at' => $user['two_factor_setup_at'],
            'backup_codes_count' => $user['two_factor_backup_codes'] ? count(json_decode($user['two_factor_backup_codes'], true)) : 0
        ];
    }

    /**
     * Check if user has verified email (required for 2FA)
     */
    public function hasVerifiedEmail(int $userId): bool
    {
        $user = $this->find($userId);
        return $user && $user['email_verified'];
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && $user['role'] === 'admin';
    }

    /**
     * Get first admin user
     */
    public function getFirstAdminUser(): ?array
    {
        $stmt = $this->db->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all admin users
     */
    public function getAllAdmins(): array
    {
        return $this->where('role', 'admin');
    }

    /**
     * Count admin users
     */
    public function countAdmins(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
        $result = $stmt->fetch();
        return (int)$result['count'];
    }

    /**
     * Find user by verification token (debug version - includes all users regardless of verification status)
     */
    public function findByVerificationTokenDebug(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT id, email, email_verified, email_verification_token FROM users WHERE email_verification_token = ?");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}

