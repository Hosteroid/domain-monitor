<?php

namespace App\Models;

use Core\Model;

/**
 * Session Manager Model
 * 
 * Manages database-backed sessions with geolocation tracking.
 * Works with DatabaseSessionHandler to provide true session control.
 */
class SessionManager extends Model
{
    protected static string $table = 'sessions';

    /**
     * Get all active sessions for a user
     */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                id,
                user_id,
                ip_address,
                user_agent,
                country,
                country_code,
                region,
                city,
                isp,
                timezone,
                last_activity,
                created_at
            FROM sessions 
            WHERE user_id = ? 
            ORDER BY last_activity DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get session by ID
     */
    public function getById(string $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                id,
                user_id,
                ip_address,
                user_agent,
                country,
                country_code,
                region,
                city,
                isp,
                timezone,
                last_activity,
                created_at
            FROM sessions 
            WHERE id = ?"
        );
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get geolocation data from IP address
     * (Moved from old Session model)
     */
    public static function getGeolocationData(string $ipAddress): array
    {
        // Skip for localhost/private IPs
        if (in_array($ipAddress, ['127.0.0.1', '::1', 'localhost']) || 
            preg_match('/^(10|172\.16|192\.168)\./', $ipAddress)) {
            return [
                'country' => 'Local',
                'country_code' => 'xx',
                'region' => 'Local',
                'city' => 'Local',
                'isp' => 'Local Network',
                'timezone' => date_default_timezone_get(),
            ];
        }

        try {
            // Using ip-api.com (free, no API key needed, 45 requests/minute)
            $url = "http://ip-api.com/json/{$ipAddress}?fields=status,country,countryCode,region,city,isp,timezone";
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'user_agent' => 'Domain Monitor/1.0'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                return self::getDefaultGeolocation();
            }
            
            $data = json_decode($response, true);
            
            if (!$data || $data['status'] !== 'success') {
                return self::getDefaultGeolocation();
            }
            
            return [
                'country' => $data['country'] ?? 'Unknown',
                'country_code' => strtolower($data['countryCode'] ?? 'xx'),
                'region' => $data['region'] ?? 'Unknown',
                'city' => $data['city'] ?? 'Unknown',
                'isp' => $data['isp'] ?? 'Unknown ISP',
                'timezone' => $data['timezone'] ?? date_default_timezone_get(),
            ];
            
        } catch (\Exception $e) {
            $logger = new \App\Services\Logger();
            $logger->warning('Geolocation lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return self::getDefaultGeolocation();
        }
    }

    /**
     * Get default geolocation data for fallback
     */
    private static function getDefaultGeolocation(): array
    {
        return [
            'country' => 'Unknown',
            'country_code' => 'xx',
            'region' => 'Unknown',
            'city' => 'Unknown',
            'isp' => 'Unknown ISP',
            'timezone' => date_default_timezone_get(),
        ];
    }

    /**
     * Delete session by ID (this actually logs out the user!)
     */
    public function deleteById(string $sessionId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
        return $stmt->execute([$sessionId]);
    }

    /**
     * Delete all sessions for user except current
     */
    public function deleteOtherSessions(int $userId, string $currentSessionId): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM sessions WHERE user_id = ? AND id != ?"
        );
        $stmt->execute([$userId, $currentSessionId]);
        return $stmt->rowCount();
    }

    /**
     * Delete all sessions for user
     */
    public function deleteAllUserSessions(int $userId): int
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Clean old sessions (older than session lifetime)
     */
    public function cleanOldSessions(int $lifetimeMinutes = 1440): int
    {
        $cutoff = time() - ($lifetimeMinutes * 60);
        
        $stmt = $this->db->prepare(
            "DELETE FROM sessions WHERE last_activity < ?"
        );
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }
}

