#!/usr/bin/env php
<?php

/**
 * DNS Record Monitoring Cron Job
 *
 * Re-checks existing DNS records for all active domains and sends notifications
 * when changes are detected (new records, removed records, changed records).
 * No discovery (brute force / crt.sh) — use discover_dns.php for that.
 *
 * Also serves as the crt.sh subprocess entry point (--crtsh) for
 * DnsService::fetchCrtshSubdomains() used by discover_dns.php.
 *
 * Usage:
 *   php cron/check_dns.php                            — re-check existing records
 *   php cron/check_dns.php --crtsh <domain> [max]     — (internal) crt.sh subprocess
 *
 * Crontab: 0 0,6,12,18 * * * /usr/bin/php /path/to/project/cron/check_dns.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\NotificationChannel;
use App\Models\NotificationGroup;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\DnsService;
use App\Services\NotificationService;
use App\Services\Logger;
use App\Helpers\CronHelper;
use Core\Database;

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
new Database();

// ─── Crt.sh subprocess mode ─────────────────────────────────────────────────
// When invoked with --crtsh, this script acts as its own subprocess for
// crt.sh fetching. Outputs a JSON array of subdomains to stdout and exits.

if (isset($argv[1]) && $argv[1] === '--crtsh') {
    runCrtshSubprocess($argv);
    exit(0);
}

// ─── Main cron mode ─────────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/** Microseconds to sleep between domains */
const INTER_DOMAIN_DELAY_US = 500000;

// Initialize services and models
$domainModel         = new Domain();
$dnsModel            = new DnsRecord();
$channelModel        = new NotificationChannel();
$groupModel          = new NotificationGroup();
$logModel            = new NotificationLog();
$notificationModel   = new \App\Models\Notification();
$settingModel        = new Setting();
$userModel           = new User();
$dnsService          = new DnsService();
$notificationService = new NotificationService();
$logger              = new Logger('dns-cron');

// Set timezone from settings
try {
    $appSettings = $settingModel->getAppSettings();
    date_default_timezone_set($appSettings['app_timezone']);
} catch (\Exception $e) {
    date_default_timezone_set('UTC');
}

$logFile   = __DIR__ . '/../logs/dns_cron.log';
$cron      = new CronHelper($logFile);
$startTime = microtime(true);

logMessage("=== Starting DNS check cron job ===");

// Only check domains that are registered and in use (active or expiring_soon).
// Skip available, expired, error, redemption_period, pending_delete — they typically have no DNS.
$checkableStatuses = ['active', 'expiring_soon'];

$allDnsEnabled = array_values(array_filter(
    $domainModel->where('is_active', 1),
    static fn($d): bool => ($d['dns_monitoring_enabled'] ?? 1) == 1
));
$domains = array_values(array_filter($allDnsEnabled, static function ($d) use ($checkableStatuses): bool {
    $status = strtolower($d['status'] ?? '');
    return in_array($status, $checkableStatuses, true);
}));
$skippedByStatus = count($allDnsEnabled) - count($domains);
logMessage("Found " . count($domains) . " domain(s) with DNS monitoring enabled and checkable status (active/expiring_soon)");
if ($skippedByStatus > 0) {
    logMessage("Skipped " . $skippedByStatus . " domain(s) with non-checkable status (available/expired/error/redemption_period/pending_delete)");
}

$stats = [
    'checked'              => 0,
    'skipped_by_status'    => $skippedByStatus,
    'changes_detected'     => 0,
    'records_added'        => 0,
    'records_removed'      => 0,
    'records_changed'      => 0,
    'notifications_sent'   => 0,
    'in_app_notifications' => 0,
    'errors'               => 0,
    'skipped_unresolved'   => 0,
    'domains_with_changes' => [],
];

$isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

