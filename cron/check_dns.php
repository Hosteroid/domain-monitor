#!/usr/bin/env php
<?php

/**
 * DNS Record Monitoring Cron Job
 *
 * Re-checks existing DNS records for all active domains and sends notifications
 * when changes are detected (new records, removed records, changed records).
 * No discovery (brute force / certificate logs) — use discover_dns.php for that.
 *
 * Also serves as the certificate transparency subprocess entry point (--ctlog) for
 * DnsService::fetchCtLogSubdomains() used by discover_dns.php.
 *
 * Usage:
 *   php cron/check_dns.php                                   — re-check existing records
 *   php cron/check_dns.php --ctlog <source> <domain> [max]   — (internal) certificate log subprocess
 *   php cron/check_dns.php --crtsh <domain> [max]            — (internal) same as --ctlog crtsh
 *
 * Crontab: 0 0,6,12,18 * * * /usr/bin/php /path/to/project/cron/check_dns.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Models\Setting;
use App\Services\DnsHistoryService;
use App\Services\DnsService;
use App\Services\NotificationService;
use App\Services\Logger;
use App\Helpers\CronHelper;
use Core\Database;

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
new Database();

// ─── Certificate log subprocess mode ────────────────────────────────────────
// When invoked with --ctlog, this script acts as its own subprocess for one
// certificate transparency source. Outputs JSON to stdout and exits.

if (isset($argv[1]) && ($argv[1] === '--ctlog' || $argv[1] === '--crtsh')) {
    runCtLogSubprocess($argv[1] === '--crtsh' ? ['crtsh', ...array_slice($argv, 2)] : array_slice($argv, 2));
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
$settingModel        = new Setting();
$dnsService          = new DnsService();
$historyService      = new DnsHistoryService();
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

// Only check domains that are registered and in use (active or expiring_soon), plus "error" ones:
// that status means the WHOIS lookup failed, which says nothing about DNS. Domains that do not
// resolve are skipped below.
// Skip available, expired, redemption_period, pending_delete — they typically have no DNS.
$checkableStatuses = Domain::DNS_SSL_CHECK_STATUSES;

$allDnsEnabled = array_values(array_filter(
    $domainModel->where('is_active', 1),
    static fn($d): bool => ($d['dns_monitoring_enabled'] ?? 1) == 1
));
$domains = array_values(array_filter($allDnsEnabled, static function ($d) use ($checkableStatuses): bool {
    $status = strtolower($d['status'] ?? '');
    return in_array($status, $checkableStatuses, true);
}));
$skippedByStatus = count($allDnsEnabled) - count($domains);
logMessage("Found " . count($domains) . " domain(s) with DNS monitoring enabled and checkable status (active/expiring_soon/error)");
if ($skippedByStatus > 0) {
    logMessage("Skipped " . $skippedByStatus . " domain(s) with non-checkable status (available/expired/redemption_period/pending_delete)");
}

$stats = [
    'checked'              => 0,
    'skipped_by_status'    => $skippedByStatus,
    'changes_detected'     => 0,
    'history_saved'        => 0,
    'records_added'        => 0,
    'records_removed'      => 0,
    'records_changed'      => 0,
    'notifications_sent'   => 0,
    'in_app_notifications' => 0,
    'errors'               => 0,
    'skipped_unresolved'   => 0,
    'domains_with_changes' => [],
];

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

        // Nothing to compare against yet, so every record would read as "added"
        if ($isFirstScan) {
            logMessage("  → First scan — baseline saved, no alerts");
            logTimeSince($domainStartTime);
            usleep(INTER_DOMAIN_DELAY_US);
            continue;
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

        // The records from before the change, whether or not an alert goes out for it
        if ($historyService->recordChange($domain, $previousRecords, $changes, 'cron')) {
            $stats['history_saved']++;
            logMessage("  → Previous records saved to DNS history");
        }

        $stats['domains_with_changes'][] = [
            'domain'  => $domainName,
            'added'   => count($changes['added']),
            'removed' => count($changes['removed']),
            'changed' => count($changes['changed']),
        ];

        // Channel alerts and in-app notifications (bell icon)
        $alerts = $notificationService->alertDnsChanges($domainModel->find($domain['id']), $summary, $detail);
        if ($alerts['skipped'] !== null) {
            logMessage("  → {$alerts['skipped']}");
        } else {
            logAlertResults($alerts, $stats);
        }

        logTimeSince($domainStartTime);
        usleep(INTER_DOMAIN_DELAY_US);

    } catch (\Throwable $e) {
        logMessage("  ✗ Error: " . $e->getMessage());
        logTimeSince($domainStartTime);
        $logger->error("DNS check failed", [
            'domain' => $domainName,
            'error'  => $e->getMessage(),
        ]);
        $stats['errors']++;
    }
}

$historyPruned = $historyService->prune();
if ($historyPruned > 0) {
    logMessage("DNS history: removed $historyPruned item(s) older than " . $settingModel->getDnsHistoryRetentionDays() . " days");
}

$settingModel->setValue('last_dns_check_run', date('Y-m-d H:i:s'));
printSummary($stats, $startTime);
exit(0);


// ═════════════════════════════════════════════════════════════════════════════
//  Certificate log subprocess entry point (invoked by DnsService::fetchCtLogSubdomains)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Internal certificate transparency subprocess entry point.
 * Called when this script is invoked with: --ctlog <source> <domain> [max_subdomains]
 * Outputs {"ok", "subs", "retry_after"} as JSON to stdout. Progress and all HTTP
 * response details are written to stderr for real-time debugging.
 */
