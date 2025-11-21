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
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Services\WhoisService;
use App\Services\NotificationService;
use Core\Database;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize database
new Database();

// Initialize services
$domainModel = new Domain();
$channelModel = new NotificationChannel();
$logModel = new NotificationLog();
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

function logMessage(string $message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

function formatElapsedTime(float $seconds): string {
    if ($seconds < 60) {
        return sprintf("%.2f seconds", $seconds);
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds - ($minutes * 60);
        return sprintf("%d minute%s %.2f seconds", $minutes, $minutes != 1 ? 's' : '', $remainingSeconds);
    } else {
        $hours = floor($seconds / 3600);
        $remainingMinutes = floor(($seconds - ($hours * 3600)) / 60);
        $remainingSeconds = $seconds - ($hours * 3600) - ($remainingMinutes * 60);
        return sprintf("%d hour%s %d minute%s %.2f seconds", $hours, $hours != 1 ? 's' : '', $remainingMinutes, $remainingMinutes != 1 ? 's' : '', $remainingSeconds);
    }
}

// Record start time
$startTime = microtime(true);

logMessage("=== Starting domain check cron job ===");

// Get notification days from database settings
$notificationDays = $settingModel->getNotificationDays();

logMessage("Notification thresholds (days): " . implode(', ', $notificationDays));

// Get all active domains
$domains = $domainModel->where('is_active', 1);
logMessage("Found " . count($domains) . " active domains to check");

$stats = [
    'checked' => 0,
    'updated' => 0,
    'notifications_sent' => 0,
    'errors' => 0,
    'retried' => 0,
    'retry_succeeded' => 0
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
                logMessage("  ⚠ Temporary error for $domainName - preserving status");
                $domainModel->update($domain['id'], [
                    'last_checked' => date('Y-m-d H:i:s')
                ]);
                $stats['checked']++;
            } else {
                // Non-active domain or permanent error
                logMessage("  ✗ Failed to get WHOIS data for $domainName");
                $stats['errors']++;
                $domainModel->update($domain['id'], [
                    'status' => 'error',
                    'last_checked' => date('Y-m-d H:i:s')
                ]);
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
        $domainModel->update($domain['id'], [
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $expirationDate,
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData)
        ]);

        $stats['checked']++;
        $stats['updated']++;

        logMessage("  ✓ Updated WHOIS data for $domainName");
        logMessage("    Expiration: " . ($whoisData['expiration_date'] ?? 'N/A') . ", Status: $status");

        // Add a small delay between domain checks to avoid rate limiting
        // This helps especially with .nl and other TLDs that have strict rate limits
        usleep(1000000); // 1 second delay between checks

        // Check if notifications should be sent
        $daysLeft = $whoisService->daysUntilExpiration($whoisData['expiration_date']);

        if ($daysLeft === null) {
            continue;
        }

        // Check if this domain should trigger a notification
        $shouldNotify = false;
        $notificationType = '';

        if ($daysLeft <= 0) {
            $shouldNotify = true;
            $notificationType = 'expired';
        } elseif (in_array($daysLeft, $notificationDays)) {
            $shouldNotify = true;
            $notificationType = "expiring_in_{$daysLeft}_days";
        }

        if (!$shouldNotify) {
            logMessage("  → No notification needed ($daysLeft days left)");
            continue;
        }

        // Check if notification was already sent recently (within last 23 hours)
        if ($logModel->wasSentRecently($domain['id'], $notificationType, 23)) {
            logMessage("  → Notification already sent recently");
            continue;
        }

        // Get notification channels for this domain's group
        if (!$domain['notification_group_id']) {
            logMessage("  → No notification group assigned");
            continue;
        }

        $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);

        if (empty($channels)) {
            logMessage("  → No active notification channels configured");
            continue;
        }

        logMessage("  📤 Sending notifications to " . count($channels) . " channel(s)");

        // Refresh domain data with group info
        $domainData = $domainModel->find($domain['id']);
        
        // Send notifications
        $results = $notificationService->sendDomainExpirationAlert($domainData, $channels);

        foreach ($results as $result) {
            $success = $result['success'];
            $channel = $result['channel'];

            if ($success) {
                logMessage("    ✓ Sent to $channel");
                $stats['notifications_sent']++;
            } else {
                logMessage("    ✗ Failed to send to $channel");
            }

            // Log the notification attempt
            $logModel->log(
                $domain['id'],
                $notificationType,
                $channel,
                "Domain $domainName expires in $daysLeft days",
                $success,
                $success ? null : "Failed to send notification"
            );
        }

    } catch (Exception $e) {
        logMessage("  ✗ Error processing $domainName: " . $e->getMessage());
        $stats['errors']++;
    }
}

