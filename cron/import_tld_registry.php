#!/usr/bin/env php
<?php

/**
 * TLD Registry Import Script
 * 
 * This script imports TLD registry data from IANA sources:
 * - RDAP servers from https://data.iana.org/rdap/dns.json
 * - WHOIS servers from individual IANA TLD pages
 * 
 * Usage: php cron/import_tld_registry.php [options]
 * 
 * Options:
 *   --tld-list-only Import only TLD list from IANA
 *   --rdap-only     Import only RDAP data
 *   --whois-only    Import only WHOIS data for missing TLDs
 *   --tlds=LIST     Import WHOIS data for specific TLDs (comma-separated, e.g., --tlds=ro,de,fr)
 *   --check-updates Check for IANA updates without importing
 *   --force         Force import even if no updates available
 *   --verbose       Enable verbose output
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\TldRegistryService;
use Core\Database;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize database
new Database();

// Parse command line arguments
$options = getopt('', ['tld-list-only', 'rdap-only', 'whois-only', 'tlds:', 'check-updates', 'force', 'verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$verbose = isset($options['verbose']);
$force = isset($options['force']);

// Initialize service
$tldService = new TldRegistryService();

// Log file
$logFile = __DIR__ . '/../logs/tld_import.log';

function logMessage(string $message, bool $verbose = false) {
    global $logFile, $options;
    
    if ($verbose && !isset($options['verbose'])) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message\n";
    
    file_put_contents($logFile, $logLine, FILE_APPEND);
    echo $logLine;
}

logMessage("=== Starting TLD Registry Import ===");

try {
    // Check for updates first
    if (isset($options['check-updates'])) {
        logMessage("Checking for IANA updates...");
        
        $updateInfo = $tldService->checkForUpdates();
        
        if ($updateInfo['needs_update']) {
            logMessage("✓ IANA data has been updated!");
            logMessage("  Current publication: " . ($updateInfo['current_publication'] ?? 'Unknown'));
            logMessage("  Last publication: " . ($updateInfo['last_publication'] ?? 'None'));
            
            if (!$force) {
                logMessage("Use --force to import the updated data.");
                exit(0);
            }
        } else {
            logMessage("✓ TLD registry is up to date");
            if (isset($updateInfo['error'])) {
                logMessage("  Error: " . $updateInfo['error']);
            }
            exit(0);
        }
    }

    $totalStats = [
        'tld_list' => ['total_tlds' => 0, 'new_tlds' => 0, 'updated_tlds' => 0, 'failed_tlds' => 0],
        'rdap' => ['total_tlds' => 0, 'new_tlds' => 0, 'updated_tlds' => 0, 'failed_tlds' => 0],
        'whois' => ['total_tlds' => 0, 'new_tlds' => 0, 'updated_tlds' => 0, 'failed_tlds' => 0]
    ];

    // Import TLD list
    if (!isset($options['rdap-only']) && !isset($options['whois-only'])) {
        logMessage("Importing TLD list from IANA...");
        
        $tldListStats = $tldService->importTldList();
        $totalStats['tld_list'] = $tldListStats;
        
        logMessage("TLD list import completed:");
        logMessage("  Total TLDs: {$tldListStats['total_tlds']}");
        logMessage("  New TLDs: {$tldListStats['new_tlds']}");
        logMessage("  Updated TLDs: {$tldListStats['updated_tlds']}");
        if ($tldListStats['failed_tlds'] > 0) {
            logMessage("  Failed TLDs: {$tldListStats['failed_tlds']}");
        }

        // The domain suffixes come along with the TLD list (#112)
        try {
            if ((new \App\Models\DomainSuffix())->isInstalled()) {
                $suffixStats = (new \App\Services\DomainSuffixService())->import();
                logMessage("Domain suffixes imported from the Public Suffix List: {$suffixStats['rules']}");
            }
        } catch (\Throwable $e) {
            logMessage("Domain suffix import failed: " . $e->getMessage());
        }
    }

    // Import RDAP data
    if (!isset($options['tld-list-only']) && !isset($options['whois-only'])) {
        logMessage("Importing RDAP data from IANA...");
        
        $rdapStats = $tldService->importRdapData();
        $totalStats['rdap'] = $rdapStats;
        
        logMessage("RDAP import completed:");
        logMessage("  Total TLDs: {$rdapStats['total_tlds']}");
        logMessage("  New TLDs: {$rdapStats['new_tlds']}");
        logMessage("  Updated TLDs: {$rdapStats['updated_tlds']}");
        if ($rdapStats['failed_tlds'] > 0) {
            logMessage("  Failed TLDs: {$rdapStats['failed_tlds']}");
        }
    }

    // Import WHOIS data for missing TLDs or specific TLDs
    if (!isset($options['tld-list-only']) && !isset($options['rdap-only'])) {
        if (isset($options['tlds'])) {
            // Import specific TLDs
            $tldList = array_map('trim', explode(',', $options['tlds']));
            logMessage("Importing WHOIS data for specific TLDs: " . implode(', ', $tldList));
            
            $whoisStats = $tldService->importWhoisForSpecificTlds($tldList);
            $totalStats['whois'] = $whoisStats;
            
            logMessage("WHOIS import completed:");
            logMessage("  Total TLDs: {$whoisStats['total_tlds']}");
            logMessage("  New TLDs: {$whoisStats['new_tlds']}");
            logMessage("  Updated TLDs: {$whoisStats['updated_tlds']}");
            if ($whoisStats['failed_tlds'] > 0) {
                logMessage("  Failed TLDs: {$whoisStats['failed_tlds']}");
            }
        } else {
            // Import WHOIS data for missing TLDs
            logMessage("Importing WHOIS data for missing TLDs...");
            
            $whoisStats = $tldService->importWhoisDataForMissingTlds();
            $totalStats['whois'] = $whoisStats;
            
            logMessage("WHOIS import completed:");
            logMessage("  Total TLDs: {$whoisStats['total_tlds']}");
            logMessage("  Updated TLDs: {$whoisStats['updated_tlds']}");
            if ($whoisStats['failed_tlds'] > 0) {
                logMessage("  Failed TLDs: {$whoisStats['failed_tlds']}");
            }
        }
    }

    // Summary
    logMessage("\n=== Import Summary ===");
    logMessage("TLD List: {$totalStats['tld_list']['total_tlds']} total, {$totalStats['tld_list']['new_tlds']} new, {$totalStats['tld_list']['updated_tlds']} updated");
    logMessage("RDAP: {$totalStats['rdap']['total_tlds']} total, {$totalStats['rdap']['new_tlds']} new, {$totalStats['rdap']['updated_tlds']} updated");
    logMessage("WHOIS: {$totalStats['whois']['total_tlds']} total, {$totalStats['whois']['updated_tlds']} updated");
    
    $totalNew = $totalStats['tld_list']['new_tlds'] + $totalStats['rdap']['new_tlds'];
    $totalUpdated = $totalStats['tld_list']['updated_tlds'] + $totalStats['rdap']['updated_tlds'] + $totalStats['whois']['updated_tlds'];
    $totalFailed = $totalStats['tld_list']['failed_tlds'] + $totalStats['rdap']['failed_tlds'] + $totalStats['whois']['failed_tlds'];
    
    logMessage("Overall: {$totalNew} new, {$totalUpdated} updated, {$totalFailed} failed");
    logMessage("==========================\n");

} catch (Exception $e) {
    logMessage("✗ Import failed: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

logMessage("✓ TLD Registry import completed successfully");
exit(0);

function showHelp() {
    echo "TLD Registry Import Script\n\n";
    echo "Usage: php cron/import_tld_registry.php [options]\n\n";
    echo "Options:\n";
    echo "  --tld-list-only Import only TLD list from IANA\n";
    echo "  --rdap-only     Import only RDAP data from IANA\n";
    echo "  --whois-only    Import only WHOIS data for missing TLDs\n";
    echo "  --tlds=LIST     Import WHOIS data for specific TLDs (comma-separated)\n";
    echo "  --check-updates Check for IANA updates without importing\n";
    echo "  --force         Force import even if no updates available\n";
    echo "  --verbose       Enable verbose output\n";
    echo "  --help          Show this help message\n\n";
    echo "Examples:\n";
    echo "  php cron/import_tld_registry.php                    # Full import\n";
    echo "  php cron/import_tld_registry.php --tld-list-only    # TLD list only\n";
    echo "  php cron/import_tld_registry.php --rdap-only        # RDAP only\n";
    echo "  php cron/import_tld_registry.php --tlds=ro,de,fr    # Import specific TLDs\n";
    echo "  php cron/import_tld_registry.php --check-updates    # Check for updates\n";
    echo "  php cron/import_tld_registry.php --force --verbose  # Force import with verbose output\n\n";
}
