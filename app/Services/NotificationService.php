<?php

namespace App\Services;

use App\Services\Channels\EmailChannel;
use App\Services\Channels\TelegramChannel;
use App\Services\Channels\DiscordChannel;
use App\Services\Channels\SlackChannel;
use App\Services\Channels\MattermostChannel;
use App\Services\Channels\WebhookChannel;
use App\Services\Channels\PushoverChannel;

class NotificationService
{
    private array $channels = [];

    public function __construct()
    {
        $this->channels = [
            'email' => new EmailChannel(),
            'telegram' => new TelegramChannel(),
            'discord' => new DiscordChannel(),
            'slack' => new SlackChannel(),
            'mattermost' => new MattermostChannel(),
            'webhook' => new WebhookChannel(),
            'pushover' => new PushoverChannel(),
        ];
    }

    /**
     * Send notification to specified channel
     */
    public function send(string $channelType, array $config, string $message, array $data = []): bool
    {
        if (!isset($this->channels[$channelType])) {
            return false;
        }

        try {
            return $this->channels[$channelType]->send($config, $message, $data);
        } catch (\Exception $e) {
            $logger = new \App\Services\Logger();
            $logger->error("Notification send failed", [
                'channel_type' => $channelType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send notification to all active channels in a group
     */
    public function sendToGroup(int $groupId, string $subject, string $message, array $data = []): array
    {
        // Get active channels for the group
        $channelModel = new \App\Models\NotificationChannel();
        $channels = $channelModel->getByGroupId($groupId);
        
        $results = [];
        
        foreach ($channels as $channel) {
            if (!$channel['is_active']) {
                continue; // Skip inactive channels
            }
            
            $config = json_decode($channel['channel_config'], true);
            
            // Add subject to data for channels that support it (like email)
            $channelData = array_merge(['subject' => $subject], $data);
            
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                $channelData
            );

            $results[] = [
                'channel' => $channel['channel_type'],
                'success' => $success
            ];
        }

        return $results;
    }

    /**
     * Send domain expiration notification
     */
    public function sendDomainExpirationAlert(array $domain, array $notificationChannels): array
    {
        $daysLeft = $this->calculateDaysLeft($domain['expiration_date']);
        $message = $this->formatExpirationMessage($domain, $daysLeft);

        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'days_left' => $daysLeft,
                    'expiration_date' => $domain['expiration_date'],
                    'registrar' => $domain['registrar']
                ]
            );

            $results[] = [
                'channel' => $channel['channel_type'],
                'success' => $success
            ];
        }

        return $results;
    }