foreach ($domains as $domain) {
    $domainName      = $domain['domain_name'];
    $domainStartTime = microtime(true);
    logMessage("Checking DNS: $domainName");

    try {
        // Quick existence check — skip if domain doesn't resolve at all
        if (!CronHelper::hostnameResolves($domainName)) {
            logMessage("  ⏭ Domain does not resolve (no SOA/A/AAAA), skipping");
            logTimeSince($domainStartTime);
            $stats['skipped_unresolved']++;
            continue;
        }

        $previousRecords = $dnsModel->getPreviousSnapshot($domain['id']);
        $isFirstScan     = empty($previousRecords);

        // Re-check only known hosts — no discovery (brute force / crt.sh)
        $existingHosts = $dnsModel->getDistinctHosts($domain['id']);
        $newRecords    = $dnsService->refreshExisting($domainName, $existingHosts);
        $totalRecords  = array_sum(array_map('count', $newRecords));

        if ($totalRecords === 0) {
            logMessage("  ⚠ No DNS records found for $domainName");
            logTimeSince($domainStartTime);
            $stats['errors']++;
            continue;
        }

        // Enrich A/AAAA records with IP details (PTR, ASN, geo)
        enrichIpDetails($newRecords, $dnsService);

        // Save snapshot
        $saveStats = $dnsModel->saveSnapshot($domain['id'], $newRecords);
        $domainModel->update($domain['id'], ['dns_last_checked' => date('Y-m-d H:i:s')]);

        $stats['checked']++;
        logMessage("  ✓ $totalRecords record(s) (added: {$saveStats['added']}, updated: {$saveStats['updated']}, removed: {$saveStats['removed']})");

        if ($isFirstScan) {
            logMessage("  → First scan — baseline saved");
        }

        // Detect changes
        $changes    = $dnsService->diffRecords($previousRecords, $newRecords);
        $hasChanges = !empty($changes['added']) || !empty($changes['removed']) || !empty($changes['changed']);

        if (!$hasChanges) {
            logMessage("  → No changes detected");
            logTimeSince($domainStartTime);
            usleep(INTER_DOMAIN_DELAY_US);
            continue;
        }

        $stats['changes_detected']++;
        $stats['records_added']   += count($changes['added']);
        $stats['records_removed'] += count($changes['removed']);
        $stats['records_changed'] += count($changes['changed']);

        $summary = $dnsService->formatChangesSummary($changes, $domainName);
        $detail  = $dnsService->formatChangesDetail($changes, $domainName);
        logMessage("  🔄 $summary");

        $stats['domains_with_changes'][] = [
            'domain'  => $domainName,
            'added'   => count($changes['added']),
            'removed' => count($changes['removed']),
            'changed' => count($changes['changed']),
        ];

        // Send external notifications (channel alerts)
        sendExternalNotifications(
            $domain, $domainModel, $channelModel, $logModel,
            $notificationService, $detail, $summary, $stats, $logger
        );

        // Create in-app notifications (bell icon)
        sendInAppNotifications(
            $domain, $domainName, $isolationMode, $userModel, $groupModel,
            $notificationService, $summary, $stats
        );

        logTimeSince($domainStartTime);
        usleep(INTER_DOMAIN_DELAY_US);

    } catch (\Exception $e) {
        logMessage("  ✗ Error: " . $e->getMessage());
        logTimeSince($domainStartTime);
        $logger->error("DNS check failed", [
            'domain' => $domainName,
            'error'  => $e->getMessage(),
        ]);
        $stats['errors']++;
    }
}

$settingModel->setValue('last_dns_check_run', date('Y-m-d H:i:s'));
printSummary($stats, $startTime);
exit(0);


// ═════════════════════════════════════════════════════════════════════════════
//  Crt.sh subprocess entry point (invoked by DnsService::fetchCrtshSubdomains)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Internal crt.sh subprocess entry point.
 * Called when this script is invoked with: --crtsh <domain> [max_subdomains]
 * Outputs JSON to stdout. Uses DnsService for HTTP fetch and parsing.
 *
 * Wildcard query ?q=%.domain.com with 5 retry attempts.
 * All HTTP response details are written to stderr for real-time debugging.
 */