// Process retry queue with exponential backoff
$maxRetries = 3;
$retryDelays = [30, 60, 120]; // Delays in seconds: 30s, 60s, 120s

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
                            logMessage("    ✗ Failed after {$currentAttempt} attempt(s)");
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
                    
                    $domainModel->update($domain['id'], [
                        'registrar' => $whoisData['registrar'],
                        'registrar_url' => $whoisData['registrar_url'] ?? null,
                        'expiration_date' => $expirationDate,
                        'updated_date' => $whoisData['updated_date'] ?? null,
                        'abuse_email' => $whoisData['abuse_email'] ?? null,
                        'last_checked' => date('Y-m-d H:i:s'),
                        'status' => $status,
                        'whois_data' => json_encode($whoisData)
                    ]);
                    
                    $stats['checked']++;
                    $stats['updated']++;
                    
                    // Check notifications for successfully retried domains
                    $daysLeft = $whoisService->daysUntilExpiration($whoisData['expiration_date']);
                    
                    if ($daysLeft !== null) {
                        $shouldNotify = false;
                        $notificationType = '';
                        
                        if ($daysLeft <= 0) {
                            $shouldNotify = true;
                            $notificationType = 'expired';
                        } elseif (in_array($daysLeft, $notificationDays)) {
                            $shouldNotify = true;
                            $notificationType = "expiring_in_{$daysLeft}_days";
                        }
                        
                        if ($shouldNotify && !$logModel->wasSentRecently($domain['id'], $notificationType, 23)) {
                            if ($domain['notification_group_id']) {
                                $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);
                                if (!empty($channels)) {
                                    $domainData = $domainModel->find($domain['id']);
                                    $results = $notificationService->sendDomainExpirationAlert($domainData, $channels);
                                    
                                    foreach ($results as $result) {
                                        if ($result['success']) {
                                            $stats['notifications_sent']++;
                                        }
                                        $logModel->log(
                                            $domain['id'],
                                            $notificationType,
                                            $result['channel'],
                                            "Domain $domainName expires in $daysLeft days",
                                            $result['success'],
                                            $result['success'] ? null : "Failed to send notification"
                                        );
                                    }
                                }
                            }
                        }
                    }
                    
                    // Delay between successful retries
                    usleep(1000000); // 1 second delay
                    
                } catch (Exception $e) {
                    logMessage("    ✗ Exception during retry: " . $e->getMessage());
                    if ($currentAttempt < $maxRetries) {
                        $remainingQueue[] = $queueItem;
                    }
                }
            }
            
            // Delay between TLD groups to avoid hitting rate limits
            if ($tldIndex < count($tldGroups)) {
                sleep(5); // 5 seconds between TLD groups
            }
        }
        
        // Update retry queue for next attempt
        if (empty($remainingQueue)) {
            logMessage("All retries completed successfully");
            break;
        }
        
        // Update retry queue with remaining items for next iteration
        $retryQueue = $remainingQueue;
        
        if ($attempt < $maxRetries - 1) {
            logMessage(count($remainingQueue) . " domain(s) remaining for next retry");
        }
    }
    
    if (!empty($retryQueue)) {
        logMessage("\n⚠ " . count($retryQueue) . " domain(s) still failed after {$maxRetries} retry attempts");
        // Preserve status for remaining failed domains
        foreach ($retryQueue as $queueItem) {
            $domain = $queueItem['domain'];
            $wasActive = in_array($domain['status'], ['active', 'expiring_soon']);
            if ($wasActive) {
                $domainModel->update($domain['id'], [
                    'last_checked' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
    
    logMessage("=== Retry queue processing completed ===\n");
}

// Update last check run timestamp
$settingModel->updateLastCheckRun();

// Calculate elapsed time
$endTime = microtime(true);
$elapsedTime = $endTime - $startTime;
$formattedTime = formatElapsedTime($elapsedTime);

// Summary
logMessage("\n=== Cron job completed ===");
logMessage("Domains checked: {$stats['checked']}");
logMessage("Domains updated: {$stats['updated']}");
logMessage("Notifications sent: {$stats['notifications_sent']}");
logMessage("Errors: {$stats['errors']}");
logMessage("Domains queued for retry: {$stats['retried']}");
logMessage("Retries succeeded: {$stats['retry_succeeded']}");
logMessage("Execution time: $formattedTime");
logMessage("==========================\n");

exit(0);

