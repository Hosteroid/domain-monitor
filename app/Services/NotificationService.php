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
     * Create a new login notification (in-app)
     */
    public function notifyNewLogin(int $userId, string $location, string $ipAddress): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'session_new',
            'New Login Detected',
            "Login from {$location} ({$ipAddress})",
            null
        );
    }

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
