<?php
$title = 'Create Notification Group';
$pageTitle = 'Create Notification Group';
$pageDescription = 'Set up a new notification group for your domains';
$pageIcon = 'fas fa-plus-circle';
ob_start();
?>

<!-- Main Form -->
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-bell text-gray-400 mr-2 text-sm"></i>
                Group Information
            </h2>
        </div>
        
        <div class="p-6">
            <form method="POST" action="/groups/store" class="space-y-5">
                <?= csrf_field() ?>
                <!-- Group Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Group Name *
                    </label>
                    <input type="text" 
                           id="name" 
                           name="name" 
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                           placeholder="e.g., Production Alerts, Team Notifications"
                           required
                           autofocus>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Choose a descriptive name for this notification group
                    </p>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Description (Optional)
                    </label>
                    <textarea id="description" 
                              name="description" 
                              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                              rows="4"
                              placeholder="Add details about this notification group, its purpose, or who should be notified..."></textarea>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Optional: Add notes to help identify this group's purpose
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Create Group
                    </button>
                    <a href="/groups" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Section -->
    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-info-circle text-white"></i>
                </div>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Next Steps</h3>
                <ul class="text-xs text-gray-600 space-y-1">
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">After creating the group, you'll be able to add notification channels (Email, Telegram, Discord, Slack, Mattermost, Pushover, Webhook)</span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">Configure each channel with the necessary credentials and settings</span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">Assign domains to this group to start receiving notifications</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
