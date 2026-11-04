#!/usr/bin/env php
<?php

/**
 * SSL Certificate Monitoring Cron Job
 *
 * Checks tracked SSL endpoints for active domains with SSL monitoring enabled.
 * If no root endpoint is tracked yet, the root domain falls back to port 443.
 * Sends notifications when an SSL state changes or when the first monitored
 * baseline already has an issue, and expiry reminders on the SSL reminder days
 * from Settings (each reminder day once per certificate).
 *
 * Usage: php cron/check_ssl.php
 * Recommended schedule: run at minute 0 every 12 hours.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SslCertificate;
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
$settingModel = new Setting();
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

// Only check domains that are registered and in use (active or expiring_soon), plus "error" ones:
// that status means the WHOIS lookup failed, which says nothing about the website. Hosts that
// do not resolve are skipped per endpoint below.
// Skip available, expired, redemption_period, pending_delete — they typically have no DNS/SSL.
$checkableStatuses = Domain::DNS_SSL_CHECK_STATUSES;

$allSslEnabled = array_values(array_filter(
    $domainModel->where('is_active', 1),
    static fn(array $d): bool => ($d['ssl_monitoring_enabled'] ?? 0) == 1
));
$domains = array_values(array_filter($allSslEnabled, static function (array $domain) use ($checkableStatuses): bool {
    $status = strtolower($domain['status'] ?? '');
    return in_array($status, $checkableStatuses, true);
}));
$skippedByStatus = count($allSslEnabled) - count($domains);
logMessage("Found " . count($domains) . " domain(s) with SSL monitoring enabled and checkable status (active/expiring_soon/error)");
if ($skippedByStatus > 0) {
    logMessage("Skipped " . $skippedByStatus . " domain(s) with non-checkable status (available/expired/redemption_period/pending_delete)");
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
    'reminders' => 0,
];

$sslReminderDays = $settingModel->getSslNotificationDays();
logMessage("Reminder days: " . implode(', ', $sslReminderDays));

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

            $check = $sslService->checkEndpoint($domain['id'], $hostname, $port, $sslReminderDays);
            $snapshot = $check['snapshot'];
            $stats['checked_hosts']++;

            $status = $snapshot['status'];
            if (in_array($status, ['expiring', 'expired', 'invalid'], true)) {
                $domainIssues++;
                $stats['issues_detected']++;
            }

            logMessage(
                "  {$endpointLabel}: {$status}" .
                ($snapshot['valid_to'] ? " (valid_to: {$snapshot['valid_to']})" : '') .
                ($snapshot['last_error'] ? " (error: {$snapshot['last_error']})" : '')
            );

            if ($check['status_changed']) {
                $domainStatusChanges++;
                $stats['status_changes']++;
            }

            $alerts = $notificationService->alertSslCheck($domain, $endpointLabel, $check);
            if ($alerts['skipped'] !== null) {
                logMessage("  -> {$alerts['skipped']}");
                continue;
            }

            if ($check['reminder_days'] !== null) {
                $stats['reminders']++;
                logMessage("  -> Expiry reminder ({$check['reminder_days']}-day): {$snapshot['days_remaining']} day(s) left");
            }

            logAlertResults($alerts, $stats);
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
    } catch (\Throwable $e) {
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
logMessage("Expiry reminders:       {$stats['reminders']}");
logMessage("Issue endpoints:        {$stats['issues_detected']}");
logMessage("External notifications: {$stats['notifications_sent']}");
logMessage("In-app notifications:   {$stats['in_app_notifications']}");
logMessage("Errors:                 {$stats['errors']}");
logMessage("Execution time:         " . CronHelper::formatElapsedTime(microtime(true) - $startTime));
logMessage("============================\n");

exit(0);

/**
 * Log what NotificationService::alertSslCheck() sent and count it
 */
function logAlertResults(array $alerts, array &$stats): void
{
    if (!empty($alerts['channels'])) {
        logMessage("  -> Sending SSL alerts to " . count($alerts['channels']) . " channel(s)");
    }

    foreach ($alerts['channels'] as $result) {
        $success = $result['success'];
        if ($success) {
            $stats['notifications_sent']++;
        }

        logMessage($success
            ? "    + Sent to {$result['channel']}"
            : "    - Failed: {$result['channel']}"
        );
    }

    if ($alerts['users'] > 0) {
        logMessage("  -> Notified {$alerts['users']} user(s) in-app");
        $stats['in_app_notifications'] += $alerts['users'];
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
