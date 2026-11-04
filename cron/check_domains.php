#!/usr/bin/env php
<?php

/**
 * Domain Expiration Check Cron Job
 * 
 * This script should be run periodically (recommended: daily) to check domain expirations
 * and send notifications when domains are approaching expiration.
 * 
 * Usage: php cron/check_domains.php
 * Crontab: 0 9 * * * /usr/bin/php /path/to/project/cron/check_domains.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Models\Setting;
use App\Services\WhoisService;
use App\Services\NotificationService;
use App\Services\UpdateService;
use App\Helpers\CronHelper;
use Core\Database;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize database
new Database();

// Initialize services
$domainModel = new Domain();
$groupModel = new NotificationGroup();
$settingModel = new Setting();
$whoisService = new WhoisService();
$notificationService = new NotificationService();

// Clear TLD cache to ensure fresh server discovery
WhoisService::clearTldCache();

// Set timezone from settings
try {
    $appSettings = $settingModel->getAppSettings();
    date_default_timezone_set($appSettings['app_timezone']);
} catch (\Exception $e) {
    date_default_timezone_set('UTC');
}

// Log file
$logFile = __DIR__ . '/../logs/cron.log';
$cron = new CronHelper($logFile);

function logMessage(string $message): void {
    global $cron;
    $cron->log($message);
}

/**
 * Log a status change found by the WHOIS refresh and send its alerts (the ones turned on in Settings)
 */
function sendStatusChangeAlerts(array $domain, string $oldStatus, string $status, bool $renewed, NotificationService $notificationService, array &$stats): void {
    $stats['status_changes']++;
    logMessage("  🔄 Status changed: $oldStatus → $status");

    $alerts = $notificationService->alertDomainStatusChange($domain, $oldStatus, $status, $renewed);
    if ($alerts['skipped'] !== null) {
        logMessage("  → {$alerts['skipped']}");
        return;
    }
    if ($alerts['type'] === null) {
        return;
    }

    logMessage("  📢 Status notification triggered: {$alerts['type']}");
    if ($alerts['already_sent']) {
        logMessage("  → Status notification already sent recently (skipping external alerts)");
    }

    if (!empty($alerts['channels'])) {
        logMessage("  📤 Sending status change alerts to " . count($alerts['channels']) . " channel(s)");
    }
    foreach ($alerts['channels'] as $sent) {
        if ($sent['success']) {
            logMessage("    ✓ Sent to {$sent['channel']}");
            $stats['status_notifications_sent']++;
            $stats['notifications_sent']++;
        } else {
            logMessage("    ✗ Failed to send to {$sent['channel']}");
        }
    }

    if ($alerts['users'] > 0) {
        logMessage("  🔔 Created status change notifications for {$alerts['users']} user(s)");
        $stats['in_app_notifications_created'] += $alerts['users'];
        $stats['domains_with_notifications']++;

        $stats['domains_notified'][] = [
            'domain' => $domain['domain_name'],
            'days_left' => null,
            'users_notified' => $alerts['users'],
            'has_group' => !empty($domain['notification_group_id']),
            'group_id' => $domain['notification_group_id'] ?? null,
            'status_change' => "$oldStatus → $status"
        ];

        if (!empty($domain['notification_group_id'])) {
            $groupId = $domain['notification_group_id'];
            if (!isset($stats['notification_groups_used'][$groupId])) {
                $stats['notification_groups_used'][$groupId] = 0;
            }
            $stats['notification_groups_used'][$groupId]++;
        }
    }
}

/**
 * Log a renewal found by the WHOIS refresh and send its alerts
 */
