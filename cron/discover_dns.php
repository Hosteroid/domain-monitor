#!/usr/bin/env php
<?php

/**
 * DNS Discovery Script
 *
 * Performs DNS subdomain discovery via brute force wordlist, crt.sh Certificate
 * Transparency logs, and wildcard detection. Separate from check_dns.php which
 * only re-checks existing records.
 *
 * Usage:
 *   php cron/discover_dns.php                                 — deep scan all domains
 *   php cron/discover_dns.php --domain example.com            — deep scan single domain
 *   php cron/discover_dns.php --domain example.com --quick    — quick scan single domain
 *
 * Crontab (optional, weekly): 0 3 * * 0 /usr/bin/php /path/to/project/cron/discover_dns.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\DnsRecord;
use App\Services\DnsService;
use App\Services\Logger;
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
$dnsModel    = new DnsRecord();
$dnsService  = new DnsService();
$logger      = new Logger('dns-discover');

$settingModel = new \App\Models\Setting();
try {
    $appSettings = $settingModel->getAppSettings();
    date_default_timezone_set($appSettings['app_timezone']);
} catch (\Exception $e) {
    date_default_timezone_set('UTC');
}

$logFile = __DIR__ . '/../logs/dns_discover.log';
$cron    = new CronHelper($logFile);

// ─── Parse CLI arguments ─────────────────────────────────────────────────────

$targetDomain = null;
$quickMode    = false;

for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--domain' && isset($argv[$i + 1])) {
        $targetDomain = $argv[++$i];
    } elseif ($argv[$i] === '--quick') {
        $quickMode = true;
    }
}

// ─── Resolve domains to scan ────────────────────────────────────────────────

if ($targetDomain) {
    $domainRow = null;
    foreach ($domainModel->all() as $d) {
        if (strcasecmp($d['domain_name'], $targetDomain) === 0) {
            $domainRow = $d;
            break;
        }
    }
    if (!$domainRow) {
        $cron->log("Domain not found in database: $targetDomain");
        exit(1);
    }
    $domains = [$domainRow];
} else {
    $checkableStatuses = ['active', 'expiring_soon'];
    $allDnsEnabled = array_values(array_filter(
        $domainModel->where('is_active', 1),
        static fn($d): bool => ($d['dns_monitoring_enabled'] ?? 1) == 1
    ));
    $domains = array_values(array_filter($allDnsEnabled, static function ($d) use ($checkableStatuses): bool {
        $status = strtolower($d['status'] ?? '');
        return in_array($status, $checkableStatuses, true);
    }));
}

$modeLabel = $quickMode ? 'Quick Scan' : 'Deep Scan';
$startTime = microtime(true);
$cron->log("=== DNS Discovery ({$modeLabel}) — " . count($domains) . " domain(s) ===");

$stats = [
    'scanned'   => 0,
    'skipped'   => 0,
    'added'     => 0,
    'updated'   => 0,
    'removed'   => 0,
    'errors'    => 0,
];

// ─── crt.sh settings (deep scan only) ──────────────────────────────────────

const CRTSH_TIMEOUT_SECONDS = 1800;
const CRTSH_MAX_SUBDOMAINS  = 100;

// ─── Scan loop ───────────────────────────────────────────────────────────────

foreach ($domains as $domain) {
    $domainName = $domain['domain_name'];
    $domStart   = microtime(true);
    $cron->log("Discovering: $domainName ($modeLabel)");

    try {
        if (!CronHelper::hostnameResolves($domainName)) {
            $cron->log("  ⏭ Domain does not resolve, skipping");
            $cron->logTimeSince($domStart);
            $stats['skipped']++;
            continue;
        }

        if ($quickMode) {
            $newRecords = $dnsService->quickScan($domainName);
        } else {
            // Deep scan: gather crt.sh subdomains + existing hosts, then full lookup
            $existingHosts = $dnsModel->getDistinctHosts($domain['id']);
            $ctSubs = [];

            $cron->log("  🔍 crt.sh: fetching subdomains...");
            [$ctSubs, $crtshOk] = $dnsService->fetchCrtshSubdomains(
                $domainName,
                CRTSH_MAX_SUBDOMAINS,
                CRTSH_TIMEOUT_SECONDS,
                fn(string $line) => $cron->log("  ↳ $line")
            );
            $cron->log("  🔍 crt.sh: " . count($ctSubs) . " subdomain(s) found");

            if ($crtshOk) {
                $domainModel->update($domain['id'], [
                    'crtsh_last_fetched' => date('Y-m-d H:i:s'),
                ]);
            }

            $extraSubs  = array_unique(array_merge($existingHosts, $ctSubs));
            $newRecords = $dnsService->lookup($domainName, $extraSubs, fn(string $msg) => $cron->log("  🔎 $msg"));
        }

        $totalRecords = array_sum(array_map('count', $newRecords));
        if ($totalRecords === 0) {
            $cron->log("  ⚠ No DNS records found");
            $stats['errors']++;
            $cron->logTimeSince($domStart);
            continue;
        }

        // Enrich IP details
        $ips = [];
        foreach (['A', 'AAAA'] as $type) {
            foreach ($newRecords[$type] ?? [] as $r) {
                if (!empty($r['value'])) {
                    $ips[] = $r['value'];
                }
            }
        }
        if (!empty($ips)) {
            $ipDetails = $dnsService->lookupIpDetails($ips);
            foreach (['A', 'AAAA'] as $type) {
                foreach ($newRecords[$type] as &$rec) {
                    if (!empty($rec['value']) && isset($ipDetails[$rec['value']])) {
                        $rec['raw']['_ip_info'] = $ipDetails[$rec['value']];
                    }
                }
                unset($rec);
            }
        }

        $saveStats = $dnsModel->saveSnapshot($domain['id'], $newRecords);
        $domainModel->update($domain['id'], ['dns_last_checked' => date('Y-m-d H:i:s')]);

        $stats['scanned']++;
        $stats['added']   += $saveStats['added'];
        $stats['updated'] += $saveStats['updated'];
        $stats['removed'] += $saveStats['removed'];

        $cron->log("  ✓ $totalRecords record(s) (added: {$saveStats['added']}, updated: {$saveStats['updated']}, removed: {$saveStats['removed']})");

        $logger->info("DNS discovery completed", [
            'domain'  => $domainName,
            'mode'    => $quickMode ? 'quick' : 'deep',
            'records' => $totalRecords,
            'added'   => $saveStats['added'],
        ]);

        $cron->logTimeSince($domStart);

    } catch (\Exception $e) {
        $cron->log("  ✗ Error: " . $e->getMessage());
        $cron->logTimeSince($domStart);
        $logger->error("DNS discovery failed", [
            'domain' => $domainName,
            'error'  => $e->getMessage(),
        ]);
        $stats['errors']++;
    }
}

// ─── Summary ─────────────────────────────────────────────────────────────────

$elapsed = CronHelper::formatElapsedTime(microtime(true) - $startTime);
$cron->log("\n=== DNS Discovery completed ===");
$cron->log("Domains scanned:  {$stats['scanned']}");
$cron->log("Skipped:          {$stats['skipped']}");
$cron->log("Records added:    {$stats['added']}");
$cron->log("Records updated:  {$stats['updated']}");
$cron->log("Records removed:  {$stats['removed']}");
$cron->log("Errors:           {$stats['errors']}");
$cron->log("Execution time:   $elapsed");
$cron->log("==========================\n");

exit(0);
