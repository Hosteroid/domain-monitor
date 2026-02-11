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

// Cached update state for Updates tab (tab badge + modal on load)
$cachedUpdateAvailable = false;
$cachedUpdateData = null;
$currentVer = $appSettings['app_version'] ?? '0';
$latestVer = $updateSettings['latest_available_version'] ?? null;
$updateChannel = $updateSettings['update_channel'] ?? 'stable';
$commitsBehind = (int)($updateSettings['commits_behind_count'] ?? 0);
$installedSha = $updateSettings['installed_commit_sha'] ?? '';
$remoteSha = $updateSettings['latest_remote_sha'] ?? '';
// If installed SHA matches remote SHA, there's no real hotfix — stale cache
if ($installedSha !== '' && $remoteSha !== '' && str_starts_with($installedSha, $remoteSha)) {
    $commitsBehind = 0;
}
if ($latestVer && version_compare($latestVer, $currentVer, '>')) {
    $cachedUpdateAvailable = true;
    $cachedUpdateData = [
        'available' => true,
        'type' => 'release',
        'current_version' => $currentVer,
        'latest_version' => $latestVer,
        'release_notes' => $updateSettings['latest_release_notes'] ?? '',
        'release_url' => $updateSettings['latest_release_url'] ?? '',
        'published_at' => $updateSettings['latest_release_published_at'] ?? null,
        'channel' => $updateChannel,
    ];
} elseif ($updateChannel === 'latest' && $commitsBehind > 0) {
    $cachedUpdateAvailable = true;
    $cachedUpdateData = [
        'available' => true,
        'type' => 'hotfix',
        'current_version' => $currentVer,
        'commits_behind' => $commitsBehind,
        'commit_messages' => [],
        'channel' => $updateChannel,
    ];
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
            <button onclick="switchTab('updates')" id="tab-updates" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap relative">
                <i class="fas fa-cloud-download-alt mr-2"></i>
                Updates
                <?php if (!empty($cachedUpdateAvailable)): ?>
                <span id="update-badge" class="ml-1.5 inline-flex items-center justify-center w-2 h-2 bg-amber-500 rounded-full" title="Update available"></span>
                <?php else: ?>
                <span id="update-badge" class="hidden ml-1.5 inline-flex items-center justify-center w-2 h-2 bg-amber-500 rounded-full"></span>
                <?php endif; ?>
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

<!-- Tab Content: Updates -->
<div id="content-updates" class="tab-content hidden">
    <!-- Update Status card: current version + Check button; show "Update available" card when cached -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center">
                        <i class="fas fa-sync-alt text-primary text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Update Status</h3>
                        <p class="text-sm text-gray-500 mt-0.5">Current version <code class="text-primary font-medium">v<?= htmlspecialchars($appSettings['app_version']) ?></code></p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($updateSettings['last_update_check']): ?>
                        <span class="text-xs text-gray-400 hidden sm:inline">Last checked: <?= date('M d, H:i', strtotime($updateSettings['last_update_check'])) ?></span>
                    <?php endif; ?>
                    <button type="button" id="checkUpdatesBtn" onclick="checkForUpdates()"
                            class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm font-medium rounded-lg hover:bg-primary-dark transition-colors shadow-sm">
                        <i class="fas fa-sync-alt mr-2" id="checkUpdatesIcon"></i>
                        Check for Updates
                    </button>
                </div>
            </div>
        </div>
        <div class="p-6">
            <!-- Cached update available: show card that opens modal (so landing from top bar shows update without clicking Check) -->
            <?php if ($cachedUpdateAvailable && $cachedUpdateData): ?>
            <div id="cachedUpdateCard" class="mb-6 p-4 rounded-xl border-2 border-amber-200 bg-amber-50/80 hover:bg-amber-50 transition-colors cursor-pointer" onclick="openUpdateModal(window.__cachedUpdateData)">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                            <i class="fas fa-cloud-download-alt text-amber-600"></i>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-amber-900">Update available</p>
                            <p class="text-xs text-amber-700">
                                <?php if (($cachedUpdateData['type'] ?? '') === 'release'): ?>
                                    New release: v<?= htmlspecialchars($cachedUpdateData['latest_version'] ?? '') ?> — click to view details and apply
                                <?php else: ?>
                                    <?= (int)($cachedUpdateData['commits_behind'] ?? 0) ?> new commit(s) — click to apply hotfix
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-amber-600 text-sm"></i>
                </div>
            </div>
            <?php elseif (!empty($updateSettings['last_update_check'])): ?>
            <!-- Last check found no update: show "up to date" from cache -->
            <div id="cachedUpToDate">
                <div class="flex items-center p-4 bg-green-50 border border-green-200 rounded-lg">
                    <i class="fas fa-check-circle text-green-500 text-lg mr-3"></i>
                    <div>
                        <p class="text-sm font-semibold text-green-800">You're up to date!</p>
                        <p class="text-xs text-green-600 mt-0.5">Version v<?= htmlspecialchars($appSettings['app_version']) ?> is the latest<?= ($updateSettings['update_channel'] ?? 'stable') === 'stable' ? ' stable release' : ' version' ?>.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <!-- Inline result: loading / up-to-date / error (update-available goes in modal) -->
            <div id="updateResultContainer" class="hidden"></div>
        </div>
    </div>

    <!-- Update preferences (channel + badge) -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Update Preferences</h3>
            <p class="text-sm text-gray-600 mt-1">Choose update channel and display options</p>
        </div>

        <form method="POST" action="/settings/updates/preferences" class="p-6">
            <?= csrf_field() ?>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Update Channel</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Stable Channel -->
                        <label class="relative flex items-start p-4 border-2 rounded-lg cursor-pointer transition-colors
                            <?= ($updateSettings['update_channel'] ?? 'stable') === 'stable' ? 'border-primary bg-blue-50' : 'border-gray-200 hover:border-gray-300' ?>">
                            <input type="radio" name="update_channel" value="stable"
                                   <?= ($updateSettings['update_channel'] ?? 'stable') === 'stable' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary border-gray-300 focus:ring-primary mt-0.5">
                            <div class="ml-3">
                                <span class="text-sm font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-shield-alt text-green-600 mr-2"></i>
                                    Stable
                                </span>
                                <p class="text-xs text-gray-600 mt-1">Only receive tagged release updates (e.g., v1.2.0). Recommended for production environments.</p>
                            </div>
                        </label>

                        <!-- Latest Channel -->
                        <label class="relative flex items-start p-4 border-2 rounded-lg cursor-pointer transition-colors
                            <?= ($updateSettings['update_channel'] ?? 'stable') === 'latest' ? 'border-primary bg-blue-50' : 'border-gray-200 hover:border-gray-300' ?>">
                            <input type="radio" name="update_channel" value="latest"
                                   <?= ($updateSettings['update_channel'] ?? 'stable') === 'latest' ? 'checked' : '' ?>
                                   class="w-4 h-4 text-primary border-gray-300 focus:ring-primary mt-0.5">
                            <div class="ml-3">
                                <span class="text-sm font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-bolt text-amber-500 mr-2"></i>
                                    Latest
                                </span>
                                <p class="text-xs text-gray-600 mt-1">Receive both releases and hotfix commits pushed to the main branch. Get fixes faster.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <?php if (($updateSettings['update_channel'] ?? 'stable') === 'latest' && empty($updateSettings['installed_commit_sha'])): ?>
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                        <p class="text-sm text-amber-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            Commit tracking is not yet active. It will begin after the first update is applied through this system. Until then, only release updates will be detected.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Show update badge in top menu -->
                <div class="border-t border-gray-200 pt-4 mt-4">
                    <label class="flex items-start cursor-pointer">
                        <input type="checkbox" name="update_badge_enabled" value="1"
                               <?= ($updateSettings['update_badge_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>
                               class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary mt-0.5">
                        <span class="ml-3 text-sm text-gray-700">
                            Show <strong>Update available</strong> badge in the top menu when an update is available (recommended so admins see it without opening the notification panel).
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-between pt-6 mt-6 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save update preferences
                </button>
            </div>
        </form>
    </div>

    <!-- Rollback Section -->
    <?php if (!empty($updateSettings['update_backup_path']) && file_exists($updateSettings['update_backup_path'])): ?>
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">Rollback</h3>
            <p class="text-sm text-gray-600 mt-1">Revert to the previous version if something went wrong</p>
        </div>

        <div class="p-6">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Warning</p>
                        <p class="text-sm text-gray-700 mt-1">
                            Rolling back will restore application files and database to the state before the last update. 
                            If the database restore fails automatically, you can import the SQL backup manually from the <code class="text-xs bg-gray-100 px-1 rounded">backups/</code> directory.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <p class="text-sm text-gray-600">
                    Backup available: <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded"><?= htmlspecialchars(basename($updateSettings['update_backup_path'])) ?></code>
                </p>
            </div>

            <form method="POST" action="/settings/updates/rollback" class="mt-4"
                  onsubmit="return confirm('Are you sure you want to rollback? This will restore files to the previous version.')">
                <?= csrf_field() ?>
                <button type="submit" class="inline-flex items-center px-4 py-2.5 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors font-medium">
                    <i class="fas fa-undo mr-2"></i>
                    Rollback to Previous Version
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update Available Modal: same content as before (blue/amber card inside), just in a popup -->
    <div id="updateAvailableModal" class="fixed inset-0 z-50 hidden" aria-modal="true">
        <div class="fixed inset-0 bg-black/50 transition-opacity" onclick="closeUpdateModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4 pointer-events-none">
            <div id="updateAvailableModalContent" class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col pointer-events-auto overflow-hidden">
                <div class="flex items-start justify-between px-4 py-3 flex-shrink-0 gap-3 bg-blue-50 border border-blue-200 rounded-t-xl">
                    <div class="flex items-start min-w-0">
                        <i id="updateModalIcon" class="fas fa-arrow-circle-up text-blue-500 text-lg mt-0.5 mr-3"></i>
                        <div>
                            <p id="updateModalTitle" class="text-sm font-semibold text-blue-800">New Release Available</p>
                            <p id="updateModalSubline" class="text-xs text-blue-600 mt-0.5"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a id="updateModalReleaseLink" href="#" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline whitespace-nowrap"><i class="fab fa-github mr-1"></i>Release notes</a>
                        <button type="button" onclick="closeUpdateModal()" class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors" aria-label="Close">
                            <i class="fas fa-times text-lg"></i>
                        </button>
                    </div>
                </div>
                <div id="updateModalBody" class="update-modal-body p-6 overflow-y-auto flex-1 text-sm min-h-0">
                    <!-- Filled by JS: changelog or commit list only -->
                </div>
                <div id="updateModalFooter" class="px-4 py-3 flex-shrink-0 border-t rounded-b-xl">
                    <!-- Filled by JS: Apply form (blue for release, amber for hotfix) -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.__cachedUpdateData = <?= $cachedUpdateData ? json_encode($cachedUpdateData) : 'null' ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js"></script>
<style>
.changelog-markdown { line-height: 1.5; }
.changelog-markdown h1, .changelog-markdown h2, .changelog-markdown h3 { font-weight: 600; margin-top: 0.5em; margin-bottom: 0.25em; }
.changelog-markdown h1 { font-size: 1em; }
.changelog-markdown h2 { font-size: 0.95em; }
.changelog-markdown h3 { font-size: 0.9em; }
.changelog-markdown p { margin-bottom: 0.5em; }
.changelog-markdown ul, .changelog-markdown ol { margin: 0.25em 0 0.5em 1em; padding-left: 1em; }
.changelog-markdown li { margin-bottom: 0.15em; }
.changelog-markdown code { background: rgba(0,0,0,0.06); padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.95em; }
.changelog-markdown pre { background: rgba(0,0,0,0.06); padding: 0.5em; border-radius: 4px; overflow-x: auto; margin: 0.5em 0; font-size: 0.9em; }
.changelog-markdown pre code { background: none; padding: 0; }
.changelog-markdown a { color: #2563eb; text-decoration: underline; }
.changelog-markdown a:hover { color: #1d4ed8; }
.changelog-markdown hr { border: none; border-top: 1px solid rgba(0,0,0,0.1); margin: 0.5em 0; }
.changelog-markdown blockquote { border-left: 3px solid rgba(0,0,0,0.15); margin: 0.5em 0; padding-left: 0.75em; color: inherit; opacity: 0.95; }
.update-modal-body .changelog-markdown { max-height: 20rem; }
</style>
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
    const validTabs = ['app', 'email', 'monitoring', 'isolation', 'security', 'system', 'maintenance', 'updates'];
    
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

    // Update check AJAX functionality
    function checkForUpdates() {
        const btn = document.getElementById('checkUpdatesBtn');
        const icon = document.getElementById('checkUpdatesIcon');
        const container = document.getElementById('updateResultContainer');
        const badge = document.getElementById('update-badge');
        
        // Hide any cached status cards
        var cachedCard = document.getElementById('cachedUpdateCard');
        var cachedUpToDate = document.getElementById('cachedUpToDate');
        if (cachedCard) cachedCard.classList.add('hidden');
        if (cachedUpToDate) cachedUpToDate.classList.add('hidden');
        
        // Show loading state
        btn.disabled = true;
        btn.classList.add('opacity-75');
        icon.classList.add('fa-spin');
        container.classList.remove('hidden');
        container.innerHTML = '<div class="flex items-center p-4 bg-gray-50 rounded-lg border border-gray-200"><i class="fas fa-spinner fa-spin text-primary mr-3"></i><span class="text-sm text-gray-600">Checking for updates...</span></div>';
        
        // Get CSRF token
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const csrfToken = csrfInput ? csrfInput.value : '';
        
        fetch('/api/updates/check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'force=1&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.classList.remove('opacity-75');
            icon.classList.remove('fa-spin');
            
            if (data.error) {
                container.innerHTML = renderUpdateError(data.error);
                return;
            }
            
            if (data.available) {
                badge.classList.remove('hidden');
                container.classList.add('hidden');
                container.innerHTML = '';
                openUpdateModal(data);
            } else {
                badge.classList.add('hidden');
                container.innerHTML = renderUpToDate(data);
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.classList.remove('opacity-75');
            icon.classList.remove('fa-spin');
            container.innerHTML = renderUpdateError('Network error: ' + error.message);
        });
    }
    window.checkForUpdates = checkForUpdates;
    
    function openUpdateModal(data) {
        const modal = document.getElementById('updateAvailableModal');
        const bodyEl = document.getElementById('updateModalBody');
        const footerEl = document.getElementById('updateModalFooter');
        const titleEl = document.getElementById('updateModalTitle');
        const sublineEl = document.getElementById('updateModalSubline');
        const releaseLinkEl = document.getElementById('updateModalReleaseLink');
        const iconEl = document.getElementById('updateModalIcon');
        if (!modal || !bodyEl) return;
        var isRelease = (data.type || 'release') === 'release';
        if (titleEl) titleEl.textContent = isRelease ? 'New Release Available: v' + (data.latest_version || '') : 'Hotfix Available: ' + (data.commits_behind || 0) + ' commit(s) behind';
        if (sublineEl) {
            if (isRelease) {
                var sub = 'Installed: v' + (data.current_version || '');
                if (data.published_at) { var d = new Date(data.published_at); sub += ' · Released: ' + String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + '/' + d.getFullYear(); }
                sublineEl.textContent = sub;
                sublineEl.classList.remove('hidden');
            } else {
                sublineEl.textContent = 'New commits have been pushed to the main branch.';
                sublineEl.classList.remove('hidden');
            }
        }
        if (releaseLinkEl) {
            if (isRelease && data.release_url) {
                releaseLinkEl.href = data.release_url;
                releaseLinkEl.classList.remove('hidden');
            } else {
                releaseLinkEl.href = '#';
                releaseLinkEl.classList.add('hidden');
            }
        }
        if (iconEl) iconEl.className = isRelease ? 'fas fa-arrow-circle-up text-blue-500 text-lg mt-0.5 mr-3' : 'fas fa-wrench text-amber-500 text-lg mt-0.5 mr-3';
        // Update header colors (blue for release, amber for hotfix)
        var headerEl = bodyEl.parentElement.querySelector('.rounded-t-xl');
        if (headerEl) headerEl.className = 'flex items-start justify-between px-4 py-3 flex-shrink-0 gap-3 rounded-t-xl ' + (isRelease ? 'bg-blue-50 border border-blue-200' : 'bg-amber-50 border border-amber-200');
        if (titleEl) titleEl.className = 'text-sm font-semibold ' + (isRelease ? 'text-blue-800' : 'text-amber-800');
        if (sublineEl) sublineEl.className = 'text-xs mt-0.5 ' + (isRelease ? 'text-blue-600' : 'text-amber-600');
        if (releaseLinkEl) releaseLinkEl.className = releaseLinkEl.className.replace(/text-(blue|amber)-600/g, isRelease ? 'text-blue-600' : 'text-amber-600');
        bodyEl.className = 'update-modal-body p-6 overflow-y-auto flex-1 text-sm min-h-0 ' + (isRelease ? 'bg-blue-50 border-x border-b border-blue-200' : 'bg-amber-50 border-x border-b border-amber-200');
        bodyEl.innerHTML = renderUpdateAvailable(data);
        var csrf = document.querySelector('input[name=csrf_token]') ? document.querySelector('input[name=csrf_token]').value : '';
        if (footerEl) {
            footerEl.className = 'px-4 py-3 flex-shrink-0 border-t rounded-b-xl ' + (isRelease ? 'bg-blue-100 border-blue-200' : 'bg-amber-100 border-amber-200');
            if (isRelease) {
                footerEl.innerHTML = '<form method="POST" action="/settings/updates/apply" onsubmit="return confirm(\'Apply update to v' + escapeHtml(data.latest_version || '') + '? A backup will be created before updating.\')">' +
                    (csrf ? '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '">' : '') +
                    '<input type="hidden" name="update_type" value="release">' +
                    '<button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-medium"><i class="fas fa-download mr-2"></i> Update to v' + escapeHtml(data.latest_version || '') + '</button>' +
                    '</form>';
            } else {
                footerEl.innerHTML = '<form method="POST" action="/settings/updates/apply" onsubmit="return confirm(\'Apply hotfix? This will update your files to the latest main branch. A backup will be created first.\')">' +
                    (csrf ? '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '">' : '') +
                    '<input type="hidden" name="update_type" value="hotfix">' +
                    '<button type="submit" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-sm rounded-lg hover:bg-amber-700 transition-colors font-medium"><i class="fas fa-download mr-2"></i> Apply Hotfix</button>' +
                    '</form>';
            }
        }
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeUpdateModal() {
        const modal = document.getElementById('updateAvailableModal');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('updateAvailableModal');
            if (modal && !modal.classList.contains('hidden')) closeUpdateModal();
        }
    });
    window.openUpdateModal = openUpdateModal;
    window.closeUpdateModal = closeUpdateModal;
    
    // When landing on Settings#updates from an external link (e.g. top bar badge or notification),
    // auto-open the modal. Skip if the user just submitted a form on this page (referrer is self).
    if (window.location.hash === '#updates' && window.__cachedUpdateData) {
        var ref = document.referrer || '';
        var onSettingsPage = ref.indexOf('/settings') !== -1;
        if (!onSettingsPage) {
            setTimeout(function() { openUpdateModal(window.__cachedUpdateData); }, 200);
        }
    }
    
    function renderReleaseNotesMarkdown(md) {
        if (!md) return '';
        if (typeof marked === 'undefined' || typeof DOMPurify === 'undefined') return escapeHtml(md);
        const raw = marked.parse(md, { gfm: true, breaks: true });
        const allowedTags = ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'a', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'hr'];
        let out = DOMPurify.sanitize(raw, { ALLOWED_TAGS: allowedTags, ALLOWED_ATTR: ['href', 'target', 'rel'] });
        out = out.replace(/<a href=/gi, '<a target="_blank" rel="noopener noreferrer" href=');
        return out;
    }
    
    function renderUpToDate(data) {
        return `
            <div class="flex items-center p-4 bg-green-50 border border-green-200 rounded-lg">
                <i class="fas fa-check-circle text-green-500 text-lg mr-3"></i>
                <div>
                    <p class="text-sm font-semibold text-green-800">You're up to date!</p>
                    <p class="text-xs text-green-600 mt-0.5">Version ${escapeHtml(data.current_version)} is the latest${data.channel === 'stable' ? ' stable release' : ' version'}.</p>
                </div>
            </div>`;
    }
    
    function renderUpdateAvailable(data) {
        var html = '';
        if (data.type === 'release') {
            html = data.release_notes
                ? '<p class="text-sm font-semibold text-blue-800 mb-2 -mt-1">Changelog:</p><hr class="border-blue-200 mb-3"><div class="changelog-markdown text-xs text-blue-700 max-h-40 overflow-y-auto">' + renderReleaseNotesMarkdown(data.release_notes) + '</div>'
                : '<p class="text-gray-500 text-sm">No changelog available.</p>';
        } else if (data.type === 'hotfix') {
            if (data.commit_messages && data.commit_messages.length > 0) {
                html = '<p class="text-xs font-semibold text-amber-800 mb-2">Recent commits:</p><div class="space-y-1 max-h-40 overflow-y-auto">';
                data.commit_messages.forEach(function(c) {
                    var firstLine = (c.message || '').split('\n')[0];
                    html += '<div class="text-xs text-amber-700 flex items-start"><code class="text-amber-600 bg-amber-100 px-1 rounded mr-2 flex-shrink-0">' + escapeHtml((c.sha || '').substring(0, 7)) + '</code><span class="truncate">' + escapeHtml(firstLine) + '</span></div>';
                });
                html += '</div>';
            } else {
                html = '';
            }
        }
        return html;
    }
    
    function renderUpdateError(message) {
        return `
            <div class="flex items-center p-4 bg-red-50 border border-red-200 rounded-lg">
                <i class="fas fa-exclamation-circle text-red-500 text-lg mr-3"></i>
                <div>
                    <p class="text-sm font-semibold text-red-800">Update check failed</p>
                    <p class="text-xs text-red-600 mt-0.5">${escapeHtml(message)}</p>
                </div>
            </div>`;
    }
    
    // Ensure escapeHtml is available (may also be defined in base layout)
    if (typeof window.escapeHtml === 'undefined') {
        window.escapeHtml = function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
    }

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