function sendRenewalAlerts(array $domain, array $renewal, NotificationService $notificationService, array &$stats): void {
    $stats['renewals']++;
    logMessage("  🔁 Renewed: expiration moved from {$renewal['old_expiration']} to {$renewal['new_expiration']}"
        . ($renewal['auto_renew_period'] ? ' (registry auto-renew period)' : ''));

    $alerts = $notificationService->alertDomainRenewal($domain, $renewal);
    if ($alerts['skipped'] !== null) {
        logMessage("  → {$alerts['skipped']}");
        return;
    }
    if ($alerts['already_sent']) {
        logMessage("  → Renewal alert already sent recently (skipping external alerts)");
    }

    if (!empty($alerts['channels'])) {
        logMessage("  📤 Sending renewal alerts to " . count($alerts['channels']) . " channel(s)");
    }
    foreach ($alerts['channels'] as $sent) {
        if ($sent['success']) {
            logMessage("    ✓ Sent to {$sent['channel']}");
            $stats['notifications_sent']++;
        } else {
            logMessage("    ✗ Failed to send to {$sent['channel']}");
        }
    }

    if ($alerts['users'] > 0) {
        logMessage("  🔔 Created renewal notifications for {$alerts['users']} user(s)");
        $stats['in_app_notifications_created'] += $alerts['users'];
    }
}

/**
 * Send the expiry alert due today for a domain (a reminder before or after it expires, or
 * "expired" on the day) and log it
 *
 * @param array $domain       Domain row after the WHOIS refresh: its date is the manual one when WHOIS has none
 * @param bool  $lookupFailed WHOIS couldn't be checked this run, so the alert uses the saved date as it was
 */
function sendExpiryAlerts(array $domain, NotificationService $notificationService, array &$stats, bool $lookupFailed = false): void {
    $alerts = $notificationService->alertDomainExpiry($domain, $lookupFailed);
    $daysLeft = $alerts['days_left'];
    if ($daysLeft === null) {
        return; // No expiration date, from WHOIS or set by hand
    }
    if ($alerts['skipped'] !== null) {
        logMessage("  → {$alerts['skipped']}");
        return;
    }
    if ($alerts['type'] === null) {
        logMessage("  → No expiration notification needed ($daysLeft days left)");
        return;
    }

    $statusText = match (true) {
        $alerts['type'] === 'expired' => 'Domain expired',
        str_starts_with($alerts['type'], 'expired_') => 'Domain expired ' . (int)substr($alerts['type'], 8) . ' days ago',
        default => "Domain expiring in $daysLeft days",
    };

    if ($alerts['already_sent']) {
        logMessage("  → External notification already sent recently (skipping external alerts)");
    } elseif (empty($domain['notification_group_id'])) {
        logMessage("  → No notification group assigned (skipping external alerts, but will create in-app notification)");
    } elseif (empty($alerts['channels'])) {
        logMessage("  → No active notification channels configured in group");
    } else {
        logMessage("  📤 Sending external notifications to " . count($alerts['channels']) . " channel(s)");
    }
    foreach ($alerts['channels'] as $sent) {
        if ($sent['success']) {
            logMessage("    ✓ Sent to {$sent['channel']}");
            $stats['notifications_sent']++;
        } else {
            logMessage("    ✗ Failed to send to {$sent['channel']}");
        }
    }

    if ($alerts['users'] === 0) {
        logMessage("  → In-app notifications already exist for all users (skipping duplicates)");
        return;
    }

    logMessage("  🔔 Created in-app notifications for {$alerts['users']} user(s): $statusText");
    $stats['in_app_notifications_created'] += $alerts['users'];
    $stats['domains_with_notifications']++;
    $stats['domains_notified'][] = [
        'domain' => $domain['domain_name'],
        'days_left' => $daysLeft,
        'users_notified' => $alerts['users'],
        'has_group' => !empty($domain['notification_group_id']),
        'group_id' => $domain['notification_group_id'] ?? null
    ];

    if (!empty($domain['notification_group_id'])) {
        $groupId = $domain['notification_group_id'];
        if (!isset($stats['notification_groups_used'][$groupId])) {
            $stats['notification_groups_used'][$groupId] = 0;
        }
        $stats['notification_groups_used'][$groupId]++;
    }
}

// Record start time
$startTime = microtime(true);

logMessage("=== Starting domain check cron job ===");

// Get notification days from database settings
$notificationDays = $settingModel->getNotificationDays();

logMessage("Notification thresholds (days): " . implode(', ', $notificationDays));
logMessage("Reminders after expiry (days): " . (implode(', ', $settingModel->getPostExpiryNotificationDays()) ?: 'none'));

