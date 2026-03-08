#!/usr/bin/env php
<?php

/**
 * DNS Record Monitoring Cron Job
 *
 * Checks DNS records for all active domains and sends notifications
 * when changes are detected (new records, removed records, changed records).
 *
 * Also handles crt.sh subdomain fetching internally via self-invocation
 * with a hard timeout (no separate script needed).
 *
 * Usage:
 *   php cron/check_dns.php                            — run the full DNS check
 *   php cron/check_dns.php --crtsh <domain> [max]     — (internal) crt.sh subprocess
 *
 * Crontab: 0 0,6,12,18 * * * /usr/bin/php /path/to/project/cron/check_dns.php
 *
 * NOTE: Requires a `crtsh_last_fetched` column on the domains table:
 *       ALTER TABLE domains ADD COLUMN crtsh_last_fetched DATETIME NULL DEFAULT NULL;
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

/** crt.sh subprocess hard kill (seconds). In practice crt.sh 503s in <60s, but HTTP timeout is 900s as insurance. */
const CRTSH_TIMEOUT_SECONDS = 1800;

/** Max unique subdomains from crt.sh per domain (0 = no limit) */
const CRTSH_MAX_SUBDOMAINS = 100;

/** How often to re-fetch crt.sh per domain (hours). New certs appear gradually. */
const CRTSH_REFRESH_HOURS = 24;

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
    'crtsh_skipped'        => 0,
    'crtsh_fetched'        => 0,
    'domains_with_changes' => [],
];

$isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

