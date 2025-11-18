<?php
$title = 'Dashboard';
$pageTitle = 'Dashboard Overview';
$pageDescription = 'Monitor your domains and expiration dates';
$pageIcon = 'fas fa-chart-line';

// Get domain stats for dashboard (if not already set by base.php)
if (!isset($domainStats)) {
    $domainStats = \App\Helpers\LayoutHelper::getDomainStats();
}

ob_start();
?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Total Domains Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Domains</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $domainStats['total'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-globe text-blue-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Active Domains Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Active</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $domainStats['active'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Expiring Soon Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Expiring Soon</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $domainStats['expiring_soon'] ?? 0 ?></p>
                <p class="text-xs text-gray-400 mt-1">within <?= $domainStats['expiring_threshold'] ?? 30 ?> days</p>
            </div>
            <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-orange-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Inactive Domains Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Inactive</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $domainStats['inactive'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-times-circle text-gray-600 text-lg"></i>
            </div>
        </div>
    </div>
</div>

<!-- Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Recent Domains -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-clock text-gray-400 mr-2 text-xs"></i>
                    Recent Domains
                </h2>
            </div>
            <div class="p-4">
            <?php if (!empty($recentDomains)): ?>
                <div class="space-y-2">
                    <?php foreach ($recentDomains as $domain): ?>
                        <div class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:border-gray-300 hover:shadow-sm transition-all duration-200">
                            <div class="flex items-center space-x-3 flex-1 min-w-0">
                                <div class="w-9 h-9 bg-gray-50 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-globe text-gray-400 text-sm"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($domain['domain_name']) ?></h3>
                                    <div class="flex items-center space-x-3 text-xs text-gray-500 mt-0.5">
                                        <span class="flex items-center">
                                            <i class="far fa-calendar mr-1"></i>
                                            <?php if ($domain['expiration_date']): ?>
                                                <?= date('M d, Y', strtotime($domain['expiration_date'])) ?>
                                            <?php else: ?>
                                                Not set
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($domain['registrar']): ?>
                                            <span class="flex items-center truncate">
                                                <i class="fas fa-building mr-1"></i>
                                                <?= htmlspecialchars($domain['registrar']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2 flex-shrink-0">
                                <?php
                                // Display data prepared by DomainHelper in controller
                                $statusClass = $domain['statusClass'];
                                $statusText = $domain['statusText'];
                                ?>
                                <span class="px-2 py-1 rounded text-xs font-medium <?= $statusClass ?>">
                                    <?= $statusText ?>
                                </span>
                                <a href="/domains/<?= $domain['id'] ?>" class="text-gray-400 hover:text-primary">
                                    <i class="fas fa-chevron-right text-sm"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 pt-3 border-t border-gray-100 text-center">
                    <a href="/domains" class="text-sm text-primary hover:text-primary-dark font-medium inline-flex items-center">
                        View All Domains
                        <i class="fas fa-arrow-right ml-2 text-xs"></i>
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-globe text-gray-300 text-4xl mb-3"></i>
                    <p class="text-sm text-gray-600">No domains added yet</p>
                    <a href="/domains/create" class="mt-3 inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i>
                        Add Your First Domain
                    </a>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar: Quick Actions & Stats -->
    <div class="space-y-4">
        <!-- Quick Actions -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-bolt text-gray-400 mr-2 text-xs"></i>
                    Quick Actions
                </h2>
            </div>
            <div class="p-4 space-y-2">
                <a href="/domains/create" class="flex items-center p-3 border border-gray-200 hover:border-primary hover:bg-blue-50 rounded-lg transition-all duration-200 group">
                    <div class="w-9 h-9 bg-blue-50 group-hover:bg-primary rounded-lg flex items-center justify-center group-hover:text-white text-blue-600 transition-colors duration-200">
                        <i class="fas fa-plus text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-primary">Add New Domain</span>
                </a>
                <a href="/groups/create" class="flex items-center p-3 border border-gray-200 hover:border-green-500 hover:bg-green-50 rounded-lg transition-all duration-200 group">
                    <div class="w-9 h-9 bg-green-50 group-hover:bg-green-500 rounded-lg flex items-center justify-center group-hover:text-white text-green-600 transition-colors duration-200">
                        <i class="fas fa-bell text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-green-700">Create Group</span>
                </a>
                <a href="/debug/whois" class="flex items-center p-3 border border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 rounded-lg transition-all duration-200 group">
                    <div class="w-9 h-9 bg-indigo-50 group-hover:bg-indigo-500 rounded-lg flex items-center justify-center group-hover:text-white text-indigo-600 transition-colors duration-200">
                        <i class="fas fa-search text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-indigo-700">WHOIS Lookup</span>
                </a>
            </div>
        </div>

        <!-- System Status -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-server text-gray-400 mr-2 text-xs"></i>
                    System Status
                </h2>
            </div>
            <div class="p-4 space-y-3">
                <?php
                $statusColors = [
                    'green' => 'text-green-600',
                    'yellow' => 'text-yellow-600',
                    'red' => 'text-red-600',
                    'gray' => 'text-gray-600'
                ];
                ?>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Database</span>
                    <span class="flex items-center <?= $statusColors[$systemStatus['database']['color']] ?> font-medium">
                        <i class="fas fa-circle text-xs mr-1.5"></i>
                        <?= ucfirst($systemStatus['database']['status']) ?>
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">TLD Registry</span>
                    <span class="flex items-center <?= $statusColors[$systemStatus['whois']['color']] ?> font-medium">
                        <i class="fas fa-circle text-xs mr-1.5"></i>
                        <?= ucfirst($systemStatus['whois']['status']) ?>
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Notifications</span>
                    <span class="flex items-center <?= $statusColors[$systemStatus['notifications']['color']] ?> font-medium">
                        <i class="fas fa-circle text-xs mr-1.5"></i>
                        <?= ucfirst($systemStatus['notifications']['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Expiring Soon -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-orange-500 mr-2 text-xs"></i>
                        Expiring Soon
                    </h2>
                    <?php if (($expiringCount ?? 0) > 5): ?>
                        <a href="/domains?status=expiring_soon" class="text-xs text-primary hover:text-primary-dark font-medium">
                            View all <?= $expiringCount ?>
                            <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($expiringThisMonth)): ?>
                <div class="p-4 space-y-2">
                    <?php foreach ($expiringThisMonth as $domain): ?>
                        <?php 
                            // Display data prepared by DomainHelper in controller
                            $daysLeft = $domain['daysLeft'];
                            $urgencyClass = $daysLeft <= 7 ? 'text-red-600' : ($daysLeft <= 30 ? 'text-orange-600' : 'text-yellow-600');
                        ?>
                        <div class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:border-gray-300 hover:shadow-sm transition-all duration-200">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($domain['domain_name']) ?></p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <?= $domain['expiration_date'] ? date('M d, Y', strtotime($domain['expiration_date'])) : 'Unknown' ?>
                                    <span class="<?= $urgencyClass ?> font-semibold ml-2">
                                        <?= $daysLeft ?> days
                                    </span>
                                </p>
                            </div>
                            <a href="/domains/<?= $domain['id'] ?>" class="text-gray-400 hover:text-primary">
                                <i class="fas fa-chevron-right text-sm"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-6 text-center">
                    <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                    <p class="text-sm text-gray-600">No domains expiring soon</p>
                    <p class="text-xs text-gray-400 mt-1">within <?= $domainStats['expiring_threshold'] ?? 30 ?> days</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
