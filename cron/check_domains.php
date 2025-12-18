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
use App\Models\NotificationGroup;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\User;
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
$groupModel = new NotificationGroup();
$logModel = new NotificationLog();
$notificationModel = new \App\Models\Notification();
$settingModel = new Setting();
$userModel = new User();
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
    'retry_succeeded' => 0,
    'in_app_notifications_created' => 0,
    'domains_with_notifications' => 0,
    'notification_groups_used' => [],
    'domains_notified' => []
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

        // Refresh domain data with group info
        $domainData = $domainModel->find($domain['id']);
        
        // Send external notifications (email, telegram, etc.) if notification group is assigned
        // Check if external alert was already sent recently (within last 23 hours)
        $shouldSendExternal = false;
        if ($domain['notification_group_id']) {
            if (!$logModel->wasSentRecently($domain['id'], $notificationType, 23)) {
                $shouldSendExternal = true;
            } else {
                logMessage("  → External notification already sent recently (skipping external alerts)");
            }
        }
        
        if ($shouldSendExternal) {
            $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);

            if (!empty($channels)) {
                logMessage("  📤 Sending external notifications to " . count($channels) . " channel(s)");
                
                // Send external notifications (email, telegram, etc.)
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
            } else {
                logMessage("  → No active notification channels configured in group");
            }
        } elseif (!$domain['notification_group_id']) {
            logMessage("  → No notification group assigned (skipping external alerts, but will create in-app notification)");
        }

        // Create in-app notification (bell icon) for users
        // Handle user isolation: 
        // - Isolated mode: send only to domain owner
        // - Shared mode: send to all active users (company-wide notifications)
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        $usersToNotify = [];
        
        if ($isolationMode === 'isolated') {
            // Isolated mode: only notify the domain owner
            $notificationUserId = null;
            
            if (!empty($domainData['user_id'])) {
                $notificationUserId = $domainData['user_id'];
            } elseif (!empty($domain['notification_group_id'])) {
                // Fallback to notification group owner
                $group = $groupModel->find($domain['notification_group_id']);
                if ($group && !empty($group['user_id'])) {
                    $notificationUserId = $group['user_id'];
                }
            }
            
            if ($notificationUserId) {
                $usersToNotify[] = $notificationUserId;
            }
        } else {
            // Shared mode: notify all active users (company-wide)
            $allUsers = $userModel->where('is_active', 1);
            foreach ($allUsers as $user) {
                $usersToNotify[] = $user['id'];
            }
            logMessage("  → Shared mode: Notifying all " . count($usersToNotify) . " active user(s)");
        }
        
        // Send notifications to all identified users
        // Check if in-app notification was already created recently (within last 23 hours) to prevent duplicates
        if (!empty($usersToNotify)) {
            $notifiedCount = 0;
            $notificationTypeForInApp = $daysLeft <= 0 ? 'domain_expired' : 'domain_expiring';
            
            foreach ($usersToNotify as $userId) {
                // Check if this user already has a notification for this domain and type within last 23 hours
                $db = \Core\Database::getConnection();
                $stmt = $db->prepare(
                    "SELECT COUNT(*) as count FROM user_notifications 
                     WHERE user_id = ? 
                     AND domain_id = ? 
                     AND type = ?
                     AND created_at >= DATE_SUB(NOW(), INTERVAL 23 HOUR)"
                );
                $stmt->execute([$userId, $domain['id'], $notificationTypeForInApp]);
                $result = $stmt->fetch();
                
                if ($result && $result['count'] > 0) {
                    // Notification already exists for this user, skip
                    continue;
                }
                
                try {
                    if ($daysLeft <= 0) {
                        $notificationService->notifyDomainExpired(
                            $userId,
                            $domainName,
                            $domain['id']
                        );
                    } else {
                        $notificationService->notifyDomainExpiring(
                            $userId,
                            $domainName,
                            $daysLeft,
                            $domain['id']
                        );
                    }
                    $notifiedCount++;
                } catch (Exception $e) {
                    logMessage("  ⚠ Failed to create in-app notification for user $userId: " . $e->getMessage());
                }
            }
            if ($notifiedCount > 0) {
                $statusText = $daysLeft <= 0 ? "Domain expired" : "Domain expiring in $daysLeft days";
                logMessage("  🔔 Created in-app notifications for $notifiedCount user(s): $statusText");
                $stats['in_app_notifications_created'] += $notifiedCount;
                $stats['domains_with_notifications']++;
                
                // Track which domain got notifications
                $stats['domains_notified'][] = [
                    'domain' => $domainName,
                    'days_left' => $daysLeft,
                    'users_notified' => $notifiedCount,
                    'has_group' => !empty($domain['notification_group_id']),
                    'group_id' => $domain['notification_group_id'] ?? null
                ];
                
                // Track notification groups used
                if (!empty($domain['notification_group_id'])) {
                    $groupId = $domain['notification_group_id'];
                    if (!isset($stats['notification_groups_used'][$groupId])) {
                        $stats['notification_groups_used'][$groupId] = 0;
                    }
                    $stats['notification_groups_used'][$groupId]++;
                }
            } elseif (count($usersToNotify) > 0) {
                logMessage("  → In-app notifications already exist for all users (skipping duplicates)");
            }
        } else {
            logMessage("  → No users to notify, skipping in-app notification (external alerts still sent)");
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
                            $domainData = $domainModel->find($domain['id']);
                            
                            // Send external notifications (email, telegram, etc.) if notification group is assigned
                            if ($domain['notification_group_id']) {
                                $channels = $channelModel->getActiveByGroupId($domain['notification_group_id']);
                                if (!empty($channels)) {
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

                            // Create in-app notification (bell icon) for users
                            // Handle user isolation: 
                            // - Isolated mode: send only to domain owner
                            // - Shared mode: send to all active users (company-wide notifications)
                            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
                            $usersToNotify = [];
                            
                            if ($isolationMode === 'isolated') {
                                // Isolated mode: only notify the domain owner
                                $notificationUserId = null;
                                
                                if (!empty($domainData['user_id'])) {
                                    $notificationUserId = $domainData['user_id'];
                                } elseif (!empty($domain['notification_group_id'])) {
                                    // Fallback to notification group owner
                                    $group = $groupModel->find($domain['notification_group_id']);
                                    if ($group && !empty($group['user_id'])) {
                                        $notificationUserId = $group['user_id'];
                                    }
                                }
                                
                                if ($notificationUserId) {
                                    $usersToNotify[] = $notificationUserId;
                                }
                            } else {
                                // Shared mode: notify all active users (company-wide)
                                $allUsers = $userModel->where('is_active', 1);
                                foreach ($allUsers as $user) {
                                    $usersToNotify[] = $user['id'];
                                }
                            }
                            
                            // Send notifications to all identified users
                            if (!empty($usersToNotify)) {
                                foreach ($usersToNotify as $userId) {
                                    try {
                                        if ($daysLeft <= 0) {
                                            $notificationService->notifyDomainExpired(
                                                $userId,
                                                $domainName,
                                                $domain['id']
                                            );
                                        } else {
                                            $notificationService->notifyDomainExpiring(
                                                $userId,
                                                $domainName,
                                                $daysLeft,
                                                $domain['id']
                                            );
                                        }
                                    } catch (Exception $e) {
                                        // Silently fail for retry queue to avoid interrupting retry process
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

logMessage("==========================\n");

exit(0);