    /**
     * Send domain status change notification via external channels
     */
    public function sendDomainStatusAlert(array $domain, array $notificationChannels, string $newStatus, string $oldStatus): array
    {
        $message = $this->formatStatusChangeMessage($domain, $newStatus, $oldStatus);

        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'new_status' => $newStatus,
                    'old_status' => $oldStatus,
                    'registrar' => $domain['registrar'] ?? 'Unknown'
                ]
            );

            $results[] = [
                'channel' => $channel['channel_type'],
                'success' => $success
            ];
        }

        return $results;
    }

    /**
     * Format status change notification message
     */
    private function formatStatusChangeMessage(array $domain, string $newStatus, string $oldStatus): string
    {
        $domainName = $domain['domain_name'];
        $registrar = $domain['registrar'] ?? 'Unknown';
        $oldStatusLabel = self::getStatusLabel($oldStatus);
        $newStatusLabel = self::getStatusLabel($newStatus);

        return match($newStatus) {
            'available' => "🟢 AVAILABLE: Domain '$domainName' is now available for registration!\n\n" .
                           "Previous status: $oldStatusLabel\n" .
                           "This domain can now be registered.",

            'active' => "✅ REGISTERED: Domain '$domainName' is now registered and active.\n\n" .
                        "Previous status: $oldStatusLabel\n" .
                        "Registrar: $registrar",

            'expired' => "🚨 EXPIRED: Domain '$domainName' has expired!\n\n" .
                         "Previous status: $oldStatusLabel\n" .
                         "Registrar: $registrar\n" .
                         "Please renew immediately to avoid losing your domain.",

            'redemption_period' => "⚠️ REDEMPTION PERIOD: Domain '$domainName' has entered the redemption period!\n\n" .
                                   "Previous status: $oldStatusLabel\n" .
                                   "Registrar: $registrar\n" .
                                   "The domain can still be recovered, but additional fees may apply. Act quickly!",

            'pending_delete' => "🔴 PENDING DELETE: Domain '$domainName' is scheduled for deletion!\n\n" .
                                "Previous status: $oldStatusLabel\n" .
                                "Registrar: $registrar\n" .
                                "The domain will be released for public registration soon.",

            default => "ℹ️ STATUS CHANGE: Domain '$domainName' status changed from $oldStatusLabel to $newStatusLabel.\n\n" .
                       "Registrar: $registrar"
        };
    }

    /**
     * Get human-readable status label
     */
    public static function getStatusLabel(string $status): string
    {
        return match($status) {
            'active' => 'Active',
            'expiring_soon' => 'Expiring Soon',
            'expired' => 'Expired',
            'available' => 'Available',
            'redemption_period' => 'Redemption Period',
            'pending_delete' => 'Pending Delete',
            'error' => 'Error',
            default => ucfirst(str_replace('_', ' ', $status))
        };
    }

    /**
     * Format expiration message
     */
    private function formatExpirationMessage(array $domain, int $daysLeft): string
    {
        $domainName = $domain['domain_name'];
        $expirationDate = $domain['expiration_date'] ? date('F j, Y', strtotime($domain['expiration_date'])) : 'Unknown';
        $registrar = $domain['registrar'] ?? 'Unknown';

        if ($daysLeft <= 0) {
            return "🚨 URGENT: Domain '$domainName' has EXPIRED on $expirationDate!\n\n" .
                   "Registrar: $registrar\n" .
                   "Please renew immediately to avoid losing your domain.";
        }

        if ($daysLeft == 1) {
            return "⚠️ CRITICAL: Domain '$domainName' expires TOMORROW ($expirationDate)!\n\n" .
                   "Registrar: $registrar\n" .
                   "Please renew as soon as possible.";
        }

        if ($daysLeft <= 7) {
            return "⚠️ WARNING: Domain '$domainName' expires in $daysLeft days ($expirationDate)!\n\n" .
                   "Registrar: $registrar\n" .
                   "Please renew soon.";
        }

        return "ℹ️ REMINDER: Domain '$domainName' expires in $daysLeft days ($expirationDate).\n\n" .
               "Registrar: $registrar\n" .
               "Please plan for renewal.";
    }

    /**
     * Calculate days left until expiration
     */
    private function calculateDaysLeft(string $expirationDate): int
    {
        $expiration = strtotime($expirationDate);
        $now = time();
        return (int)floor(($expiration - $now) / 86400);
    }

    // ========================================
    // IN-APP NOTIFICATION METHODS (Bell Icon)
    // ========================================

    /**
     * Create a domain expiring notification (in-app)
     */
    public function notifyDomainExpiring(int $userId, string $domainName, int $daysLeft, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_expiring',
            'Domain Expiring Soon',
            "{$domainName} expires in {$daysLeft} day" . ($daysLeft > 1 ? 's' : ''),
            $domainId
        );
    }

    /**
     * Create a domain expired notification (in-app)
     */
    public function notifyDomainExpired(int $userId, string $domainName, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_expired',
            'Domain Expired',
            "{$domainName} has expired - renew immediately",
            $domainId
        );
    }

    /**
     * Create a domain available notification (in-app)
     */
    public function notifyDomainAvailable(int $userId, string $domainName, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_available',
            'Domain Available',
            "{$domainName} is now available for registration",
            $domainId
        );
    }

    /**
     * Create a domain registered notification (in-app)
     * Triggered when a domain transitions from available/expired/pending_delete to active
     */
    public function notifyDomainRegistered(int $userId, string $domainName, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_registered',
            'Domain Registered',
            "{$domainName} has been registered and is now active",
            $domainId
        );
    }

    /**
     * Create a domain redemption period notification (in-app)
     */
    public function notifyDomainRedemption(int $userId, string $domainName, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_redemption',
            'Domain in Redemption Period',
            "{$domainName} has entered the redemption period - recovery fees may apply",
            $domainId
        );
    }

    /**
     * Create a domain pending delete notification (in-app)
     */
    public function notifyDomainPendingDelete(int $userId, string $domainName, int $domainId): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_pending_delete',
            'Domain Pending Deletion',
            "{$domainName} is scheduled for deletion and will be available soon",
            $domainId
        );
    }

    /**
     * Create a domain WHOIS updated notification (in-app)
     */
    public function notifyDomainUpdated(int $userId, string $domainName, int $domainId, string $changes = ''): void
    {
        $notificationModel = new \App\Models\Notification();
        $message = !empty($changes) ? 
            "{$domainName} - {$changes}" : 
            "{$domainName} WHOIS data updated";
            
        $notificationModel->createNotification(
            $userId,
            'domain_updated',
            'Domain WHOIS Updated',
            $message,
            $domainId
        );
    }

    /**
     * Create a WHOIS lookup failed notification (in-app)
     */
    public function notifyWhoisFailed(int $userId, string $domainName, int $domainId, string $reason = ''): void
    {
        $notificationModel = new \App\Models\Notification();
        $message = !empty($reason) ? 
            "Could not refresh {$domainName} - {$reason}" : 
            "Could not refresh {$domainName}";
            
        $notificationModel->createNotification(
            $userId,
            'whois_failed',
            'WHOIS Lookup Failed',
            $message,
            $domainId
        );
    }

    /**
     * Create a new login notification (in-app) with rich geolocation data
     */
    public function notifyNewLogin(int $userId, string $method, string $ipAddress, ?string $userAgent = null): void
    {
        // Get geolocation data
        $geo = \App\Models\SessionManager::getGeolocationData($ipAddress);
        
        // Parse browser/device from user agent
        $browser = 'Unknown Browser';
        $device = 'Desktop';
        $deviceIcon = 'desktop';
        
        if ($userAgent) {
            $ua = strtolower($userAgent);
            
            // Browser detection
            if (strpos($ua, 'edg') !== false) {
                $browser = 'Edge';
            } elseif (strpos($ua, 'opr') !== false || strpos($ua, 'opera') !== false) {
                $browser = 'Opera';
            } elseif (strpos($ua, 'chrome') !== false) {
                $browser = 'Chrome';
            } elseif (strpos($ua, 'safari') !== false) {
                $browser = 'Safari';
            } elseif (strpos($ua, 'firefox') !== false) {
                $browser = 'Firefox';
            }
            
            // Device detection
            if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
                $device = 'Mobile';
                $deviceIcon = 'mobile-alt';
            } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
                $device = 'Tablet';
                $deviceIcon = 'tablet-alt';
            }
            
            // OS detection
            $os = 'Unknown';
            if (strpos($ua, 'windows') !== false) $os = 'Windows';
            elseif (strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os') !== false) $os = 'macOS';
            elseif (strpos($ua, 'linux') !== false) $os = 'Linux';
            elseif (strpos($ua, 'android') !== false) $os = 'Android';
            elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $os = 'iOS';
        }

        // Build location string
        $locationParts = [];
        if ($geo['city'] !== 'Unknown' && $geo['city'] !== 'Local') {
            $locationParts[] = $geo['city'];
        }
        if ($geo['country'] !== 'Unknown' && $geo['country'] !== 'Local') {
            $locationParts[] = $geo['country'];
        }
        $locationStr = !empty($locationParts) ? implode(', ', $locationParts) : 'Unknown location';

        // Store rich data as JSON in message field
        $messageData = json_encode([
            'method' => $method,
            'ip' => $ipAddress,
            'country' => $geo['country'],
            'country_code' => $geo['country_code'],
            'city' => $geo['city'],
            'region' => $geo['region'],
            'isp' => $geo['isp'],
            'browser' => $browser,
            'device' => $device,
            'device_icon' => $deviceIcon,
            'os' => $os ?? 'Unknown',
            'location' => $locationStr,
        ]);

        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'session_new',
            'New Login Detected',
            $messageData,
            null
        );
    }

    /**
     * Create a failed login notification (in-app) with rich geolocation data
     */
    public function notifyFailedLogin(int $userId, string $reason, string $ipAddress, ?string $userAgent = null, ?string $attemptedUsername = null): void
    {
        // Get geolocation data
        $geo = \App\Models\SessionManager::getGeolocationData($ipAddress);
        
        // Parse browser/device from user agent
        $browser = 'Unknown Browser';
        $device = 'Desktop';
        $deviceIcon = 'desktop';
        
        if ($userAgent) {
            $ua = strtolower($userAgent);
            
            // Browser detection
            if (strpos($ua, 'edg') !== false) {
                $browser = 'Edge';
            } elseif (strpos($ua, 'opr') !== false || strpos($ua, 'opera') !== false) {
                $browser = 'Opera';
            } elseif (strpos($ua, 'chrome') !== false) {
                $browser = 'Chrome';
            } elseif (strpos($ua, 'safari') !== false) {
                $browser = 'Safari';
            } elseif (strpos($ua, 'firefox') !== false) {
                $browser = 'Firefox';
            }
            
            // Device detection
            if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
                $device = 'Mobile';
                $deviceIcon = 'mobile-alt';
            } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
                $device = 'Tablet';
                $deviceIcon = 'tablet-alt';
            }
            
            // OS detection
            $os = 'Unknown';
            if (strpos($ua, 'windows') !== false) $os = 'Windows';
            elseif (strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os') !== false) $os = 'macOS';
            elseif (strpos($ua, 'linux') !== false) $os = 'Linux';
            elseif (strpos($ua, 'android') !== false) $os = 'Android';
            elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $os = 'iOS';
        }

        // Build location string
        $locationParts = [];
        if ($geo['city'] !== 'Unknown' && $geo['city'] !== 'Local') {
            $locationParts[] = $geo['city'];
        }
        if ($geo['country'] !== 'Unknown' && $geo['country'] !== 'Local') {
            $locationParts[] = $geo['country'];
        }
        $locationStr = !empty($locationParts) ? implode(', ', $locationParts) : 'Unknown location';

        // Store rich data as JSON in message field
        $messageData = json_encode([
            'reason' => $reason,
            'attempted_username' => $attemptedUsername,
            'ip' => $ipAddress,
            'country' => $geo['country'],
            'country_code' => $geo['country_code'],
            'city' => $geo['city'],
            'region' => $geo['region'],
            'isp' => $geo['isp'],
            'browser' => $browser,
            'device' => $device,
            'device_icon' => $deviceIcon,
            'os' => $os ?? 'Unknown',
            'location' => $locationStr,
        ]);

        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'session_failed',
            'Failed Login Attempt',
            $messageData,
            null
        );
    }

    // Future improvement: Add notifyAdminsFailedLogin() to send in-app alerts to all admins on failed login attempts (e.g. unknown usernames, brute-force detection)

    /**
     * Create welcome notification for new users/fresh install (in-app)
     */
    public function notifyWelcome(int $userId, string $username): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'system_welcome',
            'Welcome to Domain Monitor! 🎉',
            "Hi {$username}! Your account is ready. Start by adding your first domain to monitor.",
            null
        );
    }

    /**
     * Create system upgrade notification for admins (in-app)
     */
    public function notifySystemUpgrade(int $userId, string $fromVersion, string $toVersion, int $migrationsCount): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'system_upgrade',
            'System Upgraded Successfully',
            "Domain Monitor upgraded from v{$fromVersion} to v{$toVersion} ({$migrationsCount} migration" . ($migrationsCount > 1 ? 's' : '') . " applied)",
            null
        );
    }

    /**
     * Notify all admins about system upgrade (in-app)
     */
    public function notifyAdminsUpgrade(string $fromVersion, string $toVersion, int $migrationsCount): void
    {
        try {
            $userModel = new \App\Models\User();
            $admins = $userModel->getAllAdmins();
            
            foreach ($admins as $admin) {
                $this->notifySystemUpgrade($admin['id'], $fromVersion, $toVersion, $migrationsCount);
            }
        } catch (\Exception $e) {
            $logger = new \App\Services\Logger();
            $logger->error("Failed to notify admins about upgrade", [
                'error' => $e->getMessage()
            ]);
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
            $logger = new \App\Services\Logger();
            $logger->error("Failed to clean old notifications", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