foreach ($domains as $domain) {
    $domainName      = $domain['domain_name'];
    $domainStartTime = microtime(true);
    logMessage("Checking DNS: $domainName");

    try {
        // Quick existence check — skip if domain doesn't resolve at all
        if (!domainResolves($domainName)) {
            logMessage("  ⏭ Domain does not resolve (no SOA/A/AAAA), skipping");
            logTimeSince($domainStartTime);
            $stats['skipped_unresolved']++;
            continue;
        }

        $previousRecords = $dnsModel->getPreviousSnapshot($domain['id']);
        $isFirstScan     = empty($previousRecords);

        // Gather subdomain candidates: known hosts from DB
        $existingHosts = $dnsModel->getDistinctHosts($domain['id']);

        // Decide whether to call crt.sh or use cached hosts
        $ctSubs = [];

        if (shouldFetchCrtsh($domain, $existingHosts)) {
            logMessage("  🔍 crt.sh: fetching subdomains...");

            [$ctSubs, $crtshOk] = fetchCrtshWithTimeout($domainName);

            logMessage("  🔍 crt.sh: " . count($ctSubs) . " subdomain(s) found");
            $stats['crtsh_fetched']++;

            // Update timestamp if server responded (200 OK).
            // Empty [] is valid (no CT entries) — still counts as a successful fetch.
            // Only skip update if all attempts 503'd / timed out.
            if ($crtshOk) {
                $domainModel->update($domain['id'], [
                    'crtsh_last_fetched' => date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            logMessage("  ⏩ crt.sh skipped (" . count($existingHosts) . " known host(s), refresh in "
                . crtshHoursUntilRefresh($domain) . "h)");
            $stats['crtsh_skipped']++;
        }

        $extraSubs = array_unique(array_merge($existingHosts, $ctSubs));

        // Fetch fresh DNS records
        $newRecords   = $dnsService->lookup($domainName, $extraSubs);
        $totalRecords = array_sum(array_map('count', $newRecords));

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
//  Crt.sh smart caching
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Should we fetch crt.sh for this domain right now?
 *
 * Skip if we already have enough known hosts and fetched recently.
 * Always fetch on first scan or if we have very few known hosts.
 *
 * NOTE: Requires a `crtsh_last_fetched` DATETIME column on the domains table.
 *       ALTER TABLE domains ADD COLUMN crtsh_last_fetched DATETIME NULL DEFAULT NULL;
 */
function shouldFetchCrtsh(array $domain, array $existingHosts): bool
{
    // Always fetch if we've never successfully fetched before
    $lastFetched = $domain['crtsh_last_fetched'] ?? null;
    if (empty($lastFetched)) {
        return true;
    }

    // Respect the refresh interval — even if domain has few hosts,
    // crt.sh already answered (maybe with [] or few results). Don't hammer it.
    $hoursSince = (time() - strtotime($lastFetched)) / 3600;
    return $hoursSince >= CRTSH_REFRESH_HOURS;
}

/**
 * Hours remaining until next crt.sh refresh (for log messages).
 */
function crtshHoursUntilRefresh(array $domain): string
{
    $lastFetched = $domain['crtsh_last_fetched'] ?? null;
    if (empty($lastFetched)) {
        return '0';
    }
    $hoursSince = (time() - strtotime($lastFetched)) / 3600;
    $remaining  = max(0, CRTSH_REFRESH_HOURS - $hoursSince);
    return sprintf('%.1f', $remaining);
}


// ═════════════════════════════════════════════════════════════════════════════
//  Crt.sh subprocess (self-invocation with hard timeout)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Internal crt.sh subprocess entry point.
 * Called when this script is invoked with: --crtsh <domain> [max_subdomains]
 * Outputs a JSON array of subdomains to stdout.
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

    $url = 'https://crt.sh/?q=%25.' . urlencode($domain) . '&output=json';

    try {
        $result  = [];
        $gotHttp200 = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            fwrite(STDERR, "attempt $attempt/$maxAttempts: GET $url\n");

            $response = fetchCrtshWithDebug($url, $httpTimeout);

            // HTTP 200 — server answered, don't retry regardless of content
            if ($response['status'] === 200) {
                $gotHttp200 = true;
                if (!empty($response['data'])) {
                    $result = extractSubdomains($response['data'], $domain);
                    fwrite(STDERR, "attempt $attempt/$maxAttempts: " . count($result) . " subdomain(s) extracted\n");
                } else {
                    fwrite(STDERR, "attempt $attempt/$maxAttempts: 200 OK but no cert data (domain may have no CT entries)\n");
                }
                break;
            }

            // Non-200 (503, timeout, connection error) — retry
            if ($attempt < $maxAttempts) {
                fwrite(STDERR, "attempt $attempt/$maxAttempts: retrying in {$retryDelay}s...\n");
                sleep($retryDelay);
            } else {
                fwrite(STDERR, "all $maxAttempts attempts failed\n");
            }
        }

        // Apply cap
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

/**
 * Fetch a crt.sh URL with full debug output to stderr.
 * Dumps HTTP response headers + body preview immediately so you see
 * exactly what the server returned — like watching curl in real-time.
 *
 * @param  string $url      Full crt.sh URL
 * @param  int    $timeout  HTTP timeout in seconds
 * @return array{status: int, body_length: int, data: array, time: float}
 */
function fetchCrtshWithDebug(string $url, int $timeout = 900): array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
            ]),
        ],
    ]);

    $start = microtime(true);
    $http_response_header = null;
    $body = @file_get_contents($url, false, $ctx);
    $elapsed = microtime(true) - $start;

    // ── Dump full response to stderr ──────────────────────────────────
    fwrite(STDERR, "--- response ---\n");
    fwrite(STDERR, "Time: " . sprintf('%.1f', $elapsed) . "s\n");

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            fwrite(STDERR, "$h\n");
        }
    } else {
        fwrite(STDERR, "(no response headers — connection failed or timeout)\n");
    }

    $bodyLen = is_string($body) ? strlen($body) : 0;
    fwrite(STDERR, "Body: $bodyLen bytes\n");

    if (is_string($body) && $bodyLen > 0) {
        // Show first 2000 chars of body so you can see errors, JSON start, etc.
        $preview = $bodyLen > 2000 ? substr($body, 0, 2000) . "\n... [truncated, $bodyLen total]" : $body;
        fwrite(STDERR, $preview . "\n");
    }

    fwrite(STDERR, "--- end response ---\n");

    // ── Parse status and JSON ─────────────────────────────────────────
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
        $status = (int) $m[0];
    }

    $data = [];
    if ($status === 200 && is_string($body) && $bodyLen > 2) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    return [
        'status'      => $status,
        'body_length' => $bodyLen,
        'data'        => $data,
        'time'        => $elapsed,
    ];
}

/**
 * Extract unique subdomain names from raw crt.sh JSON response.
 *
 * Each entry has a `name_value` field that may contain multiple newline-separated
 * names, including wildcards. We strip wildcards, filter to our target domain,
 * and return only the subdomain prefixes (e.g. "www", "mail", "api").
 *
 * @param  array  $crtshData  Decoded JSON array from crt.sh
 * @param  string $domain     The base domain (e.g. "example.com")
 * @return string[]           Unique subdomain prefixes
 */
function extractSubdomains(array $crtshData, string $domain): array
{
    $domainLower = strtolower($domain);
    $suffix      = '.' . $domainLower;
    $suffixLen   = strlen($suffix);
    $subs        = [];

    foreach ($crtshData as $entry) {
        if (empty($entry['name_value'])) {
            continue;
        }

        foreach (explode("\n", $entry['name_value']) as $name) {
            $name = strtolower(trim($name));

            // Strip wildcard prefix
            if (strpos($name, '*.') === 0) {
                $name = substr($name, 2);
            }

            // Skip the apex domain itself
            if ($name === $domainLower) {
                continue;
            }

            // Must be a subdomain of our domain
            if (substr($name, -$suffixLen) !== $suffix) {
                continue;
            }

            // Extract the subdomain part (everything before .domain.tld)
            $sub = substr($name, 0, strlen($name) - $suffixLen);
            if (!empty($sub)) {
                $subs[$sub] = true;
            }
        }
    }

    return array_keys($subs);
}