function runCrtshSubprocess(array $argv): void
{
    if (empty($argv[2])) {
        fwrite(STDERR, "Usage: {$argv[0]} --crtsh <domain> [max_subdomains]\n");
        echo '[]';
        return;
    }

    $domain        = $argv[2];
    $maxSubdomains = isset($argv[3]) ? max(0, (int) $argv[3]) : 0;
    $maxAttempts   = 5;
    $retryDelay    = 10;
    $httpTimeout   = 900;

    $dnsService = new DnsService();
    $url = 'https://crt.sh/?q=%25.' . urlencode($domain) . '&output=json';

    try {
        $result  = [];
        $gotHttp200 = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            fwrite(STDERR, "attempt $attempt/$maxAttempts: GET $url\n");

            $response = $dnsService->fetchCrtshUrl($url, $httpTimeout, true);

            if ($response['status'] === 200) {
                $gotHttp200 = true;
                if (!empty($response['data'])) {
                    $result = $dnsService->extractCrtshSubdomains($response['data'], $domain);
                    fwrite(STDERR, "attempt $attempt/$maxAttempts: " . count($result) . " subdomain(s) extracted\n");
                } else {
                    fwrite(STDERR, "attempt $attempt/$maxAttempts: 200 OK but no cert data (domain may have no CT entries)\n");
                }
                break;
            }

            if ($attempt < $maxAttempts) {
                fwrite(STDERR, "attempt $attempt/$maxAttempts: retrying in {$retryDelay}s...\n");
                sleep($retryDelay);
            } else {
                fwrite(STDERR, "all $maxAttempts attempts failed\n");
            }
        }

        if ($maxSubdomains > 0 && count($result) > $maxSubdomains) {
            fwrite(STDERR, "result: " . count($result) . " subdomain(s), capped to $maxSubdomains\n");
            $result = array_slice(array_values($result), 0, $maxSubdomains);
        } else {
            fwrite(STDERR, "result: " . count($result) . " subdomain(s)\n");
        }

        echo json_encode(['ok' => $gotHttp200, 'subs' => array_values($result)]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "crt.sh error: " . $e->getMessage() . "\n");
        echo json_encode(['ok' => false, 'subs' => []]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  DNS helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Enrich A/AAAA records in-place with IP metadata (PTR, ASN, geo).
 */
function enrichIpDetails(array &$newRecords, DnsService $dnsService): void
{
    $ips = [];
    foreach (['A', 'AAAA'] as $type) {
        foreach ($newRecords[$type] ?? [] as $r) {
            if (!empty($r['value'])) {
                $ips[] = $r['value'];
            }
        }
    }

    if (empty($ips)) {
        return;
    }

    $ipDetails = $dnsService->lookupIpDetails($ips);

    foreach (['A', 'AAAA'] as $type) {
        if (empty($newRecords[$type])) {
            continue;
        }
        foreach ($newRecords[$type] as &$rec) {
            if (!empty($rec['value']) && isset($ipDetails[$rec['value']])) {
                $rec['raw']['_ip_info'] = $ipDetails[$rec['value']];
            }
        }
        unset($rec);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
//  Notification helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Send external notifications via configured channels.
 */
function sendExternalNotifications(
    array $domain,
    Domain $domainModel,
    NotificationChannel $channelModel,
    NotificationLog $logModel,
    NotificationService $notificationService,
    string $detail,
    string $summary,
    array &$stats,
    Logger $logger
): void {
    if (empty($domain['notification_group_id'])) {
        return;
    }

    if ($logModel->wasSentRecently($domain['id'], 'dns_change', 6)) {
        logMessage("  → DNS change notification sent recently, skipping external");
        return;
    }

    $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);
    if (empty($channels)) {
        return;
    }

    logMessage("  📤 Sending alerts to " . count($channels) . " channel(s)");

    $domainData = $domainModel->find($domain['id']);
    $results    = $notificationService->sendDnsChangeAlert($domainData, $channels, $detail);

    foreach ($results as $result) {
        $ok = $result['success'];
        logMessage($ok
            ? "    ✓ Sent to {$result['channel']}"
            : "    ✗ Failed: {$result['channel']}"
        );

        if ($ok) {
            $stats['notifications_sent']++;
        }

        $logModel->log(
            $domain['id'],
            'dns_change',
            $result['channel'],
            $summary,
            $ok,
            $ok ? null : 'Failed to send notification'
        );
    }
}

/**
 * Create in-app (bell icon) notifications for relevant users.
 */
function sendInAppNotifications(
    array $domain,
    string $domainName,
    string $isolationMode,
    User $userModel,
    NotificationGroup $groupModel,
    NotificationService $notificationService,
    string $summary,
    array &$stats
): void {
    $usersToNotify = [];

    if ($isolationMode === 'isolated') {
        $userId = $domain['user_id'] ?? null;

        if (!$userId && !empty($domain['notification_group_id'])) {
            $group  = $groupModel->find($domain['notification_group_id']);
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

    $db            = Database::getConnection();
    $notifiedCount = 0;

    foreach ($usersToNotify as $userId) {
        // Deduplicate: skip if already notified in the last 6 hours
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM user_notifications
             WHERE user_id = ? AND domain_id = ? AND type = 'dns_change'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)"
        );
        $stmt->execute([$userId, $domain['id']]);
        $row = $stmt->fetch();

        if ($row && $row['cnt'] > 0) {
            continue;
        }

        try {
            $notificationService->notifyDnsChange($userId, $domainName, $domain['id'], $summary);
            $notifiedCount++;
        } catch (\Exception $e) {
            logMessage("  ⚠ In-app notification failed for user $userId: " . $e->getMessage());
        }
    }

    if ($notifiedCount > 0) {
        logMessage("  🔔 Notified $notifiedCount user(s) in-app");
        $stats['in_app_notifications'] += $notifiedCount;
    }
}


// ═════════════════════════════════════════════════════════════════════════════
//  Logging helpers (thin wrappers around CronHelper)
// ═════════════════════════════════════════════════════════════════════════════

function logMessage(string $message): void
{
    global $cron;
    $cron->log($message);
}

function logTimeSince(float $since): void
{
    global $cron;
    $cron->logTimeSince($since);
}

function printSummary(array $stats, float $startTime): void
{
    $elapsed = CronHelper::formatElapsedTime(microtime(true) - $startTime);

    logMessage("\n=== DNS cron job completed ===");
    logMessage("Domains checked:            {$stats['checked']}");
    logMessage("Skipped (by status):        {$stats['skipped_by_status']}");
    logMessage("Skipped (unresolved):       {$stats['skipped_unresolved']}");
    logMessage("Changes detected:           {$stats['changes_detected']}");
    logMessage("Records added:              {$stats['records_added']}");
    logMessage("Records removed:            {$stats['records_removed']}");
    logMessage("Records changed:            {$stats['records_changed']}");
    logMessage("External notifications:     {$stats['notifications_sent']}");
    logMessage("In-app notifications:       {$stats['in_app_notifications']}");
    logMessage("Errors:                     {$stats['errors']}");
    logMessage("Execution time:             $elapsed");

    if (!empty($stats['domains_with_changes'])) {
        logMessage("\n--- Domains with DNS changes ---");
        foreach ($stats['domains_with_changes'] as $info) {
            logMessage("  {$info['domain']}: +{$info['added']} added, -{$info['removed']} removed, ~{$info['changed']} changed");
        }
    }

    logMessage("==========================\n");
}
