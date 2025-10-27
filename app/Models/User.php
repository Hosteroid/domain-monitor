<?php

namespace App\Models;

use Core\Model;

class User extends Model
{
    protected static string $table = 'users';

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
     * Create password reset token
     */
    public function createPasswordResetToken(int $userId, string $token, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
        return $stmt->execute([$userId, $token, $expiresAt]);
    }

    /**
     * Find valid password reset token
     */
    public function findPasswordResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM password_reset_tokens WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Mark password reset token as used
     */
    public function markPasswordResetTokenAsUsed(int $tokenId): bool
    {
        $stmt = $this->db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
        return $stmt->execute([$tokenId]);
    }

    /**
     * Create remember token
     */
    public function createRememberToken(int $userId, string $sessionId, string $token, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO remember_tokens (user_id, session_id, token, expires_at) VALUES (?, ?, ?, ?)"
        );
        return $stmt->execute([$userId, $sessionId, $token, $expiresAt]);
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

