<?php
$title = 'Dashboard';
$pageTitle = 'Dashboard Overview';
$pageDescription = 'Monitor your domains and expiration dates';
$pageIcon = 'fas fa-chart-line';

// Get domain stats for dashboard (if not already set by base.php)
if (!isset($domainStats)) {
    $domainStats = \App\Helpers\LayoutHelper::getDomainStats();
}

// Prepare widget data
$topRegistrars = array_slice($registrarCounts ?? [], 0, 8, true);
$topTags = array_slice(array_filter($dashTags ?? [], fn($t) => ($t['usage_count'] ?? 0) > 0), 0, 8);
$domainsWithoutGroup = ($totalDomainCount ?? 0) - ($domainsWithGroup ?? 0);
$totalGroupCount = count($groups ?? []);

ob_start();
?>

<?php if (\Core\Auth::isAdmin()): ?>
<!-- System Status Bar (Admin) -->
<div class="bg-white rounded-lg border border-gray-200 px-5 py-3 mb-4">
    <div class="flex items-center gap-6">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide flex items-center">
            <i class="fas fa-server text-gray-400 mr-2"></i>
            System Status
        </span>
        <?php
        $statusColors = [
            'green' => 'text-green-600',
            'yellow' => 'text-yellow-600',
            'red' => 'text-red-600',
            'gray' => 'text-gray-600'
        ];
        $statusDots = [
            'green' => 'bg-green-500',
            'yellow' => 'bg-yellow-500',
            'red' => 'bg-red-500',
            'gray' => 'bg-gray-400'
        ];
        ?>
        <div class="flex items-center gap-5 text-sm">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full <?= $statusDots[$systemStatus['database']['color']] ?>"></span>
                <span class="text-gray-500">Database</span>
                <span class="<?= $statusColors[$systemStatus['database']['color']] ?> font-medium"><?= ucfirst($systemStatus['database']['status']) ?></span>
            </span>
            <span class="text-gray-200">|</span>
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full <?= $statusDots[$systemStatus['whois']['color']] ?>"></span>
                <span class="text-gray-500">TLD Registry</span>
                <span class="<?= $statusColors[$systemStatus['whois']['color']] ?> font-medium"><?= ucfirst($systemStatus['whois']['status']) ?></span>
            </span>
            <span class="text-gray-200">|</span>
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full <?= $statusDots[$systemStatus['notifications']['color']] ?>"></span>
                <span class="text-gray-500">Notifications</span>
                <span class="<?= $statusColors[$systemStatus['notifications']['color']] ?> font-medium"><?= ucfirst($systemStatus['notifications']['status']) ?></span>
            </span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
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

<!-- Main Content: Recent Domains + Expiring Soon -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <!-- Recent Domains -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-clock text-gray-400 mr-2 text-xs"></i>
                    Recent Domains
                </h2>
                <a href="/domains" class="text-xs text-primary hover:text-primary-dark font-medium">
                    View all <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
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
                                            <?= $domain['expiration_date'] ? date('M d, Y', strtotime($domain['expiration_date'])) : 'Not set' ?>
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
                                <span class="px-2 py-1 rounded text-xs font-medium <?= $domain['statusClass'] ?>">
                                    <?= $domain['statusText'] ?>
                                </span>
                                <a href="/domains/<?= $domain['id'] ?>" class="text-gray-400 hover:text-primary">
                                    <i class="fas fa-chevron-right text-sm"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

<!-- Insights Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Registrar Distribution -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                <i class="fas fa-building text-gray-400 mr-2 text-xs"></i>
                Registrar Distribution
            </h2>
            <span class="text-xs text-gray-500"><?= count($registrarCounts ?? []) ?> registrar<?= count($registrarCounts ?? []) != 1 ? 's' : '' ?></span>
        </div>
        <div class="p-5">
            <?php if (!empty($topRegistrars)): ?>
                <div class="space-y-3">
                    <?php foreach ($topRegistrars as $regName => $regCount): ?>
                        <?php $regPct = ($totalDomainCount ?? 0) > 0 ? round(($regCount / $totalDomainCount) * 100) : 0; ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm text-gray-700 font-medium truncate mr-3"><?= htmlspecialchars($regName) ?></span>
                                <span class="text-xs text-gray-500 whitespace-nowrap"><?= $regCount ?> (<?= $regPct ?>%)</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-1.5">
                                <div class="bg-blue-500 rounded-full h-1.5" style="width: <?= max(2, $regPct) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-building text-gray-300 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-500">No registrar data</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tag Usage -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                <i class="fas fa-tags text-gray-400 mr-2 text-xs"></i>
                Tag Usage
            </h2>
            <span class="text-xs text-gray-500"><?= count($dashTags ?? []) ?> tag<?= count($dashTags ?? []) != 1 ? 's' : '' ?></span>
        </div>
        <div class="p-5">
            <?php if (!empty($topTags)): ?>
                <div class="space-y-3">
                    <?php foreach ($topTags as $tt): ?>
                        <?php $pct = ($totalDomainCount ?? 0) > 0 ? round(($tt['usage_count'] / $totalDomainCount) * 100) : 0; ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border <?= htmlspecialchars($tt['color'] ?? 'bg-gray-100 text-gray-700 border-gray-300') ?>">
                                    <i class="fas fa-tag mr-1" style="font-size: 8px;"></i>
                                    <?= htmlspecialchars($tt['name']) ?>
                                </span>
                                <span class="text-xs text-gray-500"><?= $tt['usage_count'] ?> domain<?= $tt['usage_count'] != 1 ? 's' : '' ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-1.5">
                                <div class="bg-primary rounded-full h-1.5" style="width: <?= max(2, $pct) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-tags text-gray-300 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-500">No tags in use</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notification Coverage -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                <i class="fas fa-bell text-gray-400 mr-2 text-xs"></i>
                Notification Coverage
            </h2>
            <span class="text-xs text-gray-500"><?= $totalGroupCount ?> group<?= $totalGroupCount != 1 ? 's' : '' ?>, <?= $totalChannels ?? 0 ?> channel<?= ($totalChannels ?? 0) != 1 ? 's' : '' ?></span>
        </div>
        <div class="p-5">
            <?php if (($totalDomainCount ?? 0) > 0): ?>
                <?php $coveragePct = round((($domainsWithGroup ?? 0) / $totalDomainCount) * 100); ?>
                <div class="flex items-center justify-center mb-4">
                    <div class="relative w-28 h-28">
                        <svg class="w-28 h-28 transform -rotate-90" viewBox="0 0 36 36">
                            <path class="text-gray-200" stroke="currentColor" stroke-width="3" fill="none" d="M18 2.0845a15.9155 15.9155 0 0 1 0 31.831 15.9155 15.9155 0 0 1 0-31.831"/>
                            <path class="text-primary" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="<?= $coveragePct ?>, 100" stroke-linecap="round" d="M18 2.0845a15.9155 15.9155 0 0 1 0 31.831 15.9155 15.9155 0 0 1 0-31.831"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-xl font-bold text-gray-900"><?= $coveragePct ?>%</span>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                        <p class="text-lg font-bold text-green-700"><?= $domainsWithGroup ?? 0 ?></p>
                        <p class="text-xs text-green-600">With Notifications</p>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-lg font-bold text-gray-700"><?= $domainsWithoutGroup ?></p>
                        <p class="text-xs text-gray-500">Without Notifications</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-bell-slash text-gray-300 text-2xl mb-2"></i>
                    <p class="text-sm text-gray-500">No domains to monitor</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
