<?php
$title = 'Add New Domain';
$pageTitle = 'Add New Domain';
$pageDescription = 'Start monitoring a new domain';
$pageIcon = 'fas fa-plus-circle';
ob_start();
?>

<!-- Main Form -->
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-globe text-gray-400 mr-2 text-sm"></i>
                Domain Information
            </h2>
        </div>
        
        <div class="p-6">
            <form method="POST" action="/domains/store" class="space-y-5">
                <?= csrf_field() ?>
                <!-- Domain Name -->
                <div>
                    <label for="domain_name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Domain Name *
                    </label>
                    <input type="text" 
                           id="domain_name" 
                           name="domain_name" 
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                           placeholder="example.com"
                           required
                           autofocus>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Enter the domain name without http:// or https://
                    </p>
                </div>

                <!-- Notification Group -->
                <div>
                    <label for="notification_group_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Notification Group
                    </label>
                    <select id="notification_group_id" 
                            name="notification_group_id" 
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm">
                        <option value="">-- No Group (No notifications) --</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Optional: Assign to a notification group to receive expiry alerts
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Add Domain
                    </button>
                    <a href="/domains" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- How it works -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-info-circle text-white"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">How It Works</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        When you add a domain, we automatically fetch its WHOIS information including 
                        expiration date, registrar, nameservers, and other important details. This may take a few seconds.
                    </p>
                </div>
            </div>
        </div>

        <!-- What we track -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">What We Track</h3>
                    <ul class="text-xs text-gray-600 space-y-1">
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Domain expiration date</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Registrar information</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Nameservers</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Domain status</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
