<?php
$title = 'Settings';
$pageTitle = 'System Settings';
$pageDescription = 'Configure application, email, and monitoring settings';
$pageIcon = 'fas fa-cog';
ob_start();

$currentNotificationDays = $settings['notification_days_before'] ?? '30,15,7,3,1';
$currentCheckInterval = $settings['check_interval_hours'] ?? '24';
$lastCheckRun = $settings['last_check_run'] ?? null;

// Get timezone list (popular ones first)
$popularTimezones = [
    'UTC' => 'UTC',
    'America/New_York' => 'Eastern Time (US)',
    'America/Chicago' => 'Central Time (US)',
    'America/Denver' => 'Mountain Time (US)',
    'America/Los_Angeles' => 'Pacific Time (US)',
    'Europe/London' => 'London',
    'Europe/Paris' => 'Paris',
    'Asia/Tokyo' => 'Tokyo',
    'Australia/Sydney' => 'Sydney'
];

// Determine which preset is selected
$selectedPreset = 'custom';
foreach ($notificationPresets as $key => $preset) {
    if ($preset['value'] === $currentNotificationDays) {
        $selectedPreset = $key;
        break;
    }
}
?>

<!-- Tabs Navigation -->
<div class="bg-white rounded-lg border border-gray-200 mb-6">
    <div class="border-b border-gray-200">
        <nav class="flex -mb-px overflow-x-auto">
            <button onclick="switchTab('app')" id="tab-app" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-cog mr-2"></i>
                Application
            </button>
            <button onclick="switchTab('email')" id="tab-email" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-envelope mr-2"></i>
                Email
            </button>
            <button onclick="switchTab('monitoring')" id="tab-monitoring" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-bell mr-2"></i>
                Monitoring
            </button>
            <button onclick="switchTab('isolation')" id="tab-isolation" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-users mr-2"></i>
                User Isolation
            </button>
            <button onclick="switchTab('security')" id="tab-security" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-shield-alt mr-2"></i>
                Security
            </button>
            <button onclick="switchTab('system')" id="tab-system" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-server mr-2"></i>
                System
            </button>
            <button onclick="switchTab('maintenance')" id="tab-maintenance" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-tools mr-2"></i>
                Maintenance
            </button>
        </nav>
    </div>
</div>

