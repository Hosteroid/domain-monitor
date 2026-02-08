<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\Setting;
use App\Helpers\EmailHelper;
use App\Services\Logger;

class SettingsController extends Controller
{
    private Setting $settingModel;
    private Logger $logger;

    public function __construct()
    {
        Auth::requireAdmin();
        $this->settingModel = new Setting();
        $this->logger = new Logger('settings');
    }

    public function index()
    {
        $settings = $this->settingModel->getAllAsKeyValue();
        $appSettings = $this->settingModel->getAppSettings();
        $emailSettings = $this->settingModel->getEmailSettings();
        $captchaSettings = $this->settingModel->getCaptchaSettings();
        $twoFactorSettings = $this->settingModel->getTwoFactorSettings();
        $isolationSettings = $this->getIsolationSettings();
        
        // Predefined notification day options
        $notificationPresets = [
            'minimal' => [
                'label' => 'Minimal (30, 7, 1 days)',
                'value' => '30,7,1'
            ],
            'standard' => [
                'label' => 'Standard (60, 30, 21, 14, 7, 5, 3, 2, 1 days)',
                'value' => '60,30,21,14,7,5,3,2,1'
            ],
            'frequent' => [
                'label' => 'Frequent (90, 60, 45, 30, 21, 14, 10, 7, 5, 3, 2, 1 days)',
                'value' => '90,60,45,30,21,14,10,7,5,3,2,1'
            ],
            'business' => [
                'label' => 'Business Focused (60, 30, 14, 7, 3, 1 days)',
                'value' => '60,30,14,7,3,1'
            ],
            'conservative' => [
                'label' => 'Conservative (30, 15, 7, 3, 1 days)',
                'value' => '30,15,7,3,1'
            ],
            'custom' => [
                'label' => 'Custom',
                'value' => 'custom'
            ]
        ];

        // Check interval presets
        $checkIntervalPresets = [
            ['label' => 'Every 6 hours', 'value' => 6],
            ['label' => 'Every 12 hours', 'value' => 12],
            ['label' => 'Daily (24 hours)', 'value' => 24],
            ['label' => 'Every 2 days (48 hours)', 'value' => 48],
            ['label' => 'Weekly (168 hours)', 'value' => 168]
        ];

        // Status notification triggers
        $statusTriggers = $this->settingModel->getNotificationStatusTriggers();

        $this->view('settings/index', [
            'settings' => $settings,
            'appSettings' => $appSettings,
            'emailSettings' => $emailSettings,
            'captchaSettings' => $captchaSettings,
            'twoFactorSettings' => $twoFactorSettings,
            'isolationSettings' => $isolationSettings,
            'notificationPresets' => $notificationPresets,
            'checkIntervalPresets' => $checkIntervalPresets,
            'statusTriggers' => $statusTriggers,
            'title' => 'Settings'
        ]);
    }

    public function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#monitoring');