// Get all active domains
$domains = $domainModel->where('is_active', 1);
logMessage("Found " . count($domains) . " active domains to check");

$stats = [
    'checked' => 0,
    'updated' => 0,
    'notifications_sent' => 0,
    'errors' => 0,
    'retried' => 0,
    'retry_succeeded' => 0,
    'in_app_notifications_created' => 0,
    'domains_with_notifications' => 0,
    'notification_groups_used' => [],
    'domains_notified' => [],
    'status_changes' => 0,
    'status_notifications_sent' => 0,
    'renewals' => 0
];

// Retry queue: domains that failed due to rate limiting
$retryQueue = [];

foreach ($domains as $domain) {
    $domainName = $domain['domain_name'];
    logMessage("Checking domain: $domainName");

    try {
        // Refresh WHOIS data
        $whoisData = $whoisService->getDomainInfo($domainName);

        if (!$whoisData) {
            // Check if this was a rate limit error
            $wasRateLimited = WhoisService::wasLastErrorRateLimit();
            $wasActive = in_array($domain['status'], ['active', 'expiring_soon']);
            $reason = WhoisService::getLastErrorMessage();
            $reasonSuffix = $reason ? ": $reason" : '';
            
            if ($wasRateLimited && $wasActive) {
                // Rate limited - add to retry queue instead of marking as error
                logMessage("  ⚠ Rate limit for $domainName - queued for retry");
                
                // Extract TLD for grouping retries
                $parts = explode('.', $domainName);
                $tld = $parts[count($parts) - 1];
                
                $retryQueue[] = [
                    'domain' => $domain,
                    'tld' => $tld,
                    'attempt' => 0,
                    'last_error' => 'rate_limit'
                ];
                
                $stats['retried']++;
            } elseif ($wasActive) {
                // Other temporary error - preserve status
                logMessage("  ⚠ Temporary error for $domainName - preserving status{$reasonSuffix}");
                $domainModel->update($domain['id'], [
                    'last_checked' => date('Y-m-d H:i:s')
                ]);
                $stats['checked']++;
            } else {
                // Non-active domain or permanent error
                logMessage("  ✗ Failed to get WHOIS data for $domainName{$reasonSuffix}");
                $stats['errors']++;
                $domainModel->update($domain['id'], [
                    'status' => 'error',
                    'last_checked' => date('Y-m-d H:i:s')
                ]);
            }

            // The saved expiration date still says which reminder is due, e.g. one set by hand
            // for a TLD whose lookups never work. A domain queued for a retry gets them there.
            if (!($wasRateLimited && $wasActive)) {
                sendExpiryAlerts($domain, $notificationService, $stats, true);
            }

            // Add a small delay after errors to avoid overwhelming rate-limited servers
            usleep(500000); // 0.5 seconds delay
            
            continue;
        }

        // IMPORTANT: Use WHOIS expiration date if available, otherwise preserve existing expiration date
        // This handles TLDs like .nl that don't provide expiration dates via RDAP
        $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
        
        // Update domain information
        $status = $whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);
        $renewal = $whoisService->detectRenewal($domain, $whoisData, $status);
        $domainModel->update($domain['id'], [
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $expirationDate,
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE)
        ]);

        $stats['checked']++;
        $stats['updated']++;

        // Detect status change
        $oldStatus = $domain['status'];
        logMessage("  ✓ Updated WHOIS data for $domainName");
        logMessage("    Expiration: " . ($whoisData['expiration_date'] ?? ($expirationDate ? "$expirationDate (set by hand)" : 'N/A')) . ", Status: $status" . ($oldStatus !== $status ? " (was: $oldStatus)" : ""));

        // Add a small delay between domain checks to avoid rate limiting
        // This helps especially with .nl and other TLDs that have strict rate limits
        usleep(1000000); // 1 second delay between checks

        // The alerts read the saved row; a domain deleted since the run started has none
        $refreshed = $domainModel->find($domain['id']);
        if (!$refreshed) {
            logMessage("  → Domain was deleted during this run (skipping its alerts)");
            continue;
        }

        // ============================================================
        // STATUS CHANGE NOTIFICATIONS
        // ============================================================
        // Settings choose which status changes send alerts (both external and in-app). A failed
        // lookup saves "error" over a known status, so the change is judged against the last one.
        $lastKnownStatus = $whoisService->getLastKnownStatus($domain);
        if ($lastKnownStatus !== $status) {
            sendStatusChangeAlerts($refreshed, $lastKnownStatus, $status, $renewal !== null, $notificationService, $stats);
        }

        // ============================================================
        // RENEWAL NOTIFICATIONS
        // ============================================================
        if ($renewal) {
            sendRenewalAlerts($refreshed, $renewal, $notificationService, $stats);
        }

        // ============================================================
        // EXPIRATION-BASED NOTIFICATIONS
        // ============================================================
        // Reminders before expiry, "expired" on the day it expires, and reminders after (Settings),
        // from the saved date: a manual one counts too when WHOIS has none
        sendExpiryAlerts($refreshed, $notificationService, $stats);

    } catch (\Throwable $e) {
        logMessage("  ✗ Error processing $domainName: " . $e->getMessage());
        $stats['errors']++;
    }
}

