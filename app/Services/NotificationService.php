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
    /** The status change and the expiry-day alert both say that the domain expired */
    private const EXPIRED_ALERT_TYPES = ['expired', 'domain_expired_status'];
    /** Days after the expiration date that "expired" still goes out when no check got to the domain on the day */
    private const EXPIRED_CATCH_UP_DAYS = 7;

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
     * Expiry alert due today for a domain, or null: a reminder on the reminder days before it
     * expires, "expired" on the day it expires, and a reminder on the days after expiry from
     * Settings (an expired domain no longer alerts every day). A watchlist group only gets the
     * reminders after expiry, since the owner can still renew it until then (#95).
     *
     * @param int   $daysLeft       DomainHelper::daysUntilExpiration(): 0 on the day it expires
     * @param int[] $reminderDays   Setting::getNotificationDays()
     * @param int[] $postExpiryDays Setting::getPostExpiryNotificationDays()
     * @return string|null Notification type: expiring_in_N_days, expired or expired_N_days_ago
     */
    public static function getExpiryAlertType(int $daysLeft, array $reminderDays, array $postExpiryDays, bool $watchlist): ?string
    {
        if ($daysLeft > 0) {
            return !$watchlist && in_array($daysLeft, $reminderDays, true) ? "expiring_in_{$daysLeft}_days" : null;
        }

        if ($daysLeft === 0) {
            return $watchlist ? null : 'expired';
        }

        $daysAgo = -$daysLeft;
        return in_array($daysAgo, $postExpiryDays, true) ? "expired_{$daysAgo}_days_ago" : null;
    }

    /**
     * Send the expiry alert due today for a domain (getExpiryAlertType()) to the group's channels
     * and in-app, once a day at most. "Expired" goes out once per expiration: on the day, or on
     * the first check in the week after it when no check got to the domain that day. Nothing is
     * sent when the group turned expiration alerts off, or for a domain WHOIS reports as free,
     * in redemption or pending delete: its status alerts cover that, and its saved date may be
     * one WHOIS no longer has. Uses the saved expiration date, which is the manual one when
     * WHOIS has none.
     *
     * @param array $domain       Domain row after the WHOIS refresh
     * @param bool  $lookupFailed WHOIS couldn't be checked, so the saved date is used as it was
     * @return array{type: ?string, days_left: ?int, skipped: ?string, already_sent: bool, channels: array, users: int}
     *         Alert type due (null for none), days left, why nothing was sent, whether the channels
     *         already had it in the last 23 hours, channel results, users notified in-app
     */
    public function alertDomainExpiry(array $domain, bool $lookupFailed = false): array
    {
        $daysLeft = \App\Helpers\DomainHelper::daysUntilExpiration($domain['expiration_date'] ?? null);
        $result = ['type' => null, 'days_left' => $daysLeft, 'skipped' => null, 'already_sent' => false, 'channels' => [], 'users' => 0];

        if ($daysLeft === null) {
            return $result;
        }

        $status = (new WhoisService())->getLastKnownStatus($domain);
        if (in_array($status, ['available', 'redemption_period', 'pending_delete'], true)) {
            $result['skipped'] = 'No expiry reminders for a domain that is ' . match ($status) {
                'available' => 'available',
                'redemption_period' => 'in its redemption period',
                default => 'pending delete',
            };
            return $result;
        }

        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        $groupModel = new \App\Models\NotificationGroup();
        $watchlist = $groupModel->isWatchlist($groupId);
        $settingModel = new \App\Models\Setting();

        $type = self::getExpiryAlertType(
            $daysLeft,
            $settingModel->getNotificationDays(),
            $settingModel->getPostExpiryNotificationDays(),
            $watchlist
        );
        if ($type === null && !$watchlist && $daysLeft < 0 && $daysLeft >= -self::EXPIRED_CATCH_UP_DAYS
            && !$this->wasExpiryAnnounced($domain)) {
            $type = 'expired';
        }
        if ($type === null) {
            return $result;
        }
        $result['type'] = $type;

        if (!$groupModel->allowsAlert($groupId, 'expiration')) {
            $result['skipped'] = "Expiration alerts are off for this domain's notification group";
            return $result;
        }

        $daysAgo = -$daysLeft;
        if ($groupId) {
            $sent = $this->sendToDomainGroup(
                $domain,
                $type,
                match (true) {
                    $daysLeft < 0 => "Domain {$domain['domain_name']} expired {$daysAgo} days ago",
                    $daysLeft === 0 => "Domain {$domain['domain_name']} expired",
                    default => "Domain {$domain['domain_name']} expires in {$daysLeft} days",
                },
                fn(array $channels): array => $this->sendDomainExpirationAlert($domain, $channels, $watchlist, $lookupFailed)
            );
            $result['already_sent'] = $sent === null;
            $result['channels'] = $sent ?? [];
        }

        $result['users'] = $this->notifyDomainUsers(
            $domain,
            $daysLeft <= 0 ? 'domain_expired' : 'domain_expiring',
            fn(int $userId) => match (true) {
                $daysLeft < 0 => $this->notifyDomainExpiredDaysAgo($userId, $domain['domain_name'], $domain['id'], $daysAgo, $watchlist),
                $daysLeft === 0 => $this->notifyDomainExpired($userId, $domain['domain_name'], $domain['id']),
                default => $this->notifyDomainExpiring($userId, $domain['domain_name'], $daysLeft, $domain['id']),
            }
        );

        return $result;
    }

    /**
     * Whether the domain's channels or users were already told that it expired, since the day
     * before its expiration date (the status change and the expiry-day alert both count)
     */
    private function wasExpiryAnnounced(array $domain): bool
    {
        $since = date('Y-m-d', strtotime($domain['expiration_date'] . ' -1 day'));

        return (new \App\Models\NotificationLog())->wasSentSince((int)$domain['id'], self::EXPIRED_ALERT_TYPES, $since)
            || (new \App\Models\Notification())->wasCreatedForDomainSince((int)$domain['id'], 'domain_expired', $since);
    }

    /**
     * Send domain expiration notification: a reminder before it expires, "expired", or a reminder
     * after it expired, worded for buying the domain when it's on a watchlist
     *
     * @param bool $lookupFailed WHOIS couldn't be checked, so the reader is told the date may be out of date
     */
    public function sendDomainExpirationAlert(array $domain, array $notificationChannels, bool $watchlist = false, bool $lookupFailed = false): array
    {
        $daysLeft = $this->calculateDaysLeft($domain['expiration_date']);
        $message = $this->formatExpirationMessage($domain, $daysLeft, $watchlist);
        if ($lookupFailed) {
            $message .= "\n\nWHOIS couldn't be checked today, so this is based on the saved expiration date.";
        }
        $daysAgo = -$daysLeft;
        $plural = $daysAgo === 1 ? '' : 's';
        $subject = match (true) {
            $daysLeft < 0 && $watchlist => "Domain Lifecycle Update: {$domain['domain_name']}",
            $daysLeft < 0 => "⚠️ Domain Expired {$daysAgo} Day{$plural} Ago: {$domain['domain_name']}",
            // Same subject the email already gets, so chat and Pushover titles match it
            default => \App\Helpers\EmailHelper::getEmailSubject(['domain' => $domain['domain_name'], 'days_left' => $daysLeft]),
        };

        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'subject' => $subject,
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'days_left' => $daysLeft,
                    'expiration_date' => $domain['expiration_date'],
                    'registrar' => $domain['registrar'],
                    // A lifecycle update about a domain someone wants to buy, not an urgent expiry
                    'watchlist' => $watchlist
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
     * Send domain status change notification via external channels, worded for buying the
     * domain when it's on a watchlist
     */
    public function sendDomainStatusAlert(array $domain, array $notificationChannels, string $newStatus, string $oldStatus, bool $watchlist = false): array
    {
        $message = $this->formatStatusChangeMessage($domain, $newStatus, $oldStatus, $watchlist);

        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'subject' => $this->getStatusAlertTitle($newStatus, $watchlist) . ': ' . $domain['domain_name'],
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'new_status' => $newStatus,
                    'status_label' => self::getStatusLabel($newStatus),
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
    private function formatStatusChangeMessage(array $domain, string $newStatus, string $oldStatus, bool $watchlist = false): string
    {
        $domainName = $domain['domain_name'];
        $registrar = $domain['registrar'] ?? 'Unknown';
        $oldStatusLabel = self::getStatusLabel($oldStatus);
        $newStatusLabel = self::getStatusLabel($newStatus);

        // For someone who wants to buy the domain, deletion is the opportunity, not a loss
        if ($watchlist && $newStatus === 'redemption_period') {
            return "⏳ APPROACHING DELETION: Domain '$domainName' has entered the redemption period.\n\n" .
                   "Previous status: $oldStatusLabel\n" .
                   "The owner can still restore it. If not, it goes to pending delete and is released in about a month. " .
                   "Consider placing a backorder.";
        }
        if ($watchlist && $newStatus === 'pending_delete') {
            return "🔴 PENDING DELETE: Domain '$domainName' is scheduled for deletion.\n\n" .
                   "Previous status: $oldStatusLabel\n" .
                   "It can no longer be restored and is released for registration in about 5 days. " .
                   "This is the time for a backorder or drop-catch.";
        }

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
     * Title for a domain status alert, worded like the in-app notification
     */
    private function getStatusAlertTitle(string $status, bool $watchlist = false): string
    {
        return match($status) {
            'available' => 'Domain Available',
            'active' => 'Domain Registered',
            'expired' => 'Domain Expired',
            'redemption_period' => $watchlist ? 'Domain Approaching Deletion' : 'Domain in Redemption Period',
            'pending_delete' => 'Domain Pending Deletion',
            default => 'Domain Status Changed'
        };
    }

    /**
     * Send domain renewal notification via external channels, worded for buying the domain when
     * it's on a watchlist
     *
     * @param array $renewal From WhoisService::detectRenewal()
     */
    public function sendDomainRenewalAlert(array $domain, array $notificationChannels, array $renewal, bool $watchlist = false): array
    {
        $message = $this->formatRenewalMessage($domain, $renewal, $watchlist);

        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'subject' => 'Domain Renewed: ' . $domain['domain_name'],
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'days_left' => $this->calculateDaysLeft($renewal['new_expiration']),
                    'expiration_date' => $renewal['new_expiration'],
                    'previous_expiration_date' => $renewal['old_expiration'],
                    'auto_renew_period' => $renewal['auto_renew_period'],
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
     * Send the alerts for a status change found by a WHOIS refresh: the group's channels and in-app.
     * Settings choose which status changes send alerts, and a group can turn status alerts off.
     *
     * @param array $domain Domain row after the refresh
     * @param bool $renewed The refresh also found a renewal, which is alerted instead of "registered"
     * @return array{type: ?string, skipped: ?string, already_sent: bool, channels: array, users: int}
     *         Alert type sent (null when the change doesn't alert), why nothing was sent, whether
     *         the channels already had it in the last 23 hours, channel results, users notified in-app
     */
    public function alertDomainStatusChange(array $domain, string $oldStatus, string $newStatus, bool $renewed = false): array
    {
        $result = ['type' => null, 'skipped' => null, 'already_sent' => false, 'channels' => [], 'users' => 0];

        $triggers = (new \App\Models\Setting())->getNotificationStatusTriggers();
        $type = $this->getStatusChangeAlertType($oldStatus, $newStatus, $triggers, $renewed);
        if ($type === null) {
            return $result;
        }
        $result['type'] = $type;

        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        $groupModel = new \App\Models\NotificationGroup();
        if (!$groupModel->allowsAlert($groupId, 'status')) {
            $result['skipped'] = "Status alerts are off for this domain's notification group";
            return $result;
        }

        // Expiring starts a process that may end in a renewal, so for a watchlist it isn't news
        // yet: approaching deletion, pending delete and becoming available are (#95)
        $watchlist = $groupModel->isWatchlist($groupId);
        if ($watchlist && $type === 'domain_expired_status') {
            $result['skipped'] = 'Watchlist groups get no alert when a domain expires';
            return $result;
        }

        if ($groupId) {
            $sent = $this->sendToDomainGroup(
                $domain,
                $type,
                "Domain {$domain['domain_name']} status changed: $oldStatus → $newStatus",
                fn(array $channels): array => $this->sendDomainStatusAlert($domain, $channels, $newStatus, $oldStatus, $watchlist)
            );
            $result['already_sent'] = $sent === null;
            $result['channels'] = $sent ?? [];
        }

        // notifyDomainExpired() is shared with expiry reminders, so its notifications are "domain_expired"
        $inAppType = $type === 'domain_expired_status' ? 'domain_expired' : $type;
        $result['users'] = $this->notifyDomainUsers($domain, $inAppType, fn(int $userId) => match ($type) {
            'domain_available' => $this->notifyDomainAvailable($userId, $domain['domain_name'], $domain['id']),
            'domain_registered' => $this->notifyDomainRegistered($userId, $domain['domain_name'], $domain['id']),
            'domain_expired_status' => $this->notifyDomainExpired($userId, $domain['domain_name'], $domain['id']),
            'domain_redemption' => $this->notifyDomainRedemption($userId, $domain['domain_name'], $domain['id'], $watchlist),
            'domain_pending_delete' => $this->notifyDomainPendingDelete($userId, $domain['domain_name'], $domain['id'], $watchlist),
        });

        return $result;
    }

    /**
     * Alert type for a status change, or null when the change doesn't alert or its trigger is off
     *
     * @param string[] $triggers Setting::getNotificationStatusTriggers()
     */
    private function getStatusChangeAlertType(string $oldStatus, string $newStatus, array $triggers, bool $renewed): ?string
    {
        if ($oldStatus === $newStatus) {
            return null;
        }

        // 'registered' fires when the status changes TO 'active' FROM certain statuses,
        // unless the domain was renewed (that gets its own alert)
        return match (true) {
            $newStatus === 'available' && in_array('available', $triggers) => 'domain_available',
            $newStatus === 'active' && !$renewed
                && in_array($oldStatus, ['available', 'expired', 'pending_delete', 'redemption_period', 'error'])
                && in_array('registered', $triggers) => 'domain_registered',
            $newStatus === 'expired' && $oldStatus !== 'error' && in_array('expired', $triggers) => 'domain_expired_status',
            $newStatus === 'redemption_period' && in_array('redemption_period', $triggers) => 'domain_redemption',
            $newStatus === 'pending_delete' && in_array('pending_delete', $triggers) => 'domain_pending_delete',
            default => null,
        };
    }

    /**
     * Send the alerts for a renewal found by a WHOIS refresh: the group's channels and in-app.
     * Nothing is sent when "Renewed" is off in Settings or the group turned status alerts off.
     *
     * @param array $domain Domain row after the refresh
     * @param array $renewal From WhoisService::detectRenewal()
     * @return array{skipped: ?string, already_sent: bool, channels: array, users: int}
     *         Why nothing was sent, whether the channels already had it in the last 23 hours,
     *         channel results, users notified in-app
     */
    public function alertDomainRenewal(array $domain, array $renewal): array
    {
        $result = ['skipped' => null, 'already_sent' => false, 'channels' => [], 'users' => 0];

        if (!in_array('renewed', (new \App\Models\Setting())->getNotificationStatusTriggers(), true)) {
            $result['skipped'] = 'Renewal alerts are off (Settings → Monitoring)';
            return $result;
        }

        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        $groupModel = new \App\Models\NotificationGroup();
        if (!$groupModel->allowsAlert($groupId, 'status')) {
            $result['skipped'] = "Status alerts are off for this domain's notification group";
            return $result;
        }
        $watchlist = $groupModel->isWatchlist($groupId);

        if ($groupId) {
            $sent = $this->sendToDomainGroup(
                $domain,
                'domain_renewed',
                "Domain {$domain['domain_name']} renewed until {$renewal['new_expiration']}",
                fn(array $channels): array => $this->sendDomainRenewalAlert($domain, $channels, $renewal, $watchlist)
            );
            $result['already_sent'] = $sent === null;
            $result['channels'] = $sent ?? [];
        }

        $result['users'] = $this->notifyDomainUsers($domain, 'domain_renewed', fn(int $userId) => $this->notifyDomainRenewed(
            $userId,
            $domain['domain_name'],
            $domain['id'],
            $renewal['new_expiration'],
            $renewal['auto_renew_period'],
            $watchlist
        ));

        return $result;
    }

    /**
     * Send an alert to the active channels of the domain's notification group and log each attempt.
     * Each alert type goes out once in $hours at most, in case the cron and a manual refresh both
     * see the same change (0 sends every time). The two alerts that say a domain expired count as
     * one, like their in-app notification.
     *
     * @param callable(array): array $send Sends to the given channels and returns the results
     * @return array|null Channel results, or null when it was already sent in the last $hours
     */
    private function sendToDomainGroup(array $domain, string $type, string $logMessage, callable $send, int $hours = 23): ?array
    {
        $logModel = new \App\Models\NotificationLog();
        $sameAlertTypes = in_array($type, self::EXPIRED_ALERT_TYPES, true) ? self::EXPIRED_ALERT_TYPES : [$type];
        if ($hours > 0 && $logModel->wasSentRecently($domain['id'], $sameAlertTypes, $hours)) {
            return null;
        }

        $channelModel = new \App\Models\NotificationChannel();
        $results = $send($channelModel->getActiveByGroupId((int)$domain['notification_group_id']));

        foreach ($results as $sent) {
            $logModel->log(
                $domain['id'],
                $type,
                $sent['channel'],
                $logMessage,
                $sent['success'],
                $sent['success'] ? null : 'Failed to send notification'
            );
        }

        return $results;
    }

    /**
     * Create an in-app notification for each user who gets the domain's alerts, skipping users
     * who already got this type for the domain in the last $hours (0 notifies every time)
     *
     * @param callable(int): void $notify Creates the notification for one user
     * @return int Users notified
     */
    private function notifyDomainUsers(array $domain, string $type, callable $notify, int $hours = 23): int
    {
        $notificationModel = new \App\Models\Notification();
        $notified = 0;

        foreach ($this->getInAppRecipientIds($domain) as $userId) {
            if ($hours > 0 && $notificationModel->wasCreatedRecently($userId, $domain['id'], $type, $hours)) {
                continue;
            }

            try {
                $notify($userId);
                $notified++;
            } catch (\Exception $e) {
                $logger = new \App\Services\Logger();
                $logger->error("Failed to create in-app notification", [
                    'type' => $type,
                    'user_id' => $userId,
                    'domain_id' => $domain['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $notified;
    }

    /**
     * Format renewal notification message
     */
    private function formatRenewalMessage(array $domain, array $renewal, bool $watchlist = false): string
    {
        $domainName = $domain['domain_name'];
        $registrar = $domain['registrar'] ?? 'Unknown';
        $newDate = date('F j, Y', strtotime($renewal['new_expiration']));
        $oldDate = date('F j, Y', strtotime($renewal['old_expiration']));

        $message = $watchlist
            ? "🔄 RENEWED: Domain '$domainName' was renewed until $newDate, so it won't be released this time.\n\n"
            : "✅ RENEWED: Domain '$domainName' has been renewed until $newDate.\n\n";
        $message .= "Previous expiration: $oldDate\n" .
                    "Registrar: $registrar";

        if ($renewal['auto_renew_period']) {
            $message .= "\n\nThe registry renewed it automatically when it expired. " .
                        "If the renewal isn't paid, the registrar can still delete the domain.";
        }

        return $message;
    }

    /**
     * Users who get a domain's in-app alerts: its owner (or its group's owner) when users
     * are isolated, otherwise every active user
     *
     * @return int[]
     */
    private function getInAppRecipientIds(array $domain): array
    {
        if ((new \App\Models\Setting())->getValue('user_isolation_mode', 'shared') !== 'isolated') {
            return array_map('intval', array_column((new \App\Models\User())->where('is_active', 1), 'id'));
        }

        $userId = $domain['user_id'] ?? null;
        if (!$userId && !empty($domain['notification_group_id'])) {
            $group = (new \App\Models\NotificationGroup())->find((int)$domain['notification_group_id']);
            $userId = $group['user_id'] ?? null;
        }

        return $userId ? [(int)$userId] : [];
    }

    /**
     * Format expiration message
     */
    private function formatExpirationMessage(array $domain, int $daysLeft, bool $watchlist = false): string
    {
        $domainName = $domain['domain_name'];
        $expirationDate = $domain['expiration_date'] ? date('F j, Y', strtotime($domain['expiration_date'])) : 'Unknown';
        $registrar = $domain['registrar'] ?? 'Unknown';

        if ($daysLeft < 0) {
            $daysAgo = -$daysLeft . ' day' . ($daysLeft === -1 ? '' : 's');

            if ($watchlist) {
                return "📅 LIFECYCLE UPDATE: Domain '$domainName' expired $daysAgo ago ($expirationDate) and is not available for registration yet.\n\n" .
                       "Registrar: $registrar\n" .
                       "The owner can still renew it. If not, it goes through the redemption period and pending delete before it is released.";
            }

            return "⚠️ EXPIRED " . strtoupper($daysAgo) . " AGO: Domain '$domainName' expired on $expirationDate and has not been renewed.\n\n" .
                   "Registrar: $registrar\n" .
                   "Renew it before it enters the redemption period, when restoring it costs extra.";
        }

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
     * Calculate days left until expiration (calendar days, 0 on the day it expires)
     */
    private function calculateDaysLeft(string $expirationDate): int
    {
        return \App\Helpers\DomainHelper::daysUntilExpiration($expirationDate) ?? 0;
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
     * Create a reminder that a domain expired some days ago (in-app), worded for buying the
     * domain when it's on a watchlist
     */
    public function notifyDomainExpiredDaysAgo(int $userId, string $domainName, int $domainId, int $daysAgo, bool $watchlist = false): void
    {
        $days = $daysAgo . ' day' . ($daysAgo === 1 ? '' : 's');
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_expired',
            $watchlist ? 'Domain Lifecycle Update' : 'Domain Expired',
            $watchlist
                ? "{$domainName} expired {$days} ago and is not available yet"
                : "{$domainName} expired {$days} ago - renew it before the redemption period",
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
     * Create a domain renewed notification (in-app)
     * Triggered when a WHOIS refresh finds a later expiration date for the same registration
     */
    public function notifyDomainRenewed(int $userId, string $domainName, int $domainId, string $expirationDate, bool $autoRenewPeriod = false, bool $watchlist = false): void
    {
        $until = date('F j, Y', strtotime($expirationDate));
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_renewed',
            'Domain Renewed',
            $watchlist
                ? "{$domainName} was renewed until {$until} - it won't be released this time"
                : "{$domainName} has been renewed until {$until}" .
                    ($autoRenewPeriod ? " - renewed by the registry at expiry, check that it's paid" : ''),
            $domainId
        );
    }

    /**
     * Create a domain redemption period notification (in-app)
     */
    public function notifyDomainRedemption(int $userId, string $domainName, int $domainId, bool $watchlist = false): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_redemption',
            $watchlist ? 'Domain Approaching Deletion' : 'Domain in Redemption Period',
            $watchlist
                ? "{$domainName} has entered the redemption period - consider placing a backorder"
                : "{$domainName} has entered the redemption period - recovery fees may apply",
            $domainId
        );
    }

    /**
     * Create a domain pending delete notification (in-app)
     */
    public function notifyDomainPendingDelete(int $userId, string $domainName, int $domainId, bool $watchlist = false): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'domain_pending_delete',
            'Domain Pending Deletion',
            $watchlist
                ? "{$domainName} is released in about 5 days - time for a backorder or drop-catch"
                : "{$domainName} is scheduled for deletion and will be available soon",
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
     * @param bool $composerManualRequired If true, appends a note to run composer install manually (e.g. when exec is disabled on cPanel)
     */
    public function notifySystemUpgrade(int $userId, string $fromVersion, string $toVersion, int $migrationsCount, bool $composerManualRequired = false): void
    {
        $migrationLabel = $migrationsCount . ' migration' . ($migrationsCount !== 1 ? 's' : '') . ' applied';

        // Detect if $toVersion is a commit SHA (7-40 hex chars) rather than a semver string
        $isCommitSha = (bool) preg_match('/^[a-f0-9]{7,40}$/i', $toVersion);

        if ($isCommitSha) {
            // Hotfix: file-only update identified by commit SHA
            $message = "Domain Monitor v{$fromVersion} has been updated (hotfix {$toVersion}, {$migrationLabel})";
        } elseif ($fromVersion === $toVersion) {
            // Hotfix: same version, just file updates
            $message = "Domain Monitor v{$toVersion} has been updated ({$migrationLabel})";
        } else {
            $message = "Domain Monitor upgraded from v{$fromVersion} to v{$toVersion} ({$migrationLabel})";
        }
        if ($composerManualRequired) {
            $message .= ". Composer could not be run here (e.g. exec disabled). If dependencies changed, run \"composer install --no-dev\" manually via SSH or Terminal.";
        }
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'system_upgrade',
            'System Upgraded Successfully',
            $message,
            null
        );
    }

    /**
     * Notify all admins about system upgrade (in-app)
     * @param bool $composerManualRequired If true, in-app message will include a note to run composer install manually
     */
    public function notifyAdminsUpgrade(string $fromVersion, string $toVersion, int $migrationsCount, bool $composerManualRequired = false): void
    {
        try {
            $userModel = new \App\Models\User();
            $admins = $userModel->getAllAdmins();
            
            foreach ($admins as $admin) {
                $this->notifySystemUpgrade($admin['id'], $fromVersion, $toVersion, $migrationsCount, $composerManualRequired);
            }
        } catch (\Exception $e) {
            $logger = new \App\Services\Logger();
            $logger->error("Failed to notify admins about upgrade", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create "update available" in-app notification for one user
     */
    public function notifyUpdateAvailable(int $userId, string $currentVersion, string $latestVersion, string $type = 'release', ?int $commitsBehind = null): void
    {
        $notificationModel = new \App\Models\Notification();
        $title = 'Update Available';
        if ($type === 'release') {
            $message = "A new version of Domain Monitor is available: v{$latestVersion} (you have v{$currentVersion}). Go to Settings → Updates to apply.";
        } else {
            $msg = $commitsBehind
                ? "{$commitsBehind} new commit(s) are available on the main branch. Go to Settings → Updates to apply the hotfix."
                : "New commits are available. Go to Settings → Updates to apply the hotfix.";
            $message = $msg;
        }
        $notificationModel->createNotification(
            $userId,
            'update_available',
            $title,
            $message,
            null
        );
    }

    /**
     * Notify all admins that an update is available (in-app)
     * Used by cron when it detects a new version or hotfix.
     */
    public function notifyAdminsUpdateAvailable(string $currentVersion, string $latestVersionOrLabel, string $type = 'release', ?int $commitsBehind = null): void
    {
        try {
            $userModel = new \App\Models\User();
            $admins = $userModel->getAllAdmins();
            foreach ($admins as $admin) {
                $this->notifyUpdateAvailable($admin['id'], $currentVersion, $latestVersionOrLabel, $type, $commitsBehind);
            }
        } catch (\Exception $e) {
            $logger = new \App\Services\Logger();
            $logger->error("Failed to notify admins about available update", [
                'error' => $e->getMessage()
            ]);
        }
    }

    // ========================================
    // DNS MONITORING NOTIFICATIONS
    // ========================================

    /**
     * Create a DNS change notification (in-app / bell icon)
     */
    public function notifyDnsChange(int $userId, string $domainName, int $domainId, string $summary): void
    {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'dns_change',
            'DNS Records Changed',
            "{$domainName} - {$summary}",
            $domainId
        );
    }

    /**
     * Send DNS change alert to external channels
     */
    public function sendDnsChangeAlert(array $domain, array $notificationChannels, string $detailMessage): array
    {
        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $detailMessage,
                [
                    'subject'   => "DNS Changes: {$domain['domain_name']}",
                    'domain'    => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                ]
            );

            $results[] = [
                'channel' => $channel['channel_type'],
                'success' => $success,
            ];
        }

        return $results;
    }

    /**
     * Send the alerts for DNS changes found by a DNS check: the group's channels and in-app, once
     * in 6 hours at most. Nothing is sent when the group turned DNS alerts off.
     *
     * @param string $summary DnsService::formatChangesSummary()
     * @param string $detail DnsService::formatChangesDetail()
     * @return array{skipped: ?string, already_sent: bool, channels: array, users: int}
     *         Why nothing was sent, whether the channels already had one in the last 6 hours,
     *         channel results, users notified in-app
     */
    public function alertDnsChanges(array $domain, string $summary, string $detail): array
    {
        $result = ['skipped' => null, 'already_sent' => false, 'channels' => [], 'users' => 0];

        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        if (!(new \App\Models\NotificationGroup())->allowsAlert($groupId, 'dns')) {
            $result['skipped'] = "DNS alerts are off for this domain's notification group";
            return $result;
        }

        if ($groupId) {
            $sent = $this->sendToDomainGroup(
                $domain,
                'dns_change',
                $summary,
                fn(array $channels): array => $this->sendDnsChangeAlert($domain, $channels, $detail),
                6
            );
            $result['already_sent'] = $sent === null;
            $result['channels'] = $sent ?? [];
        }

        $result['users'] = $this->notifyDomainUsers(
            $domain,
            'dns_change',
            fn(int $userId) => $this->notifyDnsChange($userId, $domain['domain_name'], $domain['id'], $summary),
            6
        );

        return $result;
    }

    /**
     * Create an SSL monitoring notification (in-app / bell icon).
     * Pass $daysLeft for an expiry reminder.
     */
    public function notifySslStatusChange(
        int $userId,
        string $domainName,
        string $hostname,
        int $domainId,
        string $newStatus,
        ?string $oldStatus = null,
        ?int $daysLeft = null
    ): void {
        $notificationModel = new \App\Models\Notification();
        $notificationModel->createNotification(
            $userId,
            'ssl_status_change',
            $this->getSslNotificationTitle($newStatus),
            $this->formatSslStatusSummary($domainName, $hostname, $newStatus, $oldStatus, $daysLeft),
            $domainId
        );
    }

    /**
     * Send SSL status alert to external channels.
     * Pass $daysLeft for an expiry reminder.
     */
    public function sendSslStatusAlert(
        array $domain,
        array $notificationChannels,
        string $hostname,
        string $newStatus,
        ?string $oldStatus = null,
        ?string $validTo = null,
        ?string $error = null,
        ?int $daysLeft = null
    ): array {
        $message = $this->formatSslStatusMessage($domain, $hostname, $newStatus, $oldStatus, $validTo, $error, $daysLeft);
        $results = [];

        foreach ($notificationChannels as $channel) {
            $config = json_decode($channel['channel_config'], true);
            $success = $this->send(
                $channel['channel_type'],
                $config,
                $message,
                [
                    'subject' => $this->getSslNotificationTitle($newStatus) . ': ' . $hostname,
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                    'hostname' => $hostname,
                    'new_status' => $newStatus,
                    'status_label' => self::getSslStatusLabel($newStatus),
                    'old_status' => $oldStatus,
                    'valid_to' => $validTo,
                    'error' => $error,
                ] + ($daysLeft !== null ? ['days_left' => $daysLeft] : [])
            );

            $results[] = [
                'channel' => $channel['channel_type'],
                'success' => $success,
            ];
        }

        return $results;
    }

    /**
     * Send the alerts for an SSL check (SslService::checkEndpoint()): its status change or the
     * expiry reminder that is due, to the group's channels and in-app. Nothing is sent when the
     * check has nothing to announce or the group turned SSL alerts off.
     *
     * @param string $endpoint Endpoint label (SslService::formatTargetLabel())
     * @return array{skipped: ?string, channels: array, users: int} Why nothing was sent, channel results, users notified in-app
     */
    public function alertSslCheck(array $domain, string $endpoint, array $check): array
    {
        $result = ['skipped' => null, 'channels' => [], 'users' => 0];

        if (!$check['status_alert'] && $check['reminder_days'] === null) {
            return $result;
        }

        $groupId = !empty($domain['notification_group_id']) ? (int)$domain['notification_group_id'] : null;
        if (!(new \App\Models\NotificationGroup())->allowsAlert($groupId, 'ssl')) {
            $result['skipped'] = "SSL alerts are off for this domain's notification group";
            return $result;
        }

        $snapshot = $check['snapshot'];
        $status = $snapshot['status'];
        $previousStatus = $check['status_alert'] ? $check['previous_status'] : null;
        $daysLeft = $check['reminder_days'] !== null ? $snapshot['days_remaining'] : null;

        // The saved status and reminder day already keep these from repeating, so no time limit
        if ($groupId) {
            $result['channels'] = $this->sendToDomainGroup(
                $domain,
                $daysLeft !== null ? 'ssl_expiry_reminder' : 'ssl_status_' . $status,
                $daysLeft !== null
                    ? "SSL certificate for {$endpoint} expires in {$daysLeft} day(s)"
                    : "SSL status for {$endpoint}: {$status}",
                fn(array $channels): array => $this->sendSslStatusAlert(
                    $domain,
                    $channels,
                    $endpoint,
                    $status,
                    $previousStatus,
                    $snapshot['valid_to'] ?? null,
                    $snapshot['last_error'] ?? null,
                    $daysLeft
                ),
                0
            ) ?? [];
        }

        $result['users'] = $this->notifyDomainUsers(
            $domain,
            'ssl_status_change',
            fn(int $userId) => $this->notifySslStatusChange($userId, $domain['domain_name'], $endpoint, $domain['id'], $status, $previousStatus, $daysLeft),
            0
        );

        return $result;
    }

    /**
     * Get SSL status label for human-readable messages.
     */
    public static function getSslStatusLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Valid',
            'expiring' => 'Expiring Soon',
            'expired' => 'Expired',
            'invalid' => 'Invalid',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function getSslNotificationTitle(string $status): string
    {
        return match ($status) {
            'valid' => 'SSL Certificate Recovered',
            'expiring' => 'SSL Certificate Expiring Soon',
            'expired' => 'SSL Certificate Expired',
            'invalid' => 'SSL Certificate Check Failed',
            default => 'SSL Status Changed',
        };
    }

    private function formatSslStatusSummary(
        string $domainName,
        string $hostname,
        string $newStatus,
        ?string $oldStatus = null,
        ?int $daysLeft = null
    ): string {
        $hostText = $hostname === $domainName ? $domainName : "{$hostname} ({$domainName})";
        $newLabel = self::getSslStatusLabel($newStatus);

        if ($newStatus === 'expiring' && $daysLeft !== null) {
            return "{$hostText} - SSL certificate expires " . $this->formatSslDaysLeft($daysLeft);
        }

        if ($oldStatus !== null) {
            $oldLabel = self::getSslStatusLabel($oldStatus);
            return "{$hostText} - SSL status changed from {$oldLabel} to {$newLabel}";
        }

        return "{$hostText} - SSL status is {$newLabel}";
    }

    private function formatSslStatusMessage(
        array $domain,
        string $hostname,
        string $newStatus,
        ?string $oldStatus = null,
        ?string $validTo = null,
        ?string $error = null,
        ?int $daysLeft = null
    ): string {
        $domainName = $domain['domain_name'];
        $validToText = $validTo ? date('F j, Y H:i', strtotime($validTo)) : 'Unknown';
        $oldLabel = $oldStatus !== null ? self::getSslStatusLabel($oldStatus) : null;

        if ($newStatus === 'expiring' && $daysLeft !== null) {
            return "⚠️ SSL EXPIRING SOON: the certificate for {$hostname} expires " . $this->formatSslDaysLeft($daysLeft) . ".\n\n" .
                   "Domain: {$domainName}\n" .
                   "Valid until: {$validToText}";
        }

        return match ($newStatus) {
            'valid' => "✅ SSL RECOVERED: {$hostname} is now using a valid certificate.\n\n" .
                       ($oldLabel ? "Previous status: {$oldLabel}\n" : '') .
                       "Domain: {$domainName}\n" .
                       "Valid until: {$validToText}",

            'expiring' => "⚠️ SSL EXPIRING SOON: {$hostname} is approaching certificate expiration.\n\n" .
                          ($oldLabel ? "Previous status: {$oldLabel}\n" : '') .
                          "Domain: {$domainName}\n" .
                          "Valid until: {$validToText}",

            'expired' => "🚨 SSL EXPIRED: {$hostname} has an expired certificate.\n\n" .
                         ($oldLabel ? "Previous status: {$oldLabel}\n" : '') .
                         "Domain: {$domainName}\n" .
                         "Expired on: {$validToText}",

            'invalid' => "❌ SSL INVALID: {$hostname} failed certificate validation.\n\n" .
                         ($oldLabel ? "Previous status: {$oldLabel}\n" : '') .
                         "Domain: {$domainName}\n" .
                         ($error ? "Error: {$error}\n" : ''),

            default => "ℹ️ SSL STATUS CHANGE: {$hostname} changed SSL status.\n\n" .
                       ($oldLabel ? "Previous status: {$oldLabel}\n" : '') .
                       "Current status: " . self::getSslStatusLabel($newStatus) . "\n" .
                       "Domain: {$domainName}"
        };
    }

    private function formatSslDaysLeft(int $daysLeft): string
    {
        if ($daysLeft < 1) {
            return 'within a day';
        }

        return "in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's');
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