<!-- Tab Content: Application Settings -->
<div id="content-app" class="tab-content">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Application Settings</h3>
            <p class="text-sm text-gray-600 mt-1">Configure basic application information</p>
        </div>

        <form method="POST" action="/settings/update-app" class="p-6">
            <?= csrf_field() ?>
            <div class="space-y-4">
                <div>
                    <label for="app_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Application Name
                    </label>
                    <input type="text" id="app_name" name="app_name" required
                           value="<?= htmlspecialchars($appSettings['app_name']) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-gray-500 mt-1">Name displayed in the interface</p>
                </div>

                <div>
                    <label for="app_url" class="block text-sm font-medium text-gray-700 mb-2">
                        Application URL
                    </label>
                    <input type="url" id="app_url" name="app_url" required
                           value="<?= htmlspecialchars($appSettings['app_url']) ?>"
                           placeholder="https://domains.example.com"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-gray-500 mt-1">Base URL for the application (used in emails and links)</p>
                </div>

                <div>
                    <label for="app_timezone" class="block text-sm font-medium text-gray-700 mb-2">
                        Timezone
                    </label>
                    <select id="app_timezone" name="app_timezone" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <?php foreach ($popularTimezones as $tz => $label): ?>
                            <option value="<?= htmlspecialchars($tz) ?>" <?= $appSettings['app_timezone'] === $tz ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                        <option disabled>──────────</option>
                        <?php 
                        $allTimezones = timezone_identifiers_list();
                        foreach ($allTimezones as $tz): 
                            if (!isset($popularTimezones[$tz])):
                        ?>
                            <option value="<?= htmlspecialchars($tz) ?>" <?= $appSettings['app_timezone'] === $tz ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tz) ?>
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Application timezone for dates and times</p>
                </div>

                <!-- User Registration Settings -->
                <div class="border-t border-gray-200 pt-4 mt-6">
                    <h4 class="text-base font-semibold text-gray-900 mb-4">User Registration</h4>
                    
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="registration_enabled" name="registration_enabled" value="1"
                                       <?= !empty($settings['registration_enabled']) ? 'checked' : '' ?>
                                       class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
                            </div>
                            <div class="ml-3">
                                <label for="registration_enabled" class="text-sm font-medium text-gray-700">
                                    Enable User Registration
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Allow new users to create accounts via registration form</p>
                            </div>
                        </div>

                        <div class="flex items-start">
                            <div class="flex items-center h-5">
                                <input type="checkbox" id="require_email_verification" name="require_email_verification" value="1"
                                       <?= !empty($settings['require_email_verification']) ? 'checked' : '' ?>
                                       class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
                            </div>
                            <div class="ml-3">
                                <label for="require_email_verification" class="text-sm font-medium text-gray-700">
                                    Require Email Verification
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Users must verify their email address before accessing the system</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save Application Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tab Content: Email Settings -->
<div id="content-email" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Email Settings</h3>
            <p class="text-sm text-gray-600 mt-1">Configure SMTP server for sending notifications</p>
        </div>

        <form method="POST" action="/settings/update-email" class="p-6">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="mail_host" class="block text-sm font-medium text-gray-700 mb-2">
                        SMTP Host <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="mail_host" name="mail_host" required
                           value="<?= htmlspecialchars($emailSettings['mail_host']) ?>"
                           placeholder="smtp.mailtrap.io"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <div>
                    <label for="mail_port" class="block text-sm font-medium text-gray-700 mb-2">
                        SMTP Port <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="mail_port" name="mail_port" required
                           value="<?= htmlspecialchars($emailSettings['mail_port']) ?>"
                           placeholder="2525"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <div>
                    <label for="mail_encryption" class="block text-sm font-medium text-gray-700 mb-2">
                        Encryption <span class="text-blue-500 text-xs">(Auto-detected by port)</span>
                    </label>
                    <select id="mail_encryption" name="mail_encryption"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="tls" <?= $emailSettings['mail_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                        <option value="ssl" <?= $emailSettings['mail_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (SMTPS)</option>
                        <option value="" <?= empty($emailSettings['mail_encryption']) ? 'selected' : '' ?>>None</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-magic text-blue-600 mr-1"></i>
                        <span id="encryption-help">Will auto-update based on port selection</span>
                    </p>
                </div>

                <div class="md:col-span-2">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs text-gray-600">
                            <i class="fas fa-info-circle text-gray-400 mr-1"></i>
                            <strong>Protocol:</strong> This application uses SMTP (Simple Mail Transfer Protocol) for sending emails.
                        </p>
                    </div>
                </div>

                <div>
                    <label for="mail_username" class="block text-sm font-medium text-gray-700 mb-2">
                        SMTP Username
                    </label>
                    <input type="text" id="mail_username" name="mail_username"
                           value="<?= htmlspecialchars($emailSettings['mail_username']) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <div>
                    <label for="mail_password" class="block text-sm font-medium text-gray-700 mb-2">
                        SMTP Password
                    </label>
                    <input type="password" id="mail_password" name="mail_password"
                           value="<?= htmlspecialchars($emailSettings['mail_password']) ?>"
                           placeholder="••••••••"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-lock text-green-600 mr-1"></i>
                        Encrypted before storing in database
                    </p>
                </div>

                <div>
                    <label for="mail_from_address" class="block text-sm font-medium text-gray-700 mb-2">
                        From Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" id="mail_from_address" name="mail_from_address" required
                           value="<?= htmlspecialchars($emailSettings['mail_from_address']) ?>"
                           placeholder="noreply@domainmonitor.com"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>

                <div>
                    <label for="mail_from_name" class="block text-sm font-medium text-gray-700 mb-2">
                        From Name
                    </label>
                    <input type="text" id="mail_from_name" name="mail_from_name"
                           value="<?= htmlspecialchars($emailSettings['mail_from_name']) ?>"
                           placeholder="Domain Monitor"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
            </div>

            <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save Email Settings
                </button>
            </div>
        </form>

        <!-- Test Email Section -->
        <div class="px-6 pb-6">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Test Email Configuration</h4>
                        <p class="text-sm text-gray-700 mb-3">
                            Send a test email to verify your SMTP settings are configured correctly.
                        </p>
                        <form method="POST" action="/settings/test-email" id="testEmailForm" class="flex gap-2">
                            <?= csrf_field() ?>
                            <input type="email" name="test_email" id="test_email" required
                                   placeholder="Enter email address to receive test"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-medium">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Send Test Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Content: Monitoring Settings -->
<div id="content-monitoring" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Monitoring Settings</h3>
            <p class="text-sm text-gray-600 mt-1">Configure notification schedules and check intervals</p>
        </div>

        <form method="POST" action="/settings/update" id="settingsForm" class="p-6">
            <?= csrf_field() ?>
            
            <!-- Notification Settings -->
            <div class="mb-6">
                <h4 class="text-base font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-bell text-primary mr-2"></i>
                    Notification Schedule
                </h4>
                
                <div class="space-y-4">
                    <div>
                        <label for="notification_preset" class="block text-sm font-medium text-gray-700 mb-2">
                            Choose Preset
                        </label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary" 
                                id="notification_preset" name="notification_preset">
                            <?php foreach ($notificationPresets as $key => $preset): ?>
                                <option value="<?= htmlspecialchars($key) ?>" 
                                        data-value="<?= htmlspecialchars($preset['value']) ?>"
                                        <?= $selectedPreset === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($preset['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" id="notification_days_before" name="notification_days_before" 
                           value="<?= htmlspecialchars($currentNotificationDays) ?>">

                    <!-- Custom days input -->
                    <div id="custom_days_container" style="display: <?= $selectedPreset === 'custom' ? 'block' : 'none' ?>;">
                        <label for="custom_notification_days" class="block text-sm font-medium text-gray-700 mb-2">
                            Custom Days
                        </label>
                        <input type="text" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary" 
                               id="custom_notification_days" 
                               name="custom_notification_days" 
                               value="<?= $selectedPreset === 'custom' ? htmlspecialchars($currentNotificationDays) : '' ?>"
                               placeholder="e.g., 90,60,30,14,7,3,1">
                        <p class="text-xs text-gray-500 mt-1">Comma-separated numbers (will be sorted automatically)</p>
                    </div>

                    <!-- Preview -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <p class="text-sm text-gray-700">
                            <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                            Alerts at: <span id="days_preview" class="font-semibold text-primary"><?= htmlspecialchars($currentNotificationDays) ?></span> days
                        </p>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-200 my-6"></div>

            <!-- Status Change Notifications -->
            <div class="mb-6">
                <h4 class="text-base font-semibold text-gray-900 mb-2 flex items-center">
                    <i class="fas fa-exchange-alt text-primary mr-2"></i>
                    Status Change Notifications
                </h4>
                <p class="text-sm text-gray-600 mb-4">Choose which domain status changes should trigger notifications (both in-app and external channels).</p>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <!-- Available -->
                    <label class="flex items-start p-3 bg-blue-50 border border-blue-200 rounded-lg cursor-pointer hover:bg-blue-100 transition-colors">
                        <input type="checkbox" name="notification_status_triggers[]" value="available"
                               <?= in_array('available', $statusTriggers) ? 'checked' : '' ?>
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mt-0.5">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-blue-800">
                                <i class="fas fa-check-circle mr-1"></i> Available
                            </span>
                            <p class="text-xs text-blue-600 mt-0.5">Domain becomes available for registration</p>
                        </div>
                    </label>

                    <!-- Registered -->
                    <label class="flex items-start p-3 bg-green-50 border border-green-200 rounded-lg cursor-pointer hover:bg-green-100 transition-colors">
                        <input type="checkbox" name="notification_status_triggers[]" value="registered"
                               <?= in_array('registered', $statusTriggers) ? 'checked' : '' ?>
                               class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500 mt-0.5">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-green-800">
                                <i class="fas fa-globe mr-1"></i> Registered
                            </span>
                            <p class="text-xs text-green-600 mt-0.5">Domain becomes registered / active</p>
                        </div>
                    </label>

                    <!-- Expired -->
                    <label class="flex items-start p-3 bg-red-50 border border-red-200 rounded-lg cursor-pointer hover:bg-red-100 transition-colors">
                        <input type="checkbox" name="notification_status_triggers[]" value="expired"
                               <?= in_array('expired', $statusTriggers) ? 'checked' : '' ?>
                               class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500 mt-0.5">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-red-800">
                                <i class="fas fa-times-circle mr-1"></i> Expired
                            </span>
                            <p class="text-xs text-red-600 mt-0.5">Domain status changes to expired</p>
                        </div>
                    </label>

                    <!-- Redemption Period -->
                    <label class="flex items-start p-3 bg-amber-50 border border-amber-200 rounded-lg cursor-pointer hover:bg-amber-100 transition-colors">
                        <input type="checkbox" name="notification_status_triggers[]" value="redemption_period"
                               <?= in_array('redemption_period', $statusTriggers) ? 'checked' : '' ?>
                               class="w-4 h-4 text-amber-600 border-gray-300 rounded focus:ring-amber-500 mt-0.5">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-amber-800">
                                <i class="fas fa-hourglass-half mr-1"></i> Redemption Period
                            </span>
                            <p class="text-xs text-amber-600 mt-0.5">Domain enters redemption period (recovery fees apply)</p>
                        </div>
                    </label>

                    <!-- Pending Delete -->
                    <label class="flex items-start p-3 bg-rose-50 border border-rose-200 rounded-lg cursor-pointer hover:bg-rose-100 transition-colors">
                        <input type="checkbox" name="notification_status_triggers[]" value="pending_delete"
                               <?= in_array('pending_delete', $statusTriggers) ? 'checked' : '' ?>
                               class="w-4 h-4 text-rose-600 border-gray-300 rounded focus:ring-rose-500 mt-0.5">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-rose-800">
                                <i class="fas fa-trash-alt mr-1"></i> Pending Delete
                            </span>
                            <p class="text-xs text-rose-600 mt-0.5">Domain is scheduled for deletion</p>
                        </div>
                    </label>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mt-3">
                    <p class="text-xs text-gray-600">
                        <i class="fas fa-info-circle text-gray-400 mr-1"></i>
                        <strong>Note:</strong> These notifications are triggered when a domain's status changes during a WHOIS check. 
                        Redemption Period and Pending Delete detection depends on the TLD registry reporting EPP statuses. 
                        Most gTLDs (.com, .net, .org) support this, but some ccTLDs may not.
                    </p>
                </div>
            </div>

            <div class="border-t border-gray-200 my-6"></div>

            <!-- Check Interval -->
            <div class="mb-6">
                <h4 class="text-base font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-clock text-primary mr-2"></i>
                    Domain Check Interval
                </h4>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="check_interval_hours" class="block text-sm font-medium text-gray-700 mb-2">
                            Check Every
                        </label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary" 
                                id="check_interval_hours" name="check_interval_hours">
                            <?php foreach ($checkIntervalPresets as $preset): ?>
                                <option value="<?= $preset['value'] ?>" 
                                        <?= $currentCheckInterval == $preset['value'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($preset['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Last Check Run
                        </label>
                        <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                            <?php if ($lastCheckRun): ?>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                    <span class="text-gray-700"><?= date('M d, Y H:i', strtotime($lastCheckRun)) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-minus-circle text-gray-400 mr-2"></i>
                                    <span class="text-gray-500">Never run</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save Monitoring Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tab Content: User Isolation Settings -->
<div id="content-isolation" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">User Isolation Settings</h3>
            <p class="text-sm text-gray-600 mt-1">Configure how users see domains, groups, and tags</p>
        </div>

        <form method="POST" action="/settings/toggle-isolation" class="p-6">
            <?= csrf_field() ?>
            
            <div class="space-y-6">
                <!-- Isolation Mode -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        User Data Visibility
                    </label>
                    <select name="user_isolation_mode" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="shared" <?= $isolationSettings['user_isolation_mode'] === 'shared' ? 'selected' : '' ?>>
                            Shared - All users see all domains, groups, and tags
                        </option>
                        <option value="isolated" <?= $isolationSettings['user_isolation_mode'] === 'isolated' ? 'selected' : '' ?>>
                            Isolated - Users only see their own domains, groups, and tags
                        </option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <strong>Shared:</strong> Current behavior - everyone sees everything<br>
                        <strong>Isolated:</strong> Users only see what they created
                    </p>
                </div>

                <?php if ($isolationSettings['user_isolation_mode'] === 'shared'): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-1"></i>
                            <div class="ml-3">
                                <h4 class="text-sm font-medium text-yellow-800">Migration Notice</h4>
                                <p class="text-sm text-yellow-700 mt-1">
                                    When switching to isolated mode, all existing domains and groups will be assigned to the first admin user. 
                                    You can then transfer them to other users as needed.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isolationSettings['user_isolation_mode'] === 'isolated'): ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mt-1"></i>
                            <div class="ml-3">
                                <h4 class="text-sm font-medium text-blue-800">Isolation Mode Active</h4>
                                <p class="text-sm text-blue-700 mt-1">
                                    Users can only see their own domains, groups, and tags. Admins can transfer domains and groups between users.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                    <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                        <i class="fas fa-save mr-2"></i>
                        Update Isolation Mode
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Tab Content: Security Settings -->
<div id="content-security" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Security Settings</h3>
            <p class="text-sm text-gray-600 mt-1">Configure CAPTCHA protection for authentication forms</p>
        </div>

        <!-- CAPTCHA Settings -->
        <form method="POST" action="/settings/update-captcha" class="p-6">
            <?= csrf_field() ?>
            <div class="space-y-4">
                <!-- CAPTCHA Provider Selection -->
                <div>
                    <label for="captcha_provider" class="block text-sm font-medium text-gray-700 mb-2">
                        CAPTCHA Provider
                    </label>
                    <select id="captcha_provider" name="captcha_provider" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="disabled" <?= ($captchaSettings['provider'] ?? 'disabled') === 'disabled' ? 'selected' : '' ?>>
                            Disabled (No CAPTCHA)
                        </option>
                        <option value="recaptcha_v2" <?= ($captchaSettings['provider'] ?? '') === 'recaptcha_v2' ? 'selected' : '' ?>>
                            Google reCAPTCHA v2 (Checkbox)
                        </option>
                        <option value="recaptcha_v3" <?= ($captchaSettings['provider'] ?? '') === 'recaptcha_v3' ? 'selected' : '' ?>>
                            Google reCAPTCHA v3 (Invisible)
                        </option>
                        <option value="turnstile" <?= ($captchaSettings['provider'] ?? '') === 'turnstile' ? 'selected' : '' ?>>
                            Cloudflare Turnstile
                        </option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">CAPTCHA protects login, registration, and password reset forms</p>
                </div>

                <!-- CAPTCHA Configuration Fields (shown when enabled) -->
                <div id="captcha_config" style="display: <?= ($captchaSettings['provider'] ?? 'disabled') !== 'disabled' ? 'block' : 'none' ?>;">
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <!-- Site Key -->
                        <div class="mb-4">
                            <label for="captcha_site_key" class="block text-sm font-medium text-gray-700 mb-2">
                                Site Key (Public Key)
                            </label>
                            <input type="text" id="captcha_site_key" name="captcha_site_key"
                                   value="<?= htmlspecialchars($captchaSettings['site_key'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                                   placeholder="Enter your site/public key">
                            <p class="text-xs text-gray-500 mt-1">Public key visible in HTML source</p>
                        </div>

                        <!-- Secret Key -->
                        <div class="mb-4">
                            <label for="captcha_secret_key" class="block text-sm font-medium text-gray-700 mb-2">
                                Secret Key
                            </label>
                            <input type="password" id="captcha_secret_key" name="captcha_secret_key"
                                   value=""
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                                   placeholder="<?= !empty($captchaSettings['secret_key']) ? '••••••••••••••••' : 'Enter your secret key' ?>">
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-lock text-green-600 mr-1"></i>
                                Encrypted before storing in database. Leave blank to keep existing key.
                            </p>
                        </div>

                        <!-- reCAPTCHA v3 Score Threshold (only for v3) -->
                        <div id="recaptcha_v3_threshold" style="display: <?= ($captchaSettings['provider'] ?? '') === 'recaptcha_v3' ? 'block' : 'none' ?>;">
                            <label for="recaptcha_v3_score_threshold" class="block text-sm font-medium text-gray-700 mb-2">
                                reCAPTCHA v3 Score Threshold
                            </label>
                            <input type="number" id="recaptcha_v3_score_threshold" name="recaptcha_v3_score_threshold"
                                   value="<?= htmlspecialchars($captchaSettings['score_threshold'] ?? '0.5') ?>"
                                   min="0.0" max="1.0" step="0.1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                            <p class="text-xs text-gray-500 mt-1">Minimum score required (0.0 to 1.0). Default: 0.5. Lower = more permissive.</p>
                        </div>
                    </div>

                    <!-- Provider-specific Documentation -->
                    <div id="captcha_docs" class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-4">
                        <p class="text-sm font-medium text-gray-900 mb-2">
                            <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                            <span id="captcha_docs_title">Setup Instructions</span>
                        </p>
                        <div id="docs_recaptcha_v2" class="text-sm text-gray-700" style="display: none;">
                            <p class="mb-1">1. Visit <a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-primary hover:underline">Google reCAPTCHA Admin Console</a></p>
                            <p class="mb-1">2. Register a new site with reCAPTCHA v2 "I'm not a robot" Checkbox</p>
                            <p>3. Copy the Site Key and Secret Key to the fields above</p>
                        </div>
                        <div id="docs_recaptcha_v3" class="text-sm text-gray-700" style="display: none;">
                            <p class="mb-1">1. Visit <a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-primary hover:underline">Google reCAPTCHA Admin Console</a></p>
                            <p class="mb-1">2. Register a new site with reCAPTCHA v3</p>
                            <p class="mb-1">3. Copy the Site Key and Secret Key to the fields above</p>
                            <p>4. Adjust the score threshold based on your security needs (0.5 is recommended)</p>
                        </div>
                        <div id="docs_turnstile" class="text-sm text-gray-700" style="display: none;">
                            <p class="mb-1">1. Visit <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" class="text-primary hover:underline">Cloudflare Turnstile Dashboard</a></p>
                            <p class="mb-1">2. Create a new Turnstile widget</p>
                            <p class="mb-1">3. Choose "Managed" mode for best user experience</p>
                            <p>4. Copy the Site Key and Secret Key to the fields above</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save Security Settings
                </button>
            </div>
        </form>
    </div>
    <!-- Two-Factor Authentication Settings -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mt-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Two-Factor Authentication</h3>
            <p class="text-sm text-gray-600 mt-1">Configure 2FA policy and security settings</p>
        </div>

        <form method="POST" action="/settings/update-two-factor" class="p-6">
            <?= csrf_field() ?>
            <div class="space-y-4">
                <div>
                    <label for="two_factor_policy" class="block text-sm font-medium text-gray-700 mb-2">
                        2FA Policy
                    </label>
                    <select id="two_factor_policy" name="two_factor_policy" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="disabled" <?= ($twoFactorSettings['policy'] ?? 'optional') === 'disabled' ? 'selected' : '' ?>>
                            Disabled - No 2FA features available
                        </option>
                        <option value="optional" <?= ($twoFactorSettings['policy'] ?? 'optional') === 'optional' ? 'selected' : '' ?>>
                            Optional - Users can choose to enable 2FA
                        </option>
                        <option value="forced" <?= ($twoFactorSettings['policy'] ?? 'optional') === 'forced' ? 'selected' : '' ?>>
                            Forced - All users must enable 2FA (email verification required)
                        </option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                        Users must have verified email addresses to enable 2FA
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="two_factor_rate_limit_minutes" class="block text-sm font-medium text-gray-700 mb-2">
                            Rate Limit (minutes)
                        </label>
                        <input type="number" id="two_factor_rate_limit_minutes" name="two_factor_rate_limit_minutes"
                               value="<?= htmlspecialchars($twoFactorSettings['rate_limit_minutes'] ?? 15) ?>"
                               min="1" max="60"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <p class="text-xs text-gray-500 mt-1">Maximum failed attempts per IP address</p>
                    </div>

                    <div>
                        <label for="two_factor_email_code_expiry_minutes" class="block text-sm font-medium text-gray-700 mb-2">
                            Email Code Expiry (minutes)
                        </label>
                        <input type="number" id="two_factor_email_code_expiry_minutes" name="two_factor_email_code_expiry_minutes"
                               value="<?= htmlspecialchars($twoFactorSettings['email_code_expiry_minutes'] ?? 10) ?>"
                               min="1" max="30"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <p class="text-xs text-gray-500 mt-1">How long email backup codes remain valid</p>
                    </div>
                </div>

                <!-- 2FA Info Box -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm font-medium text-gray-900 mb-2">
                        <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                        Two-Factor Authentication Features
                    </p>
                    <ul class="text-sm text-gray-700 space-y-1">
                        <li>• <strong>TOTP Authenticator Apps:</strong> Google Authenticator, Authy, Microsoft Authenticator</li>
                        <li>• <strong>Email Backup Codes:</strong> One-time codes sent to verified email addresses</li>
                        <li>• <strong>Backup Recovery Codes:</strong> 8 single-use codes generated during setup</li>
                        <li>• <strong>Rate Limiting:</strong> Prevents brute force attacks on verification codes</li>
                    </ul>
                </div>
            </div>

            <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium">
                    <i class="fas fa-shield-alt mr-2"></i>
                    Save 2FA Settings
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Tab Content: System Information -->
<div id="content-system" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">System Information</h3>
            <p class="text-sm text-gray-600 mt-1">Cron job configuration and log file locations</p>
        </div>

        <div class="p-6 space-y-6">
            <!-- Cron Command -->
            <div>
                <h4 class="text-sm font-semibold text-gray-900 mb-2 flex items-center">
                    <i class="fas fa-terminal text-blue-500 mr-2"></i>
                    Cron Job Command
                </h4>
                <div class="bg-gray-900 text-gray-100 px-4 py-3 rounded-lg font-mono text-sm">
                    <code>php cron/check_domains.php</code>
                </div>
            </div>

            <!-- Crontab Entry -->
            <div>
                <h4 class="text-sm font-semibold text-gray-900 mb-2 flex items-center">
                    <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                    Recommended Crontab Entry
                </h4>
                <div class="bg-gray-900 text-gray-100 px-4 py-3 rounded-lg font-mono text-sm break-all">
                    <code>0 */<?= $currentCheckInterval ?> * * * php <?= realpath(PATH_ROOT . 'cron/check_domains.php') ?></code>
                </div>
                <p class="text-xs text-gray-500 mt-2">Update the path to match your server installation</p>
            </div>

            <!-- Log Files -->
            <div>
                <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-file-alt text-orange-500 mr-2"></i>
                    Log Files
                </h4>
                <div class="space-y-2">
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Cron Log</p>
                            <p class="text-xs text-gray-500 mt-0.5">Domain check execution logs</p>
                        </div>
                        <code class="text-xs bg-gray-900 text-gray-100 px-2 py-1 rounded">logs/cron.log</code>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                        <div>
                            <p class="text-sm font-medium text-gray-900">TLD Import Log</p>
                            <p class="text-xs text-gray-500 mt-0.5">TLD registry import logs</p>
                        </div>
                        <code class="text-xs bg-gray-900 text-gray-100 px-2 py-1 rounded">logs/tld_import_*.log</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Content: Maintenance -->
<div id="content-maintenance" class="tab-content hidden">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Maintenance Tools</h3>
            <p class="text-sm text-gray-600 mt-1">Database cleanup and system maintenance</p>
        </div>

        <div class="p-6">
            <!-- Clear Logs -->
            <div class="mb-6">
                <h4 class="text-base font-semibold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-trash-alt text-red-500 mr-2"></i>
                    Clear Old Notification Logs
                </h4>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                    <div class="flex">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Warning</p>
                            <p class="text-sm text-gray-700 mt-1">
                                This will permanently delete all notification logs older than 30 days. This action cannot be undone.
                            </p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="/settings/clear-logs" onsubmit="return confirm('Are you sure you want to clear logs older than 30 days? This action cannot be undone.')">
                    <?= csrf_field() ?>
                    <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors font-medium">
                        <i class="fas fa-trash-alt mr-2"></i>
                        Clear Old Logs
                    </button>
                </form>
            </div>

            <!-- Future maintenance tools can be added here -->
            <div class="border-t border-gray-200 pt-6 mt-6">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex">
                        <i class="fas fa-lightbulb text-blue-500 mt-0.5 mr-3"></i>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Database Optimization</p>
                            <p class="text-sm text-gray-700 mt-1">
                                Regular maintenance keeps your system running smoothly. Consider clearing old logs monthly.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-update encryption based on port
function updateEncryptionByPort() {
    const portField = document.getElementById('mail_port');
    const encryptionField = document.getElementById('mail_encryption');
    const helpText = document.getElementById('encryption-help');
    
    if (!portField || !encryptionField) return;
    
    const port = parseInt(portField.value);
    
    // Auto-select encryption based on port
    if (port === 465) {
        encryptionField.value = 'ssl';
        helpText.innerHTML = '<i class="fas fa-check text-green-600 mr-1"></i>Port 465 detected: SSL encryption selected';
        helpText.className = 'text-xs text-green-600 mt-1';
    } else if (port === 587) {
        encryptionField.value = 'tls';
        helpText.innerHTML = '<i class="fas fa-check text-green-600 mr-1"></i>Port 587 detected: TLS encryption selected';
        helpText.className = 'text-xs text-green-600 mt-1';
    } else if (port === 25 || port === 2525) {
        // Keep current selection but show info
        helpText.innerHTML = '<i class="fas fa-info text-blue-600 mr-1"></i>Port ' + port + ': Choose TLS or None based on your server';
        helpText.className = 'text-xs text-blue-600 mt-1';
    } else {
        helpText.innerHTML = '<i class="fas fa-question text-gray-600 mr-1"></i>Custom port: Choose encryption manually';
        helpText.className = 'text-xs text-gray-600 mt-1';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set up port change listener
    const portField = document.getElementById('mail_port');
    if (portField) {
        portField.addEventListener('input', updateEncryptionByPort);
        portField.addEventListener('change', updateEncryptionByPort);
        
        // Run once on page load
        updateEncryptionByPort();
    }
});

// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active', 'border-primary', 'text-primary');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab
    document.getElementById('content-' + tabName).classList.remove('hidden');
    const activeBtn = document.getElementById('tab-' + tabName);
    activeBtn.classList.add('active', 'border-primary', 'text-primary');
    activeBtn.classList.remove('border-transparent', 'text-gray-500');
    
    // Update URL hash without scrolling
    history.replaceState(null, null, '#' + tabName);
}

// Load tab from URL hash on page load
window.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.substring(1); // Remove the #
    const validTabs = ['app', 'email', 'monitoring', 'isolation', 'security', 'system', 'maintenance'];
    
    if (hash && validTabs.includes(hash)) {
        switchTab(hash);
    } else {
        // Default to first tab
        switchTab('app');
    }
});

// Settings form logic
document.addEventListener('DOMContentLoaded', function() {
    const presetSelect = document.getElementById('notification_preset');
    if (!presetSelect) return;
    
    const customContainer = document.getElementById('custom_days_container');
    const customInput = document.getElementById('custom_notification_days');
    const hiddenInput = document.getElementById('notification_days_before');
    const daysPreview = document.getElementById('days_preview');

    presetSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const value = selectedOption.dataset.value;
        
        if (this.value === 'custom') {
            customContainer.style.display = 'block';
            customInput.required = true;
            if (customInput.value) {
                daysPreview.textContent = customInput.value;
            }
        } else {
            customContainer.style.display = 'none';
            customInput.required = false;
            hiddenInput.value = value;
            daysPreview.textContent = value;
        }
    });

    customInput.addEventListener('input', function() {
        if (presetSelect.value === 'custom') {
            daysPreview.textContent = this.value || 'Not set';
        }
    });

    document.getElementById('settingsForm').addEventListener('submit', function(e) {
        if (presetSelect.value === 'custom') {
            const customValue = customInput.value.trim();
            
            if (!customValue) {
                e.preventDefault();
                alert('Please enter custom notification days');
                customInput.focus();
                return false;
            }

            if (!/^[\d,\s]+$/.test(customValue)) {
                e.preventDefault();
                alert('Custom days must contain only numbers and commas');
                customInput.focus();
                return false;
            }
        }
    });

    // CAPTCHA provider selection logic
    const captchaProvider = document.getElementById('captcha_provider');
    if (captchaProvider) {
        const captchaConfig = document.getElementById('captcha_config');
        const v3Threshold = document.getElementById('recaptcha_v3_threshold');
        const docsV2 = document.getElementById('docs_recaptcha_v2');
        const docsV3 = document.getElementById('docs_recaptcha_v3');
        const docsTurnstile = document.getElementById('docs_turnstile');

        function updateCaptchaUI() {
            const selectedProvider = captchaProvider.value;
            
            // Show/hide configuration section
            if (selectedProvider === 'disabled') {
                captchaConfig.style.display = 'none';
            } else {
                captchaConfig.style.display = 'block';
            }

            // Show/hide v3 threshold field
            if (selectedProvider === 'recaptcha_v3') {
                v3Threshold.style.display = 'block';
            } else {
                v3Threshold.style.display = 'none';
            }

            // Update documentation
            docsV2.style.display = 'none';
            docsV3.style.display = 'none';
            docsTurnstile.style.display = 'none';

            if (selectedProvider === 'recaptcha_v2') {
                docsV2.style.display = 'block';
            } else if (selectedProvider === 'recaptcha_v3') {
                docsV3.style.display = 'block';
            } else if (selectedProvider === 'turnstile') {
                docsTurnstile.style.display = 'block';
            }
        }

        captchaProvider.addEventListener('change', updateCaptchaUI);
        // Initialize on page load
        updateCaptchaUI();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