        try {
            // Update notification days
            $notificationPreset = $_POST['notification_preset'] ?? 'standard';
            
            if ($notificationPreset === 'custom') {
                // Custom days entered by user
                $customDays = trim($_POST['custom_notification_days'] ?? '');
                
                if (empty($customDays)) {
                    $_SESSION['error'] = 'Please enter notification days for custom preset';
                    $this->redirect('/settings#monitoring');
                    return;
                }
                
                // Validate custom days (comma-separated integers)
                $daysArray = array_map('trim', explode(',', $customDays));
                $daysArray = array_filter($daysArray, function($day) {
                    return is_numeric($day) && $day > 0;
                });
                
                if (empty($daysArray)) {
                    $_SESSION['error'] = 'Invalid notification days format. Use comma-separated numbers (e.g., 30,15,7,1)';
                    $this->redirect('/settings#monitoring');
                    return;
                }
                
                // Sort in descending order
                rsort($daysArray, SORT_NUMERIC);
                $notificationDays = implode(',', $daysArray);
            } else {
                // Use preset value
                $notificationDays = $_POST['notification_days_before'] ?? '30,15,7,3,1';
            }

            // Update check interval
            $checkInterval = (int)($_POST['check_interval_hours'] ?? 24);
            
            if ($checkInterval < 1 || $checkInterval > 720) { // Max 30 days
                $_SESSION['error'] = 'Check interval must be between 1 and 720 hours';
                $this->redirect('/settings#monitoring');
                return;
            }

            // Update status notification triggers
            $statusTriggers = $_POST['notification_status_triggers'] ?? [];
            if (!is_array($statusTriggers)) {
                $statusTriggers = [];
            }
            
            // Save settings
            $this->settingModel->setValue('notification_days_before', $notificationDays);
            $this->settingModel->setValue('check_interval_hours', $checkInterval);
            $this->settingModel->updateNotificationStatusTriggers($statusTriggers);

            $_SESSION['success'] = 'Settings updated successfully';
            $this->redirect('/settings#monitoring');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update settings: ' . $e->getMessage();
            $this->redirect('/settings#monitoring');
        }
    }

    public function testCron()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings');

        // Update last check run time to show the test worked
        $this->settingModel->updateLastCheckRun();
        
        $_SESSION['info'] = 'Test notification sent (feature coming soon). Last check time updated.';
        $this->redirect('/settings');
    }

    public function clearLogs()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#maintenance');

        try {
            // Clear notification logs older than 30 days
            $deleted = $this->settingModel->clearOldNotificationLogs(30);

            $_SESSION['success'] = "Cleared $deleted old notification log(s)";
            $this->redirect('/settings#maintenance');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to clear logs: ' . $e->getMessage();
            $this->redirect('/settings#maintenance');
        }
    }

    public function updateApp()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#app');

        try {
            $appSettings = [
                'app_name' => trim($_POST['app_name'] ?? 'Domain Monitor'),
                'app_url' => trim($_POST['app_url'] ?? 'http://localhost:8000'),
                'app_timezone' => trim($_POST['app_timezone'] ?? 'UTC')
            ];

            // Validate app_name
            if (empty($appSettings['app_name'])) {
                $_SESSION['error'] = 'Application name is required';
                $this->redirect('/settings#app');
                return;
            }

            // Validate app_url
            if (empty($appSettings['app_url']) || !filter_var($appSettings['app_url'], FILTER_VALIDATE_URL)) {
                $_SESSION['error'] = 'Please enter a valid application URL';
                $this->redirect('/settings#app');
                return;
            }

            // Validate timezone
            $validTimezones = timezone_identifiers_list();
            if (!in_array($appSettings['app_timezone'], $validTimezones)) {
                $_SESSION['error'] = 'Invalid timezone selected';
                $this->redirect('/settings#app');
                return;
            }

            // Update app settings
            $this->settingModel->updateAppSettings($appSettings);
            
            // Update registration settings
            $registrationEnabled = isset($_POST['registration_enabled']) ? '1' : '0';
            $requireEmailVerification = isset($_POST['require_email_verification']) ? '1' : '0';
            
            $this->settingModel->setValue('registration_enabled', $registrationEnabled);
            $this->settingModel->setValue('require_email_verification', $requireEmailVerification);
            
            $_SESSION['success'] = 'Application settings updated successfully';
            $this->redirect('/settings#app');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update application settings: ' . $e->getMessage();
            $this->redirect('/settings#app');
        }
    }

    public function updateEmail()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#email');

        try {
            $port = (int)trim($_POST['mail_port'] ?? '2525');
            $encryption = trim($_POST['mail_encryption'] ?? 'tls');
            
            // Auto-detect encryption based on port if not explicitly set
            $originalEncryption = $encryption;
            if (empty($encryption) || $encryption === 'tls') {
                if ($port === 465) {
                    $encryption = 'ssl'; // Port 465 should use SSL
                    $this->logger->info('Auto-detected SSL encryption for port 465', [
                        'port' => $port,
                        'original_encryption' => $originalEncryption,
                        'detected_encryption' => $encryption
                    ]);
                } elseif ($port === 587) {
                    $encryption = 'tls'; // Port 587 should use TLS
                    $this->logger->info('Auto-detected TLS encryption for port 587', [
                        'port' => $port,
                        'original_encryption' => $originalEncryption,
                        'detected_encryption' => $encryption
                    ]);
                }
                // For other ports, keep the user's selection
            }
            
            $emailSettings = [
                'mail_host' => trim($_POST['mail_host'] ?? ''),
                'mail_port' => $port,
                'mail_username' => trim($_POST['mail_username'] ?? ''),
                'mail_password' => trim($_POST['mail_password'] ?? ''),
                'mail_encryption' => $encryption,
                'mail_from_address' => trim($_POST['mail_from_address'] ?? ''),
                'mail_from_name' => trim($_POST['mail_from_name'] ?? 'Domain Monitor')
            ];

            // Validate required fields
            if (empty($emailSettings['mail_host'])) {
                $_SESSION['error'] = 'Mail host is required';
                $this->redirect('/settings#email');
                return;
            }

            if (empty($emailSettings['mail_from_address']) || !filter_var($emailSettings['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
                $_SESSION['error'] = 'Please enter a valid from email address';
                $this->redirect('/settings#email');
                return;
            }

            // Validate port
            if (!is_numeric($emailSettings['mail_port']) || $emailSettings['mail_port'] < 1 || $emailSettings['mail_port'] > 65535) {
                $_SESSION['error'] = 'Please enter a valid port number (1-65535)';
                $this->redirect('/settings#email');
                return;
            }

            $this->settingModel->updateEmailSettings($emailSettings);
            $_SESSION['success'] = 'Email settings updated successfully';
            $this->redirect('/settings#email');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update email settings: ' . $e->getMessage();
            $this->redirect('/settings#email');
        }
    }

    public function updateCaptcha()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#security');

        try {
            $captchaProvider = trim($_POST['captcha_provider'] ?? 'disabled');
            $captchaSiteKey = trim($_POST['captcha_site_key'] ?? '');
            $captchaSecretKey = trim($_POST['captcha_secret_key'] ?? '');
            $recaptchaV3Threshold = trim($_POST['recaptcha_v3_score_threshold'] ?? '0.5');

            // Validate provider
            $validProviders = ['disabled', 'recaptcha_v2', 'recaptcha_v3', 'turnstile'];
            if (!in_array($captchaProvider, $validProviders)) {
                $_SESSION['error'] = 'Invalid CAPTCHA provider selected';
                $this->redirect('/settings#security');
                return;
            }

            // If CAPTCHA is enabled, validate keys
            if ($captchaProvider !== 'disabled') {
                if (empty($captchaSiteKey)) {
                    $_SESSION['error'] = 'Site key is required when CAPTCHA is enabled';
                    $this->redirect('/settings#security');
                    return;
                }

                if (empty($captchaSecretKey)) {
                    $_SESSION['error'] = 'Secret key is required when CAPTCHA is enabled';
                    $this->redirect('/settings#security');
                    return;
                }
            }

            // Validate v3 score threshold
            if ($captchaProvider === 'recaptcha_v3') {
                $threshold = floatval($recaptchaV3Threshold);
                if ($threshold < 0.0 || $threshold > 1.0) {
                    $_SESSION['error'] = 'reCAPTCHA v3 score threshold must be between 0.0 and 1.0';
                    $this->redirect('/settings#security');
                    return;
                }
            }

            // Prepare settings array
            $captchaSettings = [
                'captcha_provider' => $captchaProvider,
                'captcha_site_key' => $captchaSiteKey,
                'recaptcha_v3_score_threshold' => $recaptchaV3Threshold
            ];

            // Only update secret key if provided (to allow updating other settings without re-entering secret)
            if (!empty($captchaSecretKey)) {
                $captchaSettings['captcha_secret_key'] = $captchaSecretKey;
            }

            // Update CAPTCHA settings
            $this->settingModel->updateCaptchaSettings($captchaSettings);

            $_SESSION['success'] = 'CAPTCHA settings updated successfully';
            $this->redirect('/settings#security');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update CAPTCHA settings: ' . $e->getMessage();
            $this->redirect('/settings#security');
        }
    }

    public function testEmail()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#email');

        $testEmail = trim($_POST['test_email'] ?? '');

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address';
            $this->redirect('/settings#email');
            return;
        }

        // Use EmailHelper to send test email
        $result = EmailHelper::sendTestEmail($testEmail);
        
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
            $this->logger->info('Test email sent successfully', [
                'email' => $testEmail
            ]);
        } else {
            // Log detailed error information for debugging
            $this->logger->error('Test email failed', [
                'email' => $testEmail,
                'debug_info' => $result['debug_info'] ?? null,
                'error' => $result['error'] ?? null
            ]);
            
            $_SESSION['error'] = $result['message'];
            
            // In development, show more detailed error
            if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                $_SESSION['error'] .= " (Debug: " . ($result['debug_info'] ?? $result['error']) . ")";
            }
        }
        
        $this->redirect('/settings#email');
    }

    public function updateTwoFactor()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#security');

        try {
            $twoFactorPolicy = trim($_POST['two_factor_policy'] ?? 'optional');
            $rateLimitMinutes = (int)($_POST['two_factor_rate_limit_minutes'] ?? 15);
            $emailCodeExpiryMinutes = (int)($_POST['two_factor_email_code_expiry_minutes'] ?? 10);

            // Validate policy
            $validPolicies = ['disabled', 'optional', 'forced'];
            if (!in_array($twoFactorPolicy, $validPolicies)) {
                $_SESSION['error'] = 'Invalid 2FA policy selected';
                $this->redirect('/settings#security');
                return;
            }

            // Validate rate limit (1-60 minutes)
            if ($rateLimitMinutes < 1 || $rateLimitMinutes > 60) {
                $_SESSION['error'] = 'Rate limit must be between 1 and 60 minutes';
                $this->redirect('/settings#security');
                return;
            }

            // Validate email code expiry (1-30 minutes)
            if ($emailCodeExpiryMinutes < 1 || $emailCodeExpiryMinutes > 30) {
                $_SESSION['error'] = 'Email code expiry must be between 1 and 30 minutes';
                $this->redirect('/settings#security');
                return;
            }

            $twoFactorSettings = [
                'two_factor_policy' => $twoFactorPolicy,
                'two_factor_rate_limit_minutes' => $rateLimitMinutes,
                'two_factor_email_code_expiry_minutes' => $emailCodeExpiryMinutes
            ];

            $this->settingModel->updateTwoFactorSettings($twoFactorSettings);
            
            $_SESSION['success'] = 'Two-Factor Authentication settings updated successfully';
            $this->redirect('/settings#security');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update 2FA settings: ' . $e->getMessage();
            $this->redirect('/settings#security');
        }
    }

    /**
     * Get isolation settings
     */
    private function getIsolationSettings(): array
    {
        return [
            'user_isolation_mode' => $this->settingModel->getValue('user_isolation_mode', 'shared')
        ];
    }

    /**
     * Toggle isolation mode
     */
    public function toggleIsolationMode()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings');
            return;
        }

        $this->verifyCsrf('/settings#isolation');

        $newMode = $_POST['user_isolation_mode'] ?? 'shared';

        try {
            if ($newMode === 'isolated') {
                // Check if we have any admin users
                $userModel = new \App\Models\User();
                $adminUser = $userModel->getFirstAdminUser();
                if (!$adminUser) {
                    $_SESSION['error'] = 'No admin users found. Please create an admin user first.';
                    $this->redirect('/settings#isolation');
                    return;
                }

                // Run migration
                $migrationResult = $this->migrateToIsolatedMode();
                if (!$migrationResult['success']) {
                    $_SESSION['error'] = 'Migration failed: ' . $migrationResult['error'];
                    $this->redirect('/settings#isolation');
                    return;
                }

                $_SESSION['success'] = "Isolation mode enabled. {$migrationResult['domains_assigned']} domains, {$migrationResult['groups_assigned']} groups, and {$migrationResult['tags_assigned']} tags assigned to admin.";
            } else {
                // Switching back to shared mode
                $this->settingModel->setValue('user_isolation_mode', 'shared');
                $_SESSION['success'] = 'Switched to shared mode. All users can now see all domains and groups.';
            }

            $this->redirect('/settings#isolation');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Error updating isolation mode: ' . $e->getMessage();
            $this->redirect('/settings#isolation');
        }
    }

    /**
     * Migrate existing data to isolated mode
     */
    private function migrateToIsolatedMode(): array
    {
        try {
            // Get the first admin user
            $userModel = new \App\Models\User();
            $adminUser = $userModel->getFirstAdminUser();
            
            if (!$adminUser) {
                throw new \Exception('No admin user found. Please create an admin user first.');
            }
            
            $adminId = $adminUser['id'];
            
            // Assign all domains to admin
            $domainModel = new \App\Models\Domain();
            $domainCount = $domainModel->assignUnassignedDomainsToUser($adminId);
            
            // Assign all groups to admin
            $groupModel = new \App\Models\NotificationGroup();
            $groupCount = $groupModel->assignUnassignedGroupsToUser($adminId);
            
            // Assign all tags to admin
            $tagModel = new \App\Models\Tag();
            $tagCount = $tagModel->assignUnassignedTagsToUser($adminId);
            
            // Set isolation mode
            $this->settingModel->setValue('user_isolation_mode', 'isolated');
            
            return [
                'success' => true,
                'admin_id' => $adminId,
                'domains_assigned' => $domainCount,
                'groups_assigned' => $groupCount,
                'tags_assigned' => $tagCount
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

