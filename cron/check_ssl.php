#!/usr/bin/env php
<?php

/**
 * SSL Certificate Monitoring Cron Job
 *
 * Checks tracked SSL endpoints for active domains with SSL monitoring enabled.
 * If no root endpoint is tracked yet, the root domain falls back to port 443.
 * Sends notifications when an SSL state changes or when the first monitored
 * baseline already has an issue.
 *
 * Usage: php cron/check_ssl.php
 * Recommended schedule: run at minute 0 every 12 hours.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\NotificationGroup;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\SslCertificate;
use App\Models\User;
use App\Services\Logger;
use App\Services\NotificationService;
use App\Services\SslService;
use App\Helpers\CronHelper;
use Core\Database;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
new Database();

$domainModel = new Domain();
$sslModel = new SslCertificate();
$channelModel = new NotificationChannel();
$groupModel = new NotificationGroup();
$logModel = new NotificationLog();
$settingModel = new Setting();
$userModel = new User();
$sslService = new SslService();
$notificationService = new NotificationService();
$logger = new Logger('ssl-cron');

try {
    $appSettings = $settingModel->getAppSettings();
    date_default_timezone_set($appSettings['app_timezone']);
} catch (\Exception $e) {
    date_default_timezone_set('UTC');
}

$logFile = __DIR__ . '/../logs/ssl_cron.log';
$cron = new CronHelper($logFile);
$startTime = microtime(true);

logMessage("=== Starting SSL check cron job ===");

// Only check domains that are registered and in use (active or expiring_soon).
// Skip available, expired, error, redemption_period, pending_delete — they typically have no DNS/SSL.
$checkableStatuses = ['active', 'expiring_soon'];

$allSslEnabled = array_values(array_filter(
    $domainModel->where('is_active', 1),
    static fn(array $d): bool => ($d['ssl_monitoring_enabled'] ?? 0) == 1
));
$domains = array_values(array_filter($allSslEnabled, static function (array $domain) use ($checkableStatuses): bool {
    $status = strtolower($domain['status'] ?? '');
    return in_array($status, $checkableStatuses, true);
}));
$skippedByStatus = count($allSslEnabled) - count($domains);
logMessage("Found " . count($domains) . " domain(s) with SSL monitoring enabled and checkable status (active/expiring_soon)");
if ($skippedByStatus > 0) {
    logMessage("Skipped " . $skippedByStatus . " domain(s) with non-checkable status (available/expired/error/redemption_period/pending_delete)");
}

$stats = [
    'checked_domains' => 0,
    'checked_hosts' => 0,
    'skipped_by_status' => $skippedByStatus,
    'skipped_unresolved' => 0,
    'issues_detected' => 0,
    'notifications_sent' => 0,
    'in_app_notifications' => 0,
    'errors' => 0,
    'status_changes' => 0,
];

$isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

foreach ($domains as $domain) {
    $domainName = strtolower($domain['domain_name']);
    $domainStart = microtime(true);
    logMessage("Checking SSL: {$domainName}");

    try {
        $targets = $sslModel->getDistinctTargets($domain['id']);
        $hasTrackedRootTarget = false;

        foreach ($targets as $target) {
            if ($target['hostname'] === $domainName) {
                $hasTrackedRootTarget = true;
                break;
            }
        }

        if (!$hasTrackedRootTarget) {
            $targets[] = [
                'hostname' => $domainName,
                'port' => 443,
            ];
        }

        usort($targets, static function (array $a, array $b): int {
            $hostnameCompare = strcasecmp($a['hostname'], $b['hostname']);
            if ($hostnameCompare !== 0) {
                return $hostnameCompare;
            }

            return ((int)$a['port']) <=> ((int)$b['port']);
        });

        $domainIssues = 0;
        $domainStatusChanges = 0;

        foreach ($targets as $target) {
            $hostname = $target['hostname'];
            $port = (int)($target['port'] ?? 443);
            $endpointLabel = $sslService->formatTargetLabel($hostname, $port);

            if (!CronHelper::hostnameResolves($hostname)) {
                logMessage("  {$endpointLabel}: skipped (hostname does not resolve)");
                $stats['skipped_unresolved']++;
                continue;
            }

            $existing = $sslModel->findByDomainAndHost($domain['id'], $hostname, $port);
            $previousStatus = $existing['status'] ?? null;

            $snapshot = $sslService->fetchCertificateSnapshot($hostname, $port);
            $sslModel->saveSnapshot($domain['id'], $hostname, $snapshot, $port);
            $stats['checked_hosts']++;

            $status = $snapshot['status'];
            $isIssue = in_array($status, ['expiring', 'expired', 'invalid'], true);
            if ($isIssue) {
                $domainIssues++;
                $stats['issues_detected']++;
            }

            $statusChanged = $previousStatus !== null && $previousStatus !== $status;
            $firstIssueBaseline = $previousStatus === null && $isIssue;

            logMessage(
                "  {$endpointLabel}: {$status}" .
                ($snapshot['valid_to'] ? " (valid_to: {$snapshot['valid_to']})" : '') .
                ($snapshot['last_error'] ? " (error: {$snapshot['last_error']})" : '')
            );

            if (!$statusChanged && !$firstIssueBaseline) {
                continue;
            }

            $domainStatusChanges++;
            $stats['status_changes']++;

            sendExternalSslNotifications(
                $domain,
                $endpointLabel,
                $status,
                $previousStatus,
                $snapshot,
                $channelModel,
                $logModel,
                $notificationService,
                $stats
            );

            sendInAppSslNotifications(
                $domain,
                $endpointLabel,
                $status,
                $previousStatus,
                $isolationMode,
                $userModel,
                $groupModel,
                $notificationService,
                $stats
            );
        }

        $domainModel->update($domain['id'], ['ssl_last_checked' => date('Y-m-d H:i:s')]);
        $stats['checked_domains']++;

        if ($domainStatusChanges === 0) {
            logMessage("  -> No SSL status changes detected");
        } else {
            logMessage("  -> {$domainStatusChanges} SSL status change(s) detected");
        }

        if ($domainIssues > 0) {
            logMessage("  -> {$domainIssues} issue host(s) currently detected");
        }

        logTimeSince($domainStart);
        usleep(250000);
    } catch (\Exception $e) {
        logMessage("  x Error: " . $e->getMessage());
        logTimeSince($domainStart);
        $logger->error('SSL check failed', [
            'domain' => $domainName,
            'error' => $e->getMessage(),
        ]);
        $stats['errors']++;
    }
}

$settingModel->setValue('last_ssl_check_run', date('Y-m-d H:i:s'));

logMessage("\n=== SSL cron job completed ===");
logMessage("Domains checked:        {$stats['checked_domains']}");
logMessage("Domains skipped:        {$stats['skipped_by_status']} (non-checkable status)");
logMessage("Endpoints skipped:      {$stats['skipped_unresolved']} (hostname does not resolve)");
logMessage("Endpoints checked:      {$stats['checked_hosts']}");
logMessage("Status changes:         {$stats['status_changes']}");
logMessage("Issue endpoints:        {$stats['issues_detected']}");
logMessage("External notifications: {$stats['notifications_sent']}");
logMessage("In-app notifications:   {$stats['in_app_notifications']}");
logMessage("Errors:                 {$stats['errors']}");
logMessage("Execution time:         " . CronHelper::formatElapsedTime(microtime(true) - $startTime));
logMessage("============================\n");

exit(0);

function sendExternalSslNotifications(
    array $domain,
    string $hostname,
    string $status,
    ?string $previousStatus,
    array $snapshot,
    NotificationChannel $channelModel,
    NotificationLog $logModel,
    NotificationService $notificationService,
    array &$stats
): void {
    if (empty($domain['notification_group_id'])) {
        return;
    }

    $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);
    if (empty($channels)) {
        return;
    }

    logMessage("  -> Sending SSL alerts to " . count($channels) . " channel(s)");

    $results = $notificationService->sendSslStatusAlert(
        $domain,
        $channels,
        $hostname,
        $status,
        $previousStatus,
        $snapshot['valid_to'] ?? null,
        $snapshot['last_error'] ?? null
    );

    foreach ($results as $result) {
        $success = $result['success'];
        if ($success) {
            $stats['notifications_sent']++;
        }

        logMessage($success
            ? "    + Sent to {$result['channel']}"
            : "    - Failed: {$result['channel']}"
        );

        $logModel->log(
            $domain['id'],
            'ssl_status_' . $status,
            $result['channel'],
            "SSL status for {$hostname}: {$status}",
            $success,
            $success ? null : 'Failed to send SSL status notification'
        );
    }
}

function sendInAppSslNotifications(
    array $domain,
    string $hostname,
    string $status,
    ?string $previousStatus,
    string $isolationMode,
    User $userModel,
    NotificationGroup $groupModel,
    NotificationService $notificationService,
    array &$stats
): void {
    $usersToNotify = [];

    if ($isolationMode === 'isolated') {
        $userId = $domain['user_id'] ?? null;

        if (!$userId && !empty($domain['notification_group_id'])) {
            $group = $groupModel->find($domain['notification_group_id']);
            $userId = $group['user_id'] ?? null;
        }

        if ($userId) {
            $usersToNotify[] = $userId;
        }
    } else {
        foreach ($userModel->where('is_active', 1) as $user) {
            $usersToNotify[] = $user['id'];
        }
    }

    if (empty($usersToNotify)) {
        return;
    }

    $notifiedCount = 0;

    foreach ($usersToNotify as $userId) {
        try {
            $notificationService->notifySslStatusChange(
                $userId,
                $domain['domain_name'],
                $hostname,
                $domain['id'],
                $status,
                $previousStatus
            );
            $notifiedCount++;
        } catch (\Exception $e) {
            logMessage("  ! In-app SSL notification failed for user {$userId}: " . $e->getMessage());
        }
    }

    if ($notifiedCount > 0) {
        logMessage("  -> Notified {$notifiedCount} user(s) in-app");
        $stats['in_app_notifications'] += $notifiedCount;
    }
}

function logMessage(string $message): void
{
    global $cron;
    $cron->log($message);
}

function logTimeSince(float $since): void
{
    global $cron;
    $cron->logTimeSince($since, '  -> ');
}
