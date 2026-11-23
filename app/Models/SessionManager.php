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
     * What getGeolocationData() answered for each address during this request. A sign-in asks
     * twice for the same address, for its session row and for its notification: the second time
     * costs no request, and a lookup service that does not answer is waited for once.
     */
    private static array $geolocationByAddress = [];

    /**
     * Get geolocation data from IP address
     * (Moved from old Session model)
     */
    public static function getGeolocationData(string $ipAddress): array
    {
        if (!isset(self::$geolocationByAddress[$ipAddress])) {
            self::$geolocationByAddress[$ipAddress] = self::lookUpGeolocation($ipAddress);
        }

        return self::$geolocationByAddress[$ipAddress];
    }

    /**
     * Whether an address is a public one. Only those are looked up: not an address of a private
     * or reserved range (local networks, Docker, link-local, loopback), IPv4 or IPv6.
     */
    private static function isPublicAddress(string $ipAddress): bool
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10, shared by providers and VPNs, is on neither of PHP's two lists
        return !preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./', $ipAddress);
    }

    /**
     * Look one address up, or answer without asking where there is nothing to look up
     */
    private static function lookUpGeolocation(string $ipAddress): array
    {
        // An IPv4 address written as IPv6 (::ffff:192.168.1.5) is the IPv4 address: PHP versions
        // disagree on whether that form is a reserved one
        if (preg_match('/^::ffff:(\d{1,3}(\.\d{1,3}){3})$/i', $ipAddress, $mapped)) {
            $ipAddress = $mapped[1];
        }

        // Text that is no address is not sent to the lookup service
        if ($ipAddress !== 'localhost' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return self::getDefaultGeolocation();
        }

        // Skip for localhost/private IPs
        if (!self::isPublicAddress($ipAddress)) {
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
            
            // Without "status": "success" it is no answer, whatever else came back
            if (!$data || ($data['status'] ?? null) !== 'success') {
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
                'ip' => $ipAddress,
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
     * Delete all sessions that are past their lifetime: the ones the session handler no longer
     * accepts. The lifetime is the configured one, never a fixed 24 hours: with a longer
     * SESSION_LIFETIME that ended sessions that were still valid.
     *
     * The statement itself is the handler's (DatabaseSessionHandler::deleteExpired()), which
     * removes a batch per call. Here it runs until nothing is left: Profile lists right after.
     */
    public function cleanOldSessions(): int
    {
        $lifetime = \Core\SessionConfig::lifetime();
        $deleted = 0;

        do {
            $batch = \Core\DatabaseSessionHandler::deleteExpired($this->db, $lifetime);
            $deleted += $batch;
        } while ($batch >= \Core\DatabaseSessionHandler::EXPIRED_BATCH);

        return $deleted;
    }
}