// Process retry queue with exponential backoff
$maxRetries = 3;
$retryDelays = [30, 60, 120]; // Delays in seconds: 30s, 60s, 120s
$retryFailed = []; // Domains that could not be refreshed by any retry

if (!empty($retryQueue)) {
    logMessage("\n=== Processing retry queue (" . count($retryQueue) . " domain(s)) ===");
    
    // Group by TLD to avoid hitting same rate limit multiple times
    $tldGroups = [];
    foreach ($retryQueue as $item) {
        $tld = $item['tld'];
        if (!isset($tldGroups[$tld])) {
            $tldGroups[$tld] = [];
        }
        $tldGroups[$tld][] = $item;
    }
    
    logMessage("Grouped into " . count($tldGroups) . " TLD group(s) for staggered retries");
    
    // Process each retry attempt
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $remainingQueue = [];
        $delay = $retryDelays[$attempt] ?? 120;
        
        if ($attempt > 0) {
            logMessage("\n--- Retry attempt " . ($attempt + 1) . " after {$delay}s delay ---");
            sleep($delay);
            
            // Re-group remaining queue by TLD for this attempt
            $tldGroups = [];
            foreach ($retryQueue as $item) {
                $tld = $item['tld'];
                if (!isset($tldGroups[$tld])) {
                    $tldGroups[$tld] = [];
                }
                $tldGroups[$tld][] = $item;
            }
        } else {
            logMessage("\n--- Retry attempt " . ($attempt + 1) . " (immediate) ---");
        }
        
        // Process each TLD group with delays between groups
        $tldIndex = 0;
        foreach ($tldGroups as $tld => $tldDomains) {
            $tldIndex++;
            logMessage("Processing TLD group: .$tld (" . count($tldDomains) . " domain(s))");
            
            foreach ($tldDomains as $queueItem) {
                $domain = $queueItem['domain'];
                $domainName = $domain['domain_name'];
                $currentAttempt = $attempt + 1;
                $queueItem['attempt'] = $currentAttempt;
                
                logMessage("  Retrying domain: $domainName (attempt {$queueItem['attempt']})");
                
                try {
                    // Clear last error before retry
                    WhoisService::clearLastError();
                    
                    // Retry WHOIS lookup
                    $whoisData = $whoisService->getDomainInfo($domainName);
                    
                    if (!$whoisData) {
                        $wasRateLimited = WhoisService::wasLastErrorRateLimit();
                        
                        if ($wasRateLimited && $currentAttempt < $maxRetries) {
                            // Still rate limited, queue for next retry
                            logMessage("    ⚠ Still rate limited - will retry again");
                            $remainingQueue[] = $queueItem;
                        } else {
                            // Failed after max retries or non-rate-limit error
                            $reason = WhoisService::getLastErrorMessage();
                            logMessage("    ✗ Failed after {$currentAttempt} attempt(s)" . ($reason ? ": $reason" : ''));
                            $retryFailed[] = $domainName;
                            $wasActive = in_array($domain['status'], ['active', 'expiring_soon']);
                            
                            if ($wasActive) {
                                // Preserve status if it was active
                                $domainModel->update($domain['id'], [
                                    'last_checked' => date('Y-m-d H:i:s')
                                ]);
                            } else {
                                $domainModel->update($domain['id'], [
                                    'status' => 'error',
                                    'last_checked' => date('Y-m-d H:i:s')
                                ]);
                            }

                            // The saved expiration date still says which reminder is due
                            sendExpiryAlerts($domain, $notificationService, $stats, true);
                        }
                        
                        // Delay between retry attempts
                        usleep(1000000); // 1 second delay
                        continue;
                    }
                    
                    // Success! Update domain
                    logMessage("    ✓ Retry successful for $domainName");
                    $stats['retry_succeeded']++;
                    
                    $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
                    $status = $whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);
                    $renewal = $whoisService->detectRenewal($domain, $whoisData, $status);

                    $domainModel->update($domain['id'], [
                        'registrar' => $whoisData['registrar'],
                        'registrar_url' => $whoisData['registrar_url'] ?? null,
                        'expiration_date' => $expirationDate,
                        'updated_date' => $whoisData['updated_date'] ?? null,
                        'abuse_email' => $whoisData['abuse_email'] ?? null,
                        'last_checked' => date('Y-m-d H:i:s'),
                        'status' => $status,
                        'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE)
                    ]);
                    
                    $stats['checked']++;
                    $stats['updated']++;

                    $refreshed = $domainModel->find($domain['id']);
                    if (!$refreshed) {
                        logMessage("    → Domain was deleted during this run (skipping its alerts)");
                        continue;
                    }

                    $lastKnownStatus = $whoisService->getLastKnownStatus($domain);
                    if ($lastKnownStatus !== $status) {
                        sendStatusChangeAlerts($refreshed, $lastKnownStatus, $status, $renewal !== null, $notificationService, $stats);
                    }

                    if ($renewal) {
                        sendRenewalAlerts($refreshed, $renewal, $notificationService, $stats);
                    }

                    // Check notifications for successfully retried domains
                    sendExpiryAlerts($refreshed, $notificationService, $stats);

                    // Delay between successful retries
                    usleep(1000000); // 1 second delay

                } catch (\Throwable $e) {
                    logMessage("    ✗ Exception during retry: " . $e->getMessage());
                    if ($currentAttempt < $maxRetries) {
                        $remainingQueue[] = $queueItem;
                    } else {
                        // Only active domains are queued, so keep the status as for a failed lookup
                        $retryFailed[] = $domainName;
                        $domainModel->update($domain['id'], [
                            'last_checked' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
            
            // Delay between TLD groups to avoid hitting rate limits
            if ($tldIndex < count($tldGroups)) {
                sleep(5); // 5 seconds between TLD groups
            }
        }
        
        // The next attempt retries only the domains that are still rate limited
        $retryQueue = $remainingQueue;
        if (empty($retryQueue)) {
            break;
        }
        
        if ($attempt < $maxRetries - 1) {
            logMessage(count($retryQueue) . " domain(s) remaining for next retry");
        }
    }
    
    if (empty($retryFailed)) {
        logMessage("All retries completed successfully");
    } else {
        $retryFailed = array_unique($retryFailed);
        logMessage("\n⚠ " . count($retryFailed) . " domain(s) still failed after retrying: " . implode(', ', $retryFailed));
    }
    
    logMessage("=== Retry queue processing completed ===\n");
}

// Update last check run timestamp
$settingModel->updateLastCheckRun();

// Calculate elapsed time
$endTime = microtime(true);
$elapsedTime = $endTime - $startTime;
$formattedTime = CronHelper::formatElapsedTime($elapsedTime);

// Summary
logMessage("\n=== Cron job completed ===");
logMessage("Domains checked: {$stats['checked']}");
logMessage("Domains updated: {$stats['updated']}");
logMessage("Status changes detected: {$stats['status_changes']}");
logMessage("Status notifications sent: {$stats['status_notifications_sent']}");
logMessage("Renewals detected: {$stats['renewals']}");
logMessage("External notifications sent: {$stats['notifications_sent']}");
logMessage("In-app notifications created: {$stats['in_app_notifications_created']}");
logMessage("Domains with notifications: {$stats['domains_with_notifications']}");
logMessage("Errors: {$stats['errors']}");
logMessage("Domains queued for retry: {$stats['retried']}");
logMessage("Retries succeeded: {$stats['retry_succeeded']}");
logMessage("Execution time: $formattedTime");

// Detailed notification statistics
if ($stats['domains_with_notifications'] > 0) {
    logMessage("\n--- Notification Details ---");
    
    // Group statistics
    if (!empty($stats['notification_groups_used'])) {
        logMessage("Notification groups used: " . count($stats['notification_groups_used']));
        foreach ($stats['notification_groups_used'] as $groupId => $count) {
            $group = $groupModel->find($groupId);
            $groupName = $group ? $group['name'] : "Group #$groupId";
            logMessage("  - $groupName: $count domain(s)");
        }
    } else {
        logMessage("Notification groups used: 0 (domains without groups)");
    }
    
    // Domain breakdown
    if (count($stats['domains_notified']) <= 10) {
        // Show all if 10 or fewer
        logMessage("\nDomains that received notifications:");
        foreach ($stats['domains_notified'] as $domainInfo) {
            $groupInfo = $domainInfo['has_group'] ? " (Group #{$domainInfo['group_id']})" : " (No Group)";
            logMessage("  - {$domainInfo['domain']}: {$domainInfo['days_left']} days left, {$domainInfo['users_notified']} user(s) notified$groupInfo");
        }
    } else {
        // Show summary if more than 10
        logMessage("\nDomains that received in-app notifications: " . count($stats['domains_notified']));
        $expiringCount = count(array_filter($stats['domains_notified'], fn($d) => $d['days_left'] > 0));
        $expiredCount = count($stats['domains_notified']) - $expiringCount;
        logMessage("  - Expiring soon: $expiringCount domain(s)");
        if ($expiredCount > 0) {
            logMessage("  - Expired: $expiredCount domain(s)");
        }
    }
}

// Check for application updates (respects 6-hour cache) and notify admins if new version available
try {
    $updateService = new UpdateService();
    $updateResult = $updateService->checkForUpdate(false);
    if (!empty($updateResult['error'])) {
        logMessage("Update check skipped or failed: " . $updateResult['error']);
    } elseif (!empty($updateResult['available'])) {
        $currentVersion = $updateResult['current_version'];
        $type = $updateResult['type'] ?? 'release';
        $notifiedRelease = $settingModel->getValue('last_update_available_notified_release', '');
        $notifiedHotfixSha = $settingModel->getValue('last_update_available_notified_hotfix_sha', '');
        $shouldNotify = false;
        if ($type === 'release') {
            $latestVersion = $updateResult['latest_version'] ?? '';
            if ($latestVersion !== '' && $latestVersion !== $notifiedRelease) {
                $shouldNotify = true;
                $settingModel->setValue('last_update_available_notified_release', $latestVersion);
            }
        } else {
            $remoteSha = $updateResult['remote_sha'] ?? '';
            if ($remoteSha !== '' && $remoteSha !== $notifiedHotfixSha) {
                $shouldNotify = true;
                $settingModel->setValue('last_update_available_notified_hotfix_sha', $remoteSha);
            }
        }
        if ($shouldNotify) {
            $label = ($type === 'release') ? ($updateResult['latest_version'] ?? 'latest') : 'hotfix';
            $commitsBehind = $updateResult['commits_behind'] ?? null;
            $notificationService->notifyAdminsUpdateAvailable($currentVersion, $label, $type, $commitsBehind);
            logMessage("Update available (v{$currentVersion} → {$label}): admins notified.");
        }
    }
} catch (\Throwable $e) {
    logMessage("Update check error: " . $e->getMessage());
}

logMessage("==========================\n");

exit(0);

