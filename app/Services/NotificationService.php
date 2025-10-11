<?php

namespace App\Services;

use App\Models\Notification;

class NotificationService
{
    private Notification $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new Notification();
    }

    /**
     * Create a domain expiring notification
     */
    public function notifyDomainExpiring(int $userId, string $domainName, int $daysLeft, int $domainId): void
    {
        $this->notificationModel->createNotification(
            $userId,
            'domain_expiring',
            'Domain Expiring Soon',
            "{$domainName} expires in {$daysLeft} day" . ($daysLeft > 1 ? 's' : ''),
            $domainId
        );
    }

    /**
     * Create a domain expired notification
     */
    public function notifyDomainExpired(int $userId, string $domainName, int $domainId): void
    {
        $this->notificationModel->createNotification(
            $userId,
            'domain_expired',
            'Domain Expired',
            "{$domainName} has expired - renew immediately",
            $domainId
        );
    }

    /**
     * Create a domain WHOIS updated notification
     */
    public function notifyDomainUpdated(int $userId, string $domainName, int $domainId, string $changes = ''): void
    {
        $message = !empty($changes) ? 
            "{$domainName} - {$changes}" : 
            "{$domainName} WHOIS data updated";
            
        $this->notificationModel->createNotification(
            $userId,
            'domain_updated',
            'Domain WHOIS Updated',
            $message,
            $domainId
        );
    }

    /**
     * Create a WHOIS lookup failed notification
     */
    public function notifyWhoisFailed(int $userId, string $domainName, int $domainId, string $reason = ''): void
    {
        $message = !empty($reason) ? 
            "Could not refresh {$domainName} - {$reason}" : 
            "Could not refresh {$domainName}";
            
        $this->notificationModel->createNotification(
            $userId,
            'whois_failed',
            'WHOIS Lookup Failed',
            $message,
            $domainId
        );
    }

    /**
     * Create a new login notification
     */
    public function notifyNewLogin(int $userId, string $location, string $ipAddress): void
    {
        $this->notificationModel->createNotification(
            $userId,
            'session_new',
            'New Login Detected',
            "Login from {$location} ({$ipAddress})",
            null
        );
    }

    /**
     * Create welcome notification for new users/fresh install
     */
    public function notifyWelcome(int $userId, string $username): void
    {
        $this->notificationModel->createNotification(
            $userId,
            'system_welcome',
            'Welcome to Domain Monitor! 🎉',
            "Hi {$username}! Your account is ready. Start by adding your first domain to monitor.",
            null
        );
    }

    /**
     * Create system upgrade notification for admins
     */
    public function notifySystemUpgrade(int $userId, string $fromVersion, string $toVersion, int $migrationsCount): void
    {
        $this->notificationModel->createNotification(
            $userId,
            'system_upgrade',
            'System Upgraded Successfully',
            "Domain Monitor upgraded from v{$fromVersion} to v{$toVersion} ({$migrationsCount} migration" . ($migrationsCount > 1 ? 's' : '') . " applied)",
            null
        );
    }

    /**
     * Notify all admins about system upgrade
     */
    public function notifyAdminsUpgrade(string $fromVersion, string $toVersion, int $migrationsCount): void
    {
        try {
            $pdo = \Core\Database::getConnection();
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
            $admins = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($admins as $admin) {
                $this->notifySystemUpgrade($admin['id'], $fromVersion, $toVersion, $migrationsCount);
            }
        } catch (\Exception $e) {
            error_log("Failed to notify admins about upgrade: " . $e->getMessage());
        }
    }

    /**
     * Delete old read notifications (cleanup)
     */
    public function cleanOldNotifications(int $daysOld = 30): void
    {
        try {
            $pdo = \Core\Database::getConnection();
            $stmt = $pdo->prepare(
                "DELETE FROM user_notifications 
                 WHERE is_read = 1 
                 AND read_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$daysOld]);
        } catch (\Exception $e) {
            error_log("Failed to clean old notifications: " . $e->getMessage());
        }
    }

    /**
     * Send notification to all active channels in a group
     *
     * @param int $groupId The notification group ID
     * @param string $subject The notification subject
     * @param string $message The notification message
     * @return void
     */
    public function sendToGroup(int $groupId, string $subject, string $message): void
    {
        try {
            // Get active channels for the group
            $channelModel = new \App\Models\NotificationChannel();
            $channels = $channelModel->getActiveByGroupId($groupId);

            foreach ($channels as $channel) {
                // Get channel configuration
                $config = json_decode($channel['channel_config'], true);
                if (!$config) continue;

                // Create channel instance based on type
                $channelClass = "\\App\\Services\\Channels\\" . ucfirst($channel['channel_type']) . "Channel";
                if (!class_exists($channelClass)) {
                    error_log("Notification channel class not found: " . $channelClass);
                    continue;
                }

                $channelInstance = new $channelClass();
                
                // Prepare data for channel
                $data = [
                    'subject' => $subject,
                    'message' => $message
                ];

                // Send notification through channel
                try {
                    $channelInstance->send($config, $message, $data);
                } catch (\Exception $e) {
                    error_log("Failed to send notification through channel {$channel['channel_type']}: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to send group notification: " . $e->getMessage());
        }
    }

    /**
     * Send domain expiration alert through multiple channels
     *
     * @param array $domain Domain data including expiration details
     * @param array $channels Array of notification channels to use
     * @return array Array of notification results with success status and channel type
     */
    public function sendDomainExpirationAlert(array $domain, array $channels): array
    {
        $results = [];
        $daysLeft = $this->calculateDaysLeft($domain['expiration_date']);

        // Prepare notification message
        if ($daysLeft <= 0) {
            $subject = "Domain Expired: {$domain['domain_name']}";
            $message = "URGENT: {$domain['domain_name']} has EXPIRED!\n\n" .
                      "Please take immediate action to prevent domain loss.\n" .
                      "Registrar: {$domain['registrar']}\n" .
                      ($domain['registrar_url'] ? "Renewal URL: {$domain['registrar_url']}\n" : "");
        } else {
            $subject = "Domain Expiring: {$domain['domain_name']}";
            $message = "Domain {$domain['domain_name']} expires in $daysLeft day" . ($daysLeft > 1 ? 's' : '') . "\n\n" .
                      "Expiration Date: {$domain['expiration_date']}\n" .
                      "Registrar: {$domain['registrar']}\n" .
                      ($domain['registrar_url'] ? "Renewal URL: {$domain['registrar_url']}\n" : "");
        }

        foreach ($channels as $channel) {
            $result = [
                'channel' => $channel['channel_type'],
                'success' => false
            ];

            try {
                // Get channel configuration
                $config = json_decode($channel['channel_config'], true);
                if (!$config) {
                    $results[] = $result;
                    continue;
                }

                // Create channel instance
                $channelClass = "\\App\\Services\\Channels\\" . ucfirst($channel['channel_type']) . "Channel";
                if (!class_exists($channelClass)) {
                    $results[] = $result;
                    continue;
                }

                $channelInstance = new $channelClass();
                
                // Send notification
                $data = [
                    'subject' => $subject,
                    'message' => $message,
                    'domain' => $domain
                ];

                if ($channelInstance->send($config, $message, $data)) {
                    $result['success'] = true;
                }
            } catch (\Exception $e) {
                error_log("Failed to send expiration alert through {$channel['channel_type']}: " . $e->getMessage());
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Calculate days until domain expiration
     *
     * @param string $expirationDate Expiration date string
     * @return int|null Number of days until expiration or null if invalid date
     */
    private function calculateDaysLeft(string $expirationDate): ?int
    {
        try {
            $expDate = new \DateTime($expirationDate);
            $now = new \DateTime();
            $diff = $expDate->diff($now);
            return $diff->invert ? $diff->days : -$diff->days;
        } catch (\Exception $e) {
            return null;
        }
    }
}