function runCtLogSubprocess(array $args): void
{
    $failed        = ['ok' => false, 'subs' => [], 'retry_after' => null];
    $sourceId      = $args[0] ?? '';
    $domain        = $args[1] ?? '';
    $maxSubdomains = isset($args[2]) ? max(0, (int) $args[2]) : 0;

    $source = $domain !== '' ? (new DnsService())->createCtLogSource($sourceId) : null;
    if (!$source) {
        $sources = implode('|', array_keys(DnsService::CT_LOG_SOURCES));
        fwrite(STDERR, "Usage: check_dns.php --ctlog <{$sources}> <domain> [max_subdomains]\n");
        echo json_encode($failed);
        return;
    }

    try {
        $result = $source->fetchSubdomains($domain, static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        });

        $subs = array_values($result['subs']);
        if ($maxSubdomains > 0 && count($subs) > $maxSubdomains) {
            fwrite(STDERR, "result: " . count($subs) . " subdomain(s), capped to $maxSubdomains\n");
            $subs = array_slice($subs, 0, $maxSubdomains);
        } else {
            fwrite(STDERR, "result: " . count($subs) . " subdomain(s)\n");
        }

        echo json_encode(['ok' => $result['ok'], 'subs' => $subs, 'retry_after' => $result['retry_after']]);
    } catch (\Throwable $e) {
        fwrite(STDERR, $source->getName() . " error: " . $e->getMessage() . "\n");
        echo json_encode($failed);
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
 * Log what NotificationService::alertDnsChanges() sent and count it.
 */
function logAlertResults(array $alerts, array &$stats): void
{
    if ($alerts['already_sent']) {
        logMessage("  → DNS change notification sent recently, skipping external");
    }

    if (!empty($alerts['channels'])) {
        logMessage("  📤 Sending alerts to " . count($alerts['channels']) . " channel(s)");
    }

    foreach ($alerts['channels'] as $result) {
        $ok = $result['success'];
        logMessage($ok
            ? "    ✓ Sent to {$result['channel']}"
            : "    ✗ Failed: {$result['channel']}"
        );

        if ($ok) {
            $stats['notifications_sent']++;
        }
    }

    if ($alerts['users'] > 0) {
        logMessage("  🔔 Notified {$alerts['users']} user(s) in-app");
        $stats['in_app_notifications'] += $alerts['users'];
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
    logMessage("Saved to DNS history:       {$stats['history_saved']}");
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
