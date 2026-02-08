<?php
$title = htmlspecialchars($user['full_name']) . ' - User Profile';
$pageTitle = 'User Profile';
$pageDescription = 'View user information and resources';
$pageIcon = 'fas fa-user';
ob_start();

$isActive = (bool)$user['is_active'];
$isVerified = (bool)$user['email_verified'];
$has2FA = !empty($twoFactorStatus['enabled']);
$avatar = \App\Helpers\AvatarHelper::getAvatar($user, 64);

$totalDomains = count($domains);
$totalTags = count($tags);
$totalGroups = count($groups);
?>

<!-- Back Navigation & Actions -->
<div class="mb-4 flex items-center justify-between">
    <a href="/users" class="inline-flex items-center px-3 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
        <i class="fas fa-arrow-left mr-2"></i>
        Back to Users
    </a>
    
    <div class="flex items-center space-x-2">
        <a href="/users/<?= $user['id'] ?>/edit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
            <i class="fas fa-edit mr-2"></i>
            Edit User
        </a>
        <?php if ($user['id'] != \Core\Auth::id()): ?>
            <form method="POST" action="/users/<?= $user['id'] ?>/toggle-status" class="inline">
                <?= csrf_field() ?>
                <?php if ($isActive): ?>
                    <button type="submit" onclick="return confirm('Deactivate this user?')" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors text-sm font-medium">
                        <i class="fas fa-user-slash mr-2"></i>
                        Deactivate
                    </button>
                <?php else: ?>
                    <button type="submit" onclick="return confirm('Activate this user?')" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                        <i class="fas fa-user-check mr-2"></i>
                        Activate
                    </button>
                <?php endif; ?>
            </form>
            <form method="POST" action="/users/<?= $user['id'] ?>/delete" class="inline">
                <?= csrf_field() ?>
                <button type="submit" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                    <i class="fas fa-trash mr-2"></i>
                    Delete
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- User Header Card -->
<div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
    <div class="flex items-start">
        <!-- Avatar -->
        <div class="flex-shrink-0 h-16 w-16 rounded-lg overflow-hidden bg-primary bg-opacity-10 flex items-center justify-center mr-5">
            <?php if ($avatar['type'] === 'uploaded' || $avatar['type'] === 'gravatar'): ?>
                <img src="<?= htmlspecialchars($avatar['url']) ?>" 
                     alt="<?= htmlspecialchars($avatar['alt']) ?>" 
                     class="w-full h-full object-cover"
                     loading="lazy">
            <?php else: ?>
                <span class="text-primary font-semibold text-xl">
                    <?= $avatar['initials'] ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- User Info -->
        <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
                <h2 class="text-2xl font-semibold text-gray-900"><?= htmlspecialchars($user['full_name']) ?></h2>
                <!-- Role Badge -->
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">
                        <i class="fas fa-crown mr-1"></i>Admin
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">
                        <i class="fas fa-user mr-1"></i>User
                    </span>
                <?php endif; ?>
                <!-- Status Badge -->
                <?php if ($isActive): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">
                        <i class="fas fa-check-circle mr-1"></i>Active
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 border border-red-200">
                        <i class="fas fa-times-circle mr-1"></i>Inactive
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4 text-sm text-gray-500 mb-3">
                <div class="flex items-center gap-2">
                    <i class="fas fa-at mr-1.5 text-gray-400"></i>
                    <span><?= htmlspecialchars($user['username']) ?></span>
                    <?php if ($has2FA): ?>
                        <span class="inline-flex items-center px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-semibold border border-green-200" title="Two-factor authentication enabled">
                            <i class="fas fa-shield-alt mr-0.5"></i>2FA
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-1.5 py-0.5 bg-gray-100 text-gray-400 rounded text-[10px] font-medium border border-gray-200" title="Two-factor authentication not enabled">
                            <i class="fas fa-shield-alt mr-0.5"></i>No 2FA
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-envelope mr-1.5 text-gray-400"></i>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                    <?php if ($isVerified): ?>
                        <i class="fas fa-check-circle text-green-500 ml-1" title="Email verified"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle text-orange-500 ml-1" title="Email not verified"></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex items-center gap-6 text-xs text-gray-500">
                <div class="flex items-center">
                    <i class="fas fa-calendar mr-1.5"></i>
                    Member since <?= date('M d, Y', strtotime($user['created_at'])) ?>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-clock mr-1.5"></i>
                    Last login: <?= $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never' ?>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="flex items-center gap-4 ml-6">
            <div class="text-center px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <p class="text-2xl font-bold text-gray-900"><?= $totalDomains ?></p>
                <p class="text-xs text-gray-500">Domains</p>
            </div>
            <div class="text-center px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <p class="text-2xl font-bold text-gray-900"><?= $totalTags ?></p>
                <p class="text-xs text-gray-500">Tags</p>
            </div>
            <div class="text-center px-4 py-2 bg-gray-50 rounded-lg border border-gray-200">
                <p class="text-2xl font-bold text-gray-900"><?= $totalGroups ?></p>
                <p class="text-xs text-gray-500">Groups</p>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex overflow-x-auto">
            <button onclick="switchTab('overview')" id="tab-overview" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-chart-bar mr-2"></i>
                Overview
            </button>
            <button onclick="switchTab('domains')" id="tab-domains" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-globe mr-2"></i>
                Domains (<?= $totalDomains ?>)
            </button>
            <button onclick="switchTab('tags')" id="tab-tags" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-tags mr-2"></i>
                Tags (<?= $totalTags ?>)
            </button>
            <button onclick="switchTab('groups')" id="tab-groups" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                <i class="fas fa-bell mr-2"></i>
                Notification Groups (<?= $totalGroups ?>)
            </button>
        </nav>
    </div>

    <div class="p-6">

        <!-- Overview Tab -->
        <div id="content-overview" class="tab-content hidden">
            <?php
            // Prepare overview data from already-loaded arrays
            $allAttentionDomains = array_merge(
                array_values(array_filter($domains, fn($d) => ($d['daysLeft'] ?? null) !== null && $d['daysLeft'] < 0)),
                array_values(array_filter($domains, fn($d) => ($d['daysLeft'] ?? null) !== null && $d['daysLeft'] >= 0 && $d['daysLeft'] <= 30))
            );
            usort($allAttentionDomains, fn($a, $b) => ($a['daysLeft'] ?? 999) <=> ($b['daysLeft'] ?? 999));
            $attentionCount = count($allAttentionDomains);
            $attentionPreview = array_slice($allAttentionDomains, 0, 5);
            
            // Registrar distribution
            $registrarCounts = [];
            foreach ($domains as $d) {
                $reg = !empty($d['registrar']) ? $d['registrar'] : 'Unknown';
                $registrarCounts[$reg] = ($registrarCounts[$reg] ?? 0) + 1;
            }
            arsort($registrarCounts);
            $topRegistrars = array_slice($registrarCounts, 0, 8, true);
            
            $domainsWithGroup = count(array_filter($domains, fn($d) => !empty($d['group_name'])));
            $domainsWithoutGroup = $totalDomains - $domainsWithGroup;
            
            $totalChannels = 0;
            foreach ($groups as $g) { $totalChannels += ($g['channel_count'] ?? 0); }
            
            $topTags = array_slice(array_filter($tags, fn($t) => ($t['usage_count'] ?? 0) > 0), 0, 8);
            ?>

            <!-- Domain Stats Cards (Dashboard style) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Domains</p>
                            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $totalDomains ?></p>
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
                            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $userDomainStats['active'] ?? 0 ?></p>
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
                            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $userDomainStats['expiring_soon'] ?? 0 ?></p>
                            <p class="text-xs text-gray-400 mt-1">within 30 days</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-orange-600 text-lg"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Expired</p>
                            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $userDomainStats['expired'] ?? 0 ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-50 rounded-lg flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-600 text-lg"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Requires Attention -->
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                                <i class="fas fa-exclamation-triangle text-orange-500 mr-2 text-xs"></i>
                                Requires Attention
                            </h3>
                            <?php if ($attentionCount > 5): ?>
                                <a href="#domains" onclick="switchTab('domains'); document.getElementById('domainStatusFilter').value='Expiring Soon'; filterDomains(); return false;" class="text-xs text-primary hover:text-primary-dark font-medium">
                                    View all <?= $attentionCount ?>
                                    <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($attentionPreview)): ?>
                        <div class="p-4 space-y-2">
                            <?php foreach ($attentionPreview as $ad): ?>
                                <?php $isExpired = ($ad['daysLeft'] ?? 0) < 0; ?>
                                <div class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:border-gray-300 hover:shadow-sm transition-all duration-200">
                                    <div class="flex-1 min-w-0">
                                        <a href="/domains/<?= $ad['id'] ?>" class="text-sm font-medium text-gray-900 hover:text-primary truncate block"><?= htmlspecialchars($ad['domain_name']) ?></a>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            <?= !empty($ad['expiration_date']) ? date('M d, Y', strtotime($ad['expiration_date'])) : 'Unknown' ?>
                                            <?php if ($isExpired): ?>
                                                <span class="text-red-600 font-semibold ml-2">Expired <?= abs($ad['daysLeft']) ?> day<?= abs($ad['daysLeft']) != 1 ? 's' : '' ?> ago</span>
                                            <?php else: ?>
                                                <span class="<?= $ad['daysLeft'] <= 7 ? 'text-red-600' : 'text-orange-600' ?> font-semibold ml-2"><?= $ad['daysLeft'] ?> day<?= $ad['daysLeft'] != 1 ? 's' : '' ?> left</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <a href="/domains/<?= $ad['id'] ?>" class="text-gray-400 hover:text-primary ml-3">
                                        <i class="fas fa-chevron-right text-sm"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-6 text-center">
                            <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                            <p class="text-sm text-gray-600">All domains are in good standing</p>
                            <p class="text-xs text-gray-400 mt-1">No expired or expiring domains</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Top Registrars -->
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-building text-gray-400 mr-2 text-xs"></i>
                            Registrar Distribution
                        </h3>
                        <span class="text-xs text-gray-500"><?= count($registrarCounts) ?> registrar<?= count($registrarCounts) != 1 ? 's' : '' ?></span>
                    </div>
                    <div class="p-5">
                        <?php if (!empty($topRegistrars)): ?>
                            <div class="space-y-3">
                                <?php foreach ($topRegistrars as $regName => $regCount): ?>
                                    <?php $regPct = $totalDomains > 0 ? round(($regCount / $totalDomains) * 100) : 0; ?>
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
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-tags text-gray-400 mr-2 text-xs"></i>
                            Tag Usage
                        </h3>
                        <span class="text-xs text-gray-500"><?= $totalTags ?> tag<?= $totalTags != 1 ? 's' : '' ?> total</span>
                    </div>
                    <div class="p-5">
                        <?php if (!empty($topTags)): ?>
                            <div class="space-y-3">
                                <?php foreach ($topTags as $tt): ?>
                                    <?php $pct = $totalDomains > 0 ? round(($tt['usage_count'] / $totalDomains) * 100) : 0; ?>
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
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-bell text-gray-400 mr-2 text-xs"></i>
                            Notification Coverage
                        </h3>
                        <span class="text-xs text-gray-500"><?= $totalGroups ?> group<?= $totalGroups != 1 ? 's' : '' ?>, <?= $totalChannels ?> channel<?= $totalChannels != 1 ? 's' : '' ?></span>
                    </div>
                    <div class="p-5">
                        <?php if ($totalDomains > 0): ?>
                            <?php $coveragePct = round(($domainsWithGroup / $totalDomains) * 100); ?>
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
                                    <p class="text-lg font-bold text-green-700"><?= $domainsWithGroup ?></p>
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
        </div>

        <!-- Domains Tab -->
        <div id="content-domains" class="tab-content hidden">
            <?php if (!empty($domains)): ?>
                <?php
                // Build unique tag and group lists for filters
                $domainTagNames = [];
                $domainGroupNames = [];
                foreach ($domains as $d) {
                    if (!empty($d['tags'])) {
                        foreach (explode(',', $d['tags']) as $dt) {
                            $dt = trim($dt);
                            if ($dt && !in_array($dt, $domainTagNames)) $domainTagNames[] = $dt;
                        }
                    }
                    if (!empty($d['group_name']) && !in_array($d['group_name'], $domainGroupNames)) {
                        $domainGroupNames[] = $d['group_name'];
                    }
                }
                sort($domainTagNames);
                sort($domainGroupNames);
                ?>
                <!-- Filters -->
                <div class="mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Search</label>
                            <div class="relative">
                                <input type="text" id="domainSearch" placeholder="Search domains..." onkeyup="filterDomains()" class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                            <select id="domainStatusFilter" onchange="filterDomains()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                                <option value="">All Statuses</option>
                                <option value="Active">Active</option>
                                <option value="Expiring Soon">Expiring Soon</option>
                                <option value="Expired">Expired</option>
                                <option value="Available">Available</option>
                                <option value="Redemption Period">Redemption Period</option>
                                <option value="Pending Delete">Pending Delete</option>
                                <option value="Error">Error</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Tags</label>
                            <select id="domainTagFilter" onchange="filterDomains()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                                <option value="">All Tags</option>
                                <?php foreach ($domainTagNames as $dtn): ?>
                                    <option value="<?= htmlspecialchars($dtn) ?>"><?= htmlspecialchars(ucfirst($dtn)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Group</label>
                            <select id="domainGroupFilter" onchange="filterDomains()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                                <option value="">All Groups</option>
                                <?php foreach ($domainGroupNames as $dgn): ?>
                                    <option value="<?= htmlspecialchars($dgn) ?>"><?= htmlspecialchars($dgn) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button onclick="clearDomainFilters()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                                <i class="fas fa-times mr-1"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="domainsTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortDomains(0)">
                                    <span class="flex items-center">Domain <i class="fas fa-sort text-gray-400 ml-1 text-xs" id="domain-sort-icon-0"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortDomains(1)">
                                    <span class="flex items-center">Registrar <i class="fas fa-sort text-gray-400 ml-1 text-xs" id="domain-sort-icon-1"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortDomains(2)">
                                    <span class="flex items-center">Expiration <i class="fas fa-sort text-gray-400 ml-1 text-xs" id="domain-sort-icon-2"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortDomains(3)">
                                    <span class="flex items-center">Status <i class="fas fa-sort text-gray-400 ml-1 text-xs" id="domain-sort-icon-3"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortDomains(4)">
                                    <span class="flex items-center">Group <i class="fas fa-sort text-gray-400 ml-1 text-xs" id="domain-sort-icon-4"></i></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($domains as $domain): ?>
                                <tr class="hover:bg-gray-50 transition-colors domain-row"
                                    data-domain-name="<?= htmlspecialchars(strtolower($domain['domain_name'])) ?>"
                                    data-domain-status="<?= htmlspecialchars($domain['statusText'] ?? '') ?>"
                                    data-domain-tags="<?= htmlspecialchars(strtolower($domain['tags'] ?? '')) ?>"
                                    data-domain-group="<?= htmlspecialchars($domain['group_name'] ?? '') ?>">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-globe text-primary"></i>
                                            </div>
                                            <div class="ml-4">
                                                <a href="/domains/<?= $domain['id'] ?>" class="text-sm font-semibold text-gray-900 hover:text-primary"><?= htmlspecialchars($domain['domain_name']) ?></a>
                                                <div class="flex items-center gap-1.5 mt-1">
                                                    <?php
                                                    $domainTags = !empty($domain['tags']) ? explode(',', $domain['tags']) : [];
                                                    $tagColors = !empty($domain['tag_colors']) ? explode('|', $domain['tag_colors']) : [];
                                                    foreach ($domainTags as $index => $dtag):
                                                        $dtag = trim($dtag);
                                                        $colorClass = isset($tagColors[$index]) ? $tagColors[$index] : 'bg-gray-100 text-gray-700 border-gray-200';
                                                    ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border <?= $colorClass ?>">
                                                            <i class="fas fa-tag mr-1" style="font-size: 9px;"></i>
                                                            <?= htmlspecialchars(ucfirst($dtag)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (!empty($domain['registrar'])): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-building text-gray-400 mr-2"></i>
                                                <span class="text-sm text-gray-900"><?= htmlspecialchars($domain['registrar']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (!empty($domain['expiration_date'])): ?>
                                            <div class="text-sm">
                                                <div class="font-medium text-gray-900"><?= date('M d, Y', strtotime($domain['expiration_date'])) ?></div>
                                                <div class="text-xs <?= $domain['expiryClass'] ?>">
                                                    <?= $domain['daysLeft'] ?> days
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border <?= $domain['statusClass'] ?>">
                                            <i class="fas <?= $domain['statusIcon'] ?> mr-1"></i>
                                            <?= $domain['statusText'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= htmlspecialchars($domain['group_name'] ?? '—') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-200">
                    <div class="text-sm text-gray-500" id="domainPaginationInfo">
                        Showing 1-<?= min(25, $totalDomains) ?> of <?= $totalDomains ?> domains
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="domainPageNav('prev')" id="domainPrevBtn" class="px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            <i class="fas fa-chevron-left mr-1"></i> Previous
                        </button>
                        <span id="domainPageNumbers" class="flex items-center gap-1"></span>
                        <button onclick="domainPageNav('next')" id="domainNextBtn" class="px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="p-12 text-center">
                    <i class="fas fa-globe text-gray-300 text-4xl mb-3"></i>
                    <p class="text-sm text-gray-600">This user has no domains</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tags Tab -->
        <div id="content-tags" class="tab-content hidden">
            <?php if (!empty($tags)): ?>
                <!-- Filters -->
                <div class="mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="relative">
                            <input type="text" id="tagSearch" placeholder="Search tags..." onkeyup="filterTags()" class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                        </div>
                        <div>
                            <select id="tagTypeFilter" onchange="filterTags()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                                <option value="">All Types</option>
                                <option value="personal">Personal</option>
                                <option value="global">Global</option>
                            </select>
                        </div>
                        <div class="flex items-center">
                            <button onclick="clearTagFilters()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                                <i class="fas fa-times mr-1"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="tagsTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortTable('tagsTable', 0)">
                                    <span class="flex items-center">Tag <i class="fas fa-sort text-gray-400 ml-1 text-xs"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortTable('tagsTable', 1)">
                                    <span class="flex items-center">Description <i class="fas fa-sort text-gray-400 ml-1 text-xs"></i></span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider cursor-pointer hover:text-primary" onclick="sortTable('tagsTable', 2)">
                                    <span class="flex items-center">Domains <i class="fas fa-sort text-gray-400 ml-1 text-xs"></i></span>
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($tags as $tag): ?>
                                <?php $tagDomainsList = $tag['domains'] ?? []; ?>
                                <tr class="hover:bg-gray-50 transition-colors tag-row" data-tag-type="<?= $tag['user_id'] === null ? 'global' : 'personal' ?>">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border <?= htmlspecialchars($tag['color'] ?? 'bg-gray-100 text-gray-800 border-gray-300') ?>">
                                                <i class="fas fa-tag mr-1" style="font-size: 9px;"></i>
                                                <?= htmlspecialchars($tag['name']) ?>
                                            </span>
                                            <?php if ($tag['user_id'] === null): ?>
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <i class="fas fa-globe mr-1" style="font-size: 8px;"></i>
                                                    Global
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900">
                                            <?= !empty($tag['description']) ? htmlspecialchars($tag['description']) : '<span class="text-gray-400 italic">No description</span>' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center text-sm text-gray-500">
                                            <i class="fas fa-link mr-1"></i>
                                            <?= $tag['usage_count'] ?? 0 ?> domain<?= ($tag['usage_count'] ?? 0) != 1 ? 's' : '' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex items-center justify-end space-x-2">
                                            <?php if (!empty($tagDomainsList)): ?>
                                                <button onclick="toggleTagDomains(<?= $tag['id'] ?>)" class="text-gray-500 hover:text-primary" title="Show domains">
                                                    <i class="fas fa-chevron-down text-xs" id="tag-chevron-<?= $tag['id'] ?>"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="/tags/<?= $tag['id'] ?>" class="text-blue-600 hover:text-blue-800" title="View tag page">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Expandable domains list for this tag -->
                                <?php if (!empty($tagDomainsList)): ?>
                                    <tr id="tag-domains-<?= $tag['id'] ?>" class="hidden tag-domains-row" data-parent-tag="<?= $tag['id'] ?>">
                                        <td colspan="4" class="px-6 py-0">
                                            <div class="py-3 pl-4 border-l-2 border-primary ml-2">
                                                <div class="space-y-1.5">
                                                    <?php foreach ($tagDomainsList as $td): ?>
                                                        <div class="flex items-center justify-between py-1.5 px-3 bg-gray-50 rounded-lg text-sm">
                                                            <div class="flex items-center gap-3">
                                                                <i class="fas fa-globe text-primary text-xs"></i>
                                                                <a href="/domains/<?= $td['id'] ?>" class="font-medium text-gray-900 hover:text-primary"><?= htmlspecialchars($td['domain_name']) ?></a>
                                                            </div>
                                                            <div class="flex items-center gap-4">
                                                                <?php if (!empty($td['expiration_date'])): ?>
                                                                    <span class="text-xs text-gray-500"><?= date('M d, Y', strtotime($td['expiration_date'])) ?></span>
                                                                <?php endif; ?>
                                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border <?= $td['statusClass'] ?>">
                                                                    <i class="fas <?= $td['statusIcon'] ?> mr-1" style="font-size: 8px;"></i>
                                                                    <?= $td['statusText'] ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-12 text-center">
                    <i class="fas fa-tags text-gray-300 text-4xl mb-3"></i>
                    <p class="text-sm text-gray-600">This user has no tags</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notification Groups Tab -->
        <div id="content-groups" class="tab-content hidden">
            <?php if (!empty($groups)): ?>
                <div class="space-y-2">
                    <?php foreach ($groups as $group): ?>
                        <?php $groupChannels = $group['channels'] ?? []; ?>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <!-- Group Header (clickable) -->
                            <button onclick="toggleGroup(<?= $group['id'] ?>)" class="w-full flex items-center justify-between bg-gray-50 hover:bg-gray-100 transition-colors p-4 text-left">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-bell text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($group['name']) ?></div>
                                        <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($group['description'] ?? 'No description') ?></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                        <i class="fas fa-plug mr-1"></i>
                                        <?= $group['channel_count'] ?? 0 ?> channel<?= ($group['channel_count'] ?? 0) != 1 ? 's' : '' ?>
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                        <i class="fas fa-globe mr-1"></i>
                                        <?= $group['domain_count'] ?? 0 ?> domain<?= ($group['domain_count'] ?? 0) != 1 ? 's' : '' ?>
                                    </span>
                                    <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform" id="group-chevron-<?= $group['id'] ?>"></i>
                                </div>
                            </button>
                            <!-- Channels (hidden by default) -->
                            <div id="group-channels-<?= $group['id'] ?>" class="hidden border-t border-gray-200">
                                <?php if (!empty($groupChannels)): ?>
                                    <div class="divide-y divide-gray-100">
                                        <?php
                                        $channelIcons = [
                                            'email' => ['icon' => 'fa-envelope', 'color' => 'text-blue-500', 'bg' => 'bg-blue-50'],
                                            'telegram' => ['icon' => 'fa-paper-plane', 'color' => 'text-sky-500', 'bg' => 'bg-sky-50'],
                                            'discord' => ['icon' => 'fa-comment', 'color' => 'text-indigo-500', 'bg' => 'bg-indigo-50'],
                                            'slack' => ['icon' => 'fa-hashtag', 'color' => 'text-purple-500', 'bg' => 'bg-purple-50'],
                                            'webhook' => ['icon' => 'fa-link', 'color' => 'text-gray-500', 'bg' => 'bg-gray-50'],
                                            'pushover' => ['icon' => 'fa-mobile-alt', 'color' => 'text-green-500', 'bg' => 'bg-green-50'],
                                            'mattermost' => ['icon' => 'fa-comments', 'color' => 'text-blue-600', 'bg' => 'bg-blue-50'],
                                        ];
                                        ?>
                                        <?php foreach ($groupChannels as $channel): ?>
                                            <?php
                                            $type = $channel['channel_type'] ?? 'webhook';
                                            $chIcon = $channelIcons[$type] ?? ['icon' => 'fa-bell', 'color' => 'text-gray-500', 'bg' => 'bg-gray-50'];
                                            $isChActive = (bool)($channel['is_active'] ?? false);
                                            ?>
                                            <div class="flex items-center justify-between px-4 py-3 <?= $isChActive ? '' : 'opacity-50' ?>">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 <?= $chIcon['bg'] ?> rounded-lg flex items-center justify-center flex-shrink-0">
                                                        <i class="fas <?= $chIcon['icon'] ?> <?= $chIcon['color'] ?> text-sm"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($channel['name'] ?? ucfirst($type)) ?></p>
                                                        <p class="text-xs text-gray-500"><?= ucfirst($type) ?></p>
                                                    </div>
                                                </div>
                                                <?php if ($isChActive): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                                        <i class="fas fa-check-circle mr-1" style="font-size: 8px;"></i>Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                                        <i class="fas fa-times-circle mr-1" style="font-size: 8px;"></i>Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="p-4 text-center text-sm text-gray-500">
                                        <i class="fas fa-plug text-gray-300 mr-1"></i>
                                        No channels configured
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-12 text-center">
                    <i class="fas fa-bell-slash text-gray-300 text-4xl mb-3"></i>
                    <p class="text-sm text-gray-600">This user has no notification groups</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function switchTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-primary', 'text-primary');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    document.getElementById('content-' + tabName).classList.remove('hidden');
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('border-primary', 'text-primary');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    history.replaceState(null, null, '#' + tabName);
}

window.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.substring(1);
    const validTabs = ['overview', 'domains', 'tags', 'groups'];
    switchTab(hash && validTabs.includes(hash) ? hash : 'overview');
    
    // Initialize domain pagination
    filteredDomainRows = Array.from(document.querySelectorAll('.domain-row'));
    renderDomainPage();
});

// Domain filtering with pagination
const DOMAINS_PER_PAGE = 25;
let domainCurrentPage = 1;
let filteredDomainRows = [];

function filterDomains() {
    const query = document.getElementById('domainSearch').value.toLowerCase();
    const statusFilter = document.getElementById('domainStatusFilter').value;
    const tagFilter = document.getElementById('domainTagFilter').value.toLowerCase();
    const groupFilter = document.getElementById('domainGroupFilter').value;
    
    const allRows = Array.from(document.querySelectorAll('.domain-row'));
    
    filteredDomainRows = allRows.filter(row => {
        const name = row.getAttribute('data-domain-name') || '';
        const status = row.getAttribute('data-domain-status') || '';
        const tags = row.getAttribute('data-domain-tags') || '';
        const group = row.getAttribute('data-domain-group') || '';
        const text = row.textContent.toLowerCase();
        
        const matchesSearch = !query || text.includes(query);
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesTag = !tagFilter || tags.split(',').map(t => t.trim()).includes(tagFilter);
        const matchesGroup = !groupFilter || group === groupFilter;
        
        return matchesSearch && matchesStatus && matchesTag && matchesGroup;
    });
    
    domainCurrentPage = 1;
    renderDomainPage();
}

function renderDomainPage() {
    const allRows = Array.from(document.querySelectorAll('.domain-row'));
    const total = filteredDomainRows.length;
    const totalPages = Math.max(1, Math.ceil(total / DOMAINS_PER_PAGE));
    const start = (domainCurrentPage - 1) * DOMAINS_PER_PAGE;
    const end = start + DOMAINS_PER_PAGE;
    
    // Hide all rows first
    allRows.forEach(row => row.style.display = 'none');
    
    // Show only current page rows
    filteredDomainRows.forEach((row, i) => {
        row.style.display = (i >= start && i < end) ? '' : 'none';
    });
    
    // Update pagination info
    const info = document.getElementById('domainPaginationInfo');
    if (info) {
        if (total === 0) {
            info.textContent = 'No domains match your filters';
        } else {
            info.textContent = 'Showing ' + (start + 1) + '-' + Math.min(end, total) + ' of ' + total + ' domains';
        }
    }
    
    // Update buttons
    const prevBtn = document.getElementById('domainPrevBtn');
    const nextBtn = document.getElementById('domainNextBtn');
    if (prevBtn) prevBtn.disabled = domainCurrentPage <= 1;
    if (nextBtn) nextBtn.disabled = domainCurrentPage >= totalPages;
    
    // Render page numbers
    const pageNums = document.getElementById('domainPageNumbers');
    if (pageNums) {
        pageNums.innerHTML = '';
        for (let p = 1; p <= totalPages && p <= 7; p++) {
            const btn = document.createElement('button');
            btn.textContent = p;
            btn.className = 'px-3 py-1.5 rounded-lg text-sm ' + 
                (p === domainCurrentPage 
                    ? 'bg-primary text-white font-medium' 
                    : 'border border-gray-300 text-gray-700 hover:bg-gray-50');
            btn.onclick = () => { domainCurrentPage = p; renderDomainPage(); };
            pageNums.appendChild(btn);
        }
        if (totalPages > 7) {
            const dots = document.createElement('span');
            dots.textContent = '...';
            dots.className = 'px-2 text-gray-400 text-sm';
            pageNums.appendChild(dots);
        }
    }
}

function domainPageNav(dir) {
    const totalPages = Math.max(1, Math.ceil(filteredDomainRows.length / DOMAINS_PER_PAGE));
    if (dir === 'prev' && domainCurrentPage > 1) domainCurrentPage--;
    if (dir === 'next' && domainCurrentPage < totalPages) domainCurrentPage++;
    renderDomainPage();
}

function clearDomainFilters() {
    document.getElementById('domainSearch').value = '';
    document.getElementById('domainStatusFilter').value = '';
    document.getElementById('domainTagFilter').value = '';
    document.getElementById('domainGroupFilter').value = '';
    filterDomains();
}

// Domain column sorting (integrated with pagination)
let domainSortCol = -1;
let domainSortDir = '';
function sortDomains(colIndex) {
    domainSortDir = (domainSortCol === colIndex && domainSortDir === 'asc') ? 'desc' : 'asc';
    domainSortCol = colIndex;
    
    filteredDomainRows.sort((a, b) => {
        const aText = a.cells[colIndex] ? a.cells[colIndex].textContent.trim().toLowerCase() : '';
        const bText = b.cells[colIndex] ? b.cells[colIndex].textContent.trim().toLowerCase() : '';
        if (domainSortDir === 'asc') return aText.localeCompare(bText, undefined, { numeric: true });
        return bText.localeCompare(aText, undefined, { numeric: true });
    });
    
    // Also reorder in the DOM tbody so page rendering stays consistent
    const tbody = document.getElementById('domainsTable').querySelector('tbody');
    filteredDomainRows.forEach(row => tbody.appendChild(row));
    
    domainCurrentPage = 1;
    renderDomainPage();
    
    // Update sort icons
    for (let i = 0; i <= 4; i++) {
        const icon = document.getElementById('domain-sort-icon-' + i);
        if (!icon) continue;
        if (i === colIndex) {
            icon.className = 'fas ' + (domainSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') + ' text-primary ml-1 text-xs';
        } else {
            icon.className = 'fas fa-sort text-gray-400 ml-1 text-xs';
        }
    }
}

// Client-side table sorting (handles expandable child rows)
let sortDirections = {};
function sortTable(tableId, colIndex) {
    const table = document.getElementById(tableId);
    const tbody = table.querySelector('tbody');
    
    // Collect main rows with their child (expandable) rows
    const mainRows = [];
    const allRows = Array.from(tbody.querySelectorAll('tr'));
    
    allRows.forEach(row => {
        if (row.classList.contains('tag-domains-row')) {
            // This is a child row, attach to previous main row
            if (mainRows.length > 0) {
                mainRows[mainRows.length - 1].children.push(row);
            }
        } else {
            mainRows.push({ row: row, children: [] });
        }
    });
    
    const dir = sortDirections[tableId + '_' + colIndex] === 'asc' ? 'desc' : 'asc';
    sortDirections[tableId + '_' + colIndex] = dir;
    
    mainRows.sort((a, b) => {
        const aText = a.row.cells[colIndex] ? a.row.cells[colIndex].textContent.trim().toLowerCase() : '';
        const bText = b.row.cells[colIndex] ? b.row.cells[colIndex].textContent.trim().toLowerCase() : '';
        if (dir === 'asc') return aText.localeCompare(bText, undefined, { numeric: true });
        return bText.localeCompare(aText, undefined, { numeric: true });
    });
    
    // Re-append rows in sorted order (main row + its children)
    mainRows.forEach(item => {
        tbody.appendChild(item.row);
        item.children.forEach(child => tbody.appendChild(child));
    });
    
    // Update sort icons
    table.querySelectorAll('thead th').forEach((th, i) => {
        const icon = th.querySelector('i');
        if (!icon) return;
        if (i === colIndex) {
            icon.className = 'fas ' + (dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') + ' text-primary ml-1 text-xs';
        } else {
            icon.className = 'fas fa-sort text-gray-400 ml-1 text-xs';
        }
    });
}

// Tag search and type filter
function filterTags() {
    const query = document.getElementById('tagSearch').value.toLowerCase();
    const typeFilter = document.getElementById('tagTypeFilter').value;
    
    document.querySelectorAll('.tag-row').forEach(row => {
        const text = row.textContent.toLowerCase();
        const tagType = row.getAttribute('data-tag-type');
        const matchesSearch = !query || text.includes(query);
        const matchesType = !typeFilter || tagType === typeFilter;
        const visible = matchesSearch && matchesType;
        
        row.style.display = visible ? '' : 'none';
        
        // Also hide/show child domain rows
        const tagId = row.querySelector('[id^="tag-chevron-"]');
        if (tagId) {
            const id = tagId.id.replace('tag-chevron-', '');
            const childRow = document.getElementById('tag-domains-' + id);
            if (childRow) {
                childRow.style.display = visible ? '' : 'none';
                if (!visible) childRow.classList.add('hidden');
            }
        }
    });
}

function clearTagFilters() {
    document.getElementById('tagSearch').value = '';
    document.getElementById('tagTypeFilter').value = '';
    filterTags();
}

// Toggle tag domains dropdown
function toggleTagDomains(tagId) {
    const domainsRow = document.getElementById('tag-domains-' + tagId);
    const chevron = document.getElementById('tag-chevron-' + tagId);
    
    if (domainsRow.classList.contains('hidden')) {
        domainsRow.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
    } else {
        domainsRow.classList.add('hidden');
        chevron.style.transform = 'rotate(0deg)';
    }
}

// Toggle notification group channels
function toggleGroup(groupId) {
    const channels = document.getElementById('group-channels-' + groupId);
    const chevron = document.getElementById('group-chevron-' + groupId);
    
    if (channels.classList.contains('hidden')) {
        channels.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
    } else {
        channels.classList.add('hidden');
        chevron.style.transform = 'rotate(0deg)';
    }
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>
