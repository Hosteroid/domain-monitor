<?php

namespace Core;

use SessionHandlerInterface;
use PDO;

/**
 * Database Session Handler
 * 
 * Stores PHP sessions in database with geolocation tracking.
 * Provides true session management where deleting a session actually logs out the user.
 */
class DatabaseSessionHandler implements SessionHandlerInterface
{
    /** How many rows one call of deleteExpired() removes at most */
    public const EXPIRED_BATCH = 1000;

    private PDO $db;
    private int $lifetime;
    
    /**
     * @param int $lifetime Minutes a session may stay idle, from SessionConfig::lifetime()
     */
    public function __construct(int $lifetime)
    {
        $this->db = Database::getConnection();
        $this->lifetime = $lifetime;
    }
    
    /**
     * Open session
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }
    
    /**
     * Close session
     */
    public function close(): bool
    {
        return true;
    }
    
    /**
     * Read session data
     */
    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT payload FROM sessions WHERE id = ? AND last_activity > ?"
            );
            $stmt->execute([$id, time() - ($this->lifetime * 60)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Update last activity
                $this->updateActivity($id);
                return $result['payload'];
            }
            
            return '';
        } catch (\Exception $e) {
            error_log("Session read failed: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Write session data
     */
    public function write(string $id, string $data): bool
    {
        try {
            // Extract user_id from session data
            $sessionData = $this->unserializeSession($data);
            $userId = $sessionData['user_id'] ?? null;
            
            // Get IP and user agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Check if session exists
            $stmt = $this->db->prepare("SELECT id, country FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            // A new session that holds nothing needs no row: reading it gives the same nothing
            if (!$existing && $data === '') {
                return true;
            }

            // The location is looked up once per session, and only for one that has a user:
            // Profile lists no others. A visitor who is not signed in starts no request to the
            // lookup service, and their address is not sent to it.
            $geoData = null;
            if ($userId !== null && (!$existing || $existing['country'] === null)) {
                $geoData = \App\Models\SessionManager::getGeolocationData($ipAddress);
            }

            if ($existing && $geoData !== null) {
                // The session got its user with this request: the row takes its address, browser and location
                $stmt = $this->db->prepare(
                    "UPDATE sessions SET payload = ?, last_activity = ?, user_id = ?, ip_address = ?, user_agent = ?,
                         country = ?, country_code = ?, region = ?, city = ?, isp = ?, timezone = ?
                     WHERE id = ?"
                );
                return $stmt->execute([
                    $data,
                    time(),
                    $userId,
                    $ipAddress,
                    $userAgent,
                    $geoData['country'],
                    $geoData['country_code'],
                    $geoData['region'],
                    $geoData['city'],
                    $geoData['isp'],
                    $geoData['timezone'],
                    $id
                ]);
            } elseif ($existing) {
                // Update existing session
                $stmt = $this->db->prepare(
                    "UPDATE sessions SET payload = ?, last_activity = ?, user_id = ? WHERE id = ?"
                );
                return $stmt->execute([$data, time(), $userId, $id]);
            } else {
                // A new row is about to be added: the rows past their lifetime go first. PHP calls
                // gc() by chance only, and never where a PHP package has switched that off.
                $this->gc($this->lifetime * 60);

                // Insert new session
                $stmt = $this->db->prepare(
                    "INSERT INTO sessions (id, user_id, ip_address, user_agent, country, country_code, region, city, isp, timezone, payload, last_activity, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $currentTime = time();
                return $stmt->execute([
                    $id,
                    $userId,
                    $ipAddress,
                    $userAgent,
                    $geoData['country'] ?? null,
                    $geoData['country_code'] ?? null,
                    $geoData['region'] ?? null,
                    $geoData['city'] ?? null,
                    $geoData['isp'] ?? null,
                    $geoData['timezone'] ?? null,
                    $data,
                    $currentTime,
                    $currentTime  // created_at = same as last_activity initially
                ]);
            }
        } catch (\Exception $e) {
            error_log("Session write failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destroy session
     */
    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (\Exception $e) {
            error_log("Session destroy failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Garbage collection: delete sessions that are past their lifetime. The lifetime is the
     * handler's own (SESSION_LIFETIME); the one PHP passes is not used.
     */
    public function gc(int $max_lifetime): int|false
    {
        try {
            return self::deleteExpired($this->db, $this->lifetime);
        } catch (\Exception $e) {
            error_log("Session GC failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete the sessions that are past their lifetime, the ones read() no longer accepts.
     * At most EXPIRED_BATCH rows per call, so that no request pays for a large backlog alone.
     *
     * In two steps on purpose: the ids are read first, which locks nothing, and the rows are then
     * deleted by id. One "DELETE ... WHERE last_activity <= ?" walks the last_activity index and
     * also locks the first row behind the expired ones, a session that is still valid. When that
     * session is written at the same moment, the two statements wait for each other and the
     * database ends one of them. A session write that is ended is an error page.
     *
     * @param int $lifetime Minutes a session may stay idle
     * @return int Rows deleted
     */
    public static function deleteExpired(PDO $db, int $lifetime): int
    {
        $cutoff = time() - ($lifetime * 60);

        $stmt = $db->prepare("SELECT id FROM sessions WHERE last_activity <= ? LIMIT " . self::EXPIRED_BATCH);
        $stmt->execute([$cutoff]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$ids) {
            return 0;
        }

        // A row that was used again in the meantime is kept. "+ 0" keeps the last_activity index
        // out of this statement: it has to find its rows by id and by nothing else.
        $stmt = $db->prepare(
            "DELETE FROM sessions WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                 AND last_activity + 0 <= ?"
        );
        $stmt->execute([...$ids, $cutoff]);

        return $stmt->rowCount();
    }
    
    /**
     * Update session activity timestamp
     */
    private function updateActivity(string $id): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE sessions SET last_activity = ? WHERE id = ?"
            );
            $stmt->execute([time(), $id]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
    
    /**
     * Unserialize session data to extract variables
     */
    private function unserializeSession(string $data): array
    {
        $result = [];
        $offset = 0;
        
        while ($offset < strlen($data)) {
            // Parse key
            if (!preg_match('/(\w+)\|/', substr($data, $offset), $match)) {
                break;
            }
            
            $key = $match[1];
            $offset += strlen($match[0]);
            
            // Parse value
            $value = @unserialize(substr($data, $offset));
            if ($value === false && substr($data, $offset, 4) !== 'b:0;') {
                break;
            }
            
            $result[$key] = $value;
            $offset += strlen(serialize($value));
        }
        
        return $result;
    }
}