// ═════════════════════════════════════════════════════════════════════════════
//  Subprocess management (main process side)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Spawn a subprocess of this script in --crtsh mode with a hard timeout.
 * Relays stderr from the subprocess to logMessage in REAL-TIME so you see
 * every HTTP response, retry, and status as it happens.
 *
 * @return array{0: string[], 1: bool}  [subdomains, ok (true if server responded 200)]
 */
function fetchCrtshWithTimeout(string $domainName): array
{
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd    = [$phpBin, __FILE__, '--crtsh', $domainName];

    if (CRTSH_MAX_SUBDOMAINS > 0) {
        $cmd[] = (string) CRTSH_MAX_SUBDOMAINS;
    }

    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__ . '/..');

    if (!is_resource($proc)) {
        return [[], false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start        = time();
    $stdout       = '';
    $stderrBuffer = '';

    while (true) {
        $status = proc_get_status($proc);

        if (!$status['running']) {
            break;
        }

        $elapsed = time() - $start;

        // Hard timeout — kill the subprocess
        if ($elapsed >= CRTSH_TIMEOUT_SECONDS) {
            $stdout .= drainStream($pipes[1]);
            $stderrBuffer .= drainStream($pipes[2]);
            flushStderrLines($stderrBuffer);
            proc_terminate($proc, 9);
            proc_close($proc);
            logMessage("  ✗ crt.sh killed after {$elapsed}s (hard timeout)");
            return [[], false];
        }

        // Read available data from pipes
        $readable = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($readable, $w, $e, 0, 200000) > 0) {
            foreach ($readable as $stream) {
                $chunk = stream_get_contents($stream);
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderrBuffer .= $chunk;
                    // Flush complete lines to terminal immediately
                    flushStderrLines($stderrBuffer);
                }
            }
        }
        usleep(100000);
    }

    // Drain remaining output
    $stdout .= stream_get_contents($pipes[1]);
    $stderrBuffer .= stream_get_contents($pipes[2]);
    flushStderrLines($stderrBuffer);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode($stdout, true);
    $ok   = is_array($decoded) && !empty($decoded['ok']);
    $subs = is_array($decoded) && isset($decoded['subs']) ? $decoded['subs'] : [];
    return [$subs, $ok];
}

/**
 * Flush complete lines from stderr buffer to logMessage in real-time.
 * Keeps any incomplete trailing line in the buffer for next call.
 */
function flushStderrLines(string &$buffer): void
{
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = trim(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + 1);
        if ($line !== '') {
            logMessage("  ↳ $line");
        }
    }
}


// ═════════════════════════════════════════════════════════════════════════════
//  DNS helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Check whether a domain resolves at all (SOA, A, or AAAA).
 */
function domainResolves(string $domain): bool
{
    return @checkdnsrr($domain, 'SOA')
        || @checkdnsrr($domain, 'A')
        || @checkdnsrr($domain, 'AAAA');
}

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
//  Logging / formatting helpers
// ═════════════════════════════════════════════════════════════════════════════

function logMessage(string $message): void
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line      = "[$timestamp] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function logTimeSince(float $since): void
{
    logMessage("  ⏱ " . formatDuration(microtime(true) - $since));
}

function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf("%.1fs", $seconds);
    }
    $m = (int) floor($seconds / 60);
    $s = $seconds - $m * 60;
    return $m . 'm ' . sprintf("%.1fs", $s);
}

function formatElapsedTime(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf("%.2f seconds", $seconds);
    }
    if ($seconds < 3600) {
        $m = (int) floor($seconds / 60);
        $s = $seconds - $m * 60;
        return sprintf("%d minute%s %.2f seconds", $m, $m !== 1 ? 's' : '', $s);
    }
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds - $h * 3600) / 60);
    $s = $seconds - $h * 3600 - $m * 60;
    return sprintf("%d hour%s %d minute%s %.2f seconds",
        $h, $h !== 1 ? 's' : '', $m, $m !== 1 ? 's' : '', $s);
}

/**
 * Drain remaining data from a non-blocking stream and close it.
 */
function drainStream($stream): string
{
    if (!is_resource($stream)) {
        return '';
    }
    $data = stream_get_contents($stream);
    fclose($stream);
    return $data ?: '';
}

function printSummary(array $stats, float $startTime): void
{
    $elapsed = formatElapsedTime(microtime(true) - $startTime);

    logMessage("\n=== DNS cron job completed ===");
    logMessage("Domains checked:            {$stats['checked']}");
    logMessage("Skipped (by status):        {$stats['skipped_by_status']}");
    logMessage("Skipped (unresolved):       {$stats['skipped_unresolved']}");
    logMessage("Crt.sh fetched:             {$stats['crtsh_fetched']}");
    logMessage("Crt.sh skipped (cached):    {$stats['crtsh_skipped']}");
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
