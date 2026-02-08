<!-- Top Navigation Bar -->
<!-- Notification data ($recentNotifications, $unreadNotifications) loaded in base.php -->
<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 md:left-64 right-0 z-20">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Left: Menu button and Page Header -->
            <div class="flex items-center min-w-0">
                <button onclick="toggleSidebar()" class="text-gray-500 hover:text-gray-700 focus:outline-none focus:text-gray-700 md:hidden mr-4">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                
                <!-- Page Title & Description -->
                <div class="hidden md:block">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center">
                        <?php if (isset($pageIcon)): ?>
                            <i class="<?= $pageIcon ?> text-primary mr-2"></i>
                        <?php endif; ?>
                        <?= $pageTitle ?? $title ?? 'Dashboard' ?>
                    </h2>
                    <?php if (isset($pageDescription)): ?>
                        <p class="text-sm text-gray-600 mt-0.5"><?= $pageDescription ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Center: Search Bar -->
            <div class="flex-1 max-w-2xl mx-2 sm:mx-4 lg:mx-8">
                <form action="/search" method="GET" class="relative" id="globalSearchForm">
                    <input type="text" 
                           name="q"
                           placeholder="Search..." 
                           class="w-full pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                           id="globalSearchInput"
                           autocomplete="off">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                    
                    <!-- Search Results Dropdown -->
                    <div id="searchDropdown" class="hidden absolute top-full left-0 right-0 mt-2 bg-white rounded-lg shadow-xl border border-gray-200 max-h-96 overflow-y-auto z-50">
                        <!-- Loading state -->
                        <div id="searchLoading" class="hidden p-4 text-center">
                            <i class="fas fa-spinner fa-spin text-primary"></i>
                            <p class="text-sm text-gray-600 mt-2">Searching...</p>
                        </div>
                        
                        <!-- Results will be inserted here -->
                        <div id="searchResults"></div>
                    </div>
                </form>
            </div>

            <!-- Right: Actions & User -->
            <div class="flex items-center space-x-1 sm:space-x-2">
                <!-- Quick Actions Dropdown -->
                <div class="relative">
                    <button onclick="toggleQuickActions()" title="Quick Actions" class="flex items-center justify-center w-9 h-9 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors duration-150">
                        <i class="fas fa-plus"></i>
                    </button>
                    <div id="quickActionsDropdown" class="dropdown-menu absolute right-0 mt-2 w-52 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden py-1">
                        <div class="px-3 py-2 border-b border-gray-100">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Quick Actions</p>
                        </div>
                        <a href="/domains/create" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-blue-50 hover:text-primary transition-colors">
                            <div class="w-7 h-7 bg-blue-50 rounded-md flex items-center justify-center mr-3">
                                <i class="fas fa-globe text-blue-600 text-xs"></i>
                            </div>
                            Add Domain
                        </a>
                        <a href="/groups/create" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-700 transition-colors">
                            <div class="w-7 h-7 bg-green-50 rounded-md flex items-center justify-center mr-3">
                                <i class="fas fa-bell text-green-600 text-xs"></i>
                            </div>
                            Create Group
                        </a>
                        <a href="/tags" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition-colors">
                            <div class="w-7 h-7 bg-purple-50 rounded-md flex items-center justify-center mr-3">
                                <i class="fas fa-tag text-purple-600 text-xs"></i>
                            </div>
                            Create Tag
                        </a>
                        <a href="/debug/whois" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
                            <div class="w-7 h-7 bg-indigo-50 rounded-md flex items-center justify-center mr-3">
                                <i class="fas fa-search text-indigo-600 text-xs"></i>
                            </div>
                            WHOIS Lookup
                        </a>
                    </div>
                </div>
                
                <!-- Notifications -->
                <div class="relative">
                    <button onclick="toggleNotifications()" title="Notifications" class="relative flex items-center justify-center w-9 h-9 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors duration-150">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="absolute top-1 right-1 flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                            </span>
                        <?php endif; ?>
                    </button>
                    
                    <!-- Notifications Dropdown -->
                    <div id="notificationsDropdown" class="dropdown-menu absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-lg shadow-xl border border-gray-200 max-h-[32rem] overflow-hidden">
                        <!-- Header -->
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span id="dropdownHeaderBadge" class="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs font-semibold rounded"><?= $unreadNotifications ?> new</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Notifications List (Scrollable) -->
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (!empty($recentNotifications)): ?>
                                <?php foreach ($recentNotifications as $notif): ?>
                                    <?php
                                    // Build the click URL: if domain notification, go to domain; otherwise just mark as read
                                    $hasDomain = !empty($notif['domain_id']);
                                    $notifUrl = $hasDomain 
                                        ? '/notifications/' . $notif['id'] . '/mark-read?redirect=domain&domain_id=' . $notif['domain_id']
                                        : '/notifications/' . $notif['id'] . '/mark-read';
                                    ?>
                                    <div class="px-4 py-3 hover:bg-gray-50 border-b border-gray-100 bg-blue-50 transition-colors notification-item" data-id="<?= $notif['id'] ?>">
                                        <div class="flex items-start space-x-3">
                                            <?php $loginData = $notif['login_data'] ?? null; ?>
                                            <?php if ($loginData && $notif['type'] === 'session_failed'): ?>
                                                <!-- Failed login notification -->
                                                <a href="<?= $notifUrl ?>" class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0 hover:opacity-80 transition-opacity">
                                                    <?php if (($loginData['country_code'] ?? 'xx') !== 'xx'): ?>
                                                        <span class="fi fi-<?= strtolower($loginData['country_code']) ?> text-base rounded-sm"></span>
                                                    <?php else: ?>
                                                        <i class="fas fa-shield-alt text-red-600 text-sm"></i>
                                                    <?php endif; ?>
                                                </a>
                                                <a href="<?= $notifUrl ?>" class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-sm font-semibold text-red-700"><?= htmlspecialchars($notif['title']) ?></p>
                                                        <span class="w-2 h-2 bg-red-500 rounded-full flex-shrink-0"></span>
                                                    </div>
                                                    <p class="text-xs text-gray-600 mt-0.5">
                                                        <?= htmlspecialchars(\App\Helpers\LayoutHelper::formatLoginDropdown($loginData)) ?>
                                                    </p>
                                                    <p class="text-xs text-gray-400 mt-1">
                                                        <i class="fas fa-<?= htmlspecialchars($loginData['device_icon'] ?? 'desktop') ?> mr-0.5"></i>
                                                        <?= htmlspecialchars($loginData['reason'] ?? 'Failed') ?> · <?= $notif['time_ago'] ?>
                                                    </p>
                                                </a>
                                            <?php elseif ($loginData && $notif['type'] === 'session_new'): ?>
                                                <!-- Session notification with flag icon -->
                                                <a href="<?= $notifUrl ?>" class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0 hover:opacity-80 transition-opacity">
                                                    <?php if ($loginData['country_code'] !== 'xx'): ?>
                                                        <span class="fi fi-<?= strtolower($loginData['country_code']) ?> text-base rounded-sm"></span>
                                                    <?php else: ?>
                                                        <i class="fas fa-sign-in-alt text-blue-600 text-sm"></i>
                                                    <?php endif; ?>
                                                </a>
                                                <a href="<?= $notifUrl ?>" class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($notif['title']) ?></p>
                                                        <span class="w-2 h-2 bg-blue-500 rounded-full flex-shrink-0"></span>
                                                    </div>
                                                    <p class="text-xs text-gray-600 mt-0.5">
                                                        <?= htmlspecialchars(\App\Helpers\LayoutHelper::formatLoginDropdown($loginData)) ?>
                                                    </p>
                                                    <p class="text-xs text-gray-400 mt-1">
                                                        <i class="fas fa-<?= htmlspecialchars($loginData['device_icon'] ?? 'desktop') ?> mr-0.5"></i>
                                                        <?= htmlspecialchars($loginData['method'] ?? 'Login') ?> · <?= $notif['time_ago'] ?>
                                                    </p>
                                                </a>
                                            <?php else: ?>
                                                <!-- Standard notification -->
                                                <a href="<?= $notifUrl ?>" class="w-8 h-8 bg-<?= $notif['color'] ?>-100 rounded-lg flex items-center justify-center flex-shrink-0 hover:opacity-80 transition-opacity">
                                                    <i class="fas fa-<?= $notif['icon'] ?> text-<?= $notif['color'] ?>-600 text-sm"></i>
                                                </a>
                                                <a href="<?= $notifUrl ?>" class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($notif['title']) ?></p>
                                                        <span class="w-2 h-2 bg-blue-500 rounded-full flex-shrink-0"></span>
                                                    </div>
                                                    <p class="text-xs text-gray-600 mt-0.5"><?= htmlspecialchars($notif['message']) ?></p>
                                                    <p class="text-xs text-gray-400 mt-1">
                                                        <?= $notif['time_ago'] ?>
                                                        <?php if ($hasDomain): ?>
                                                            <span class="text-primary ml-1"><i class="fas fa-external-link-alt text-[10px]"></i> View domain</span>
                                                        <?php endif; ?>
                                                    </p>
                                                </a>
                                            <?php endif; ?>
                                            <button onclick="event.stopPropagation(); markNotifRead(<?= $notif['id'] ?>, this)" 
                                                    class="w-7 h-7 flex items-center justify-center text-gray-400 hover:text-green-600 hover:bg-green-50 rounded transition-colors flex-shrink-0" 
                                                    title="Mark as read">
                                                <i class="fas fa-check text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="px-4 py-8 text-center">
                                    <i class="fas fa-bell-slash text-gray-300 text-3xl mb-2"></i>
                                    <p class="text-sm text-gray-600">No new notifications</p>
                                    <p class="text-xs text-gray-400 mt-0.5">You're all caught up!</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer - View All Button -->
                        <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                            <a href="/notifications" class="block text-center text-sm font-medium text-primary hover:text-primary-dark">
                                View All Notifications
                                <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Divider -->
                <div class="hidden md:block h-8 w-px bg-gray-300"></div>
                
                <!-- User Dropdown -->
                <div class="relative">
                    <button onclick="toggleDropdown()" class="flex items-center space-x-3 p-2 hover:bg-gray-100 rounded-lg transition-colors duration-150 focus:outline-none">
                        <?php
                        // Get user data for avatar
                        $userModel = new \App\Models\User();
                        $user = $userModel->find($_SESSION['user_id'] ?? 0);
                        $avatar = $user ? \App\Helpers\AvatarHelper::getAvatar($user, 36) : null;
                        ?>
                        <?php if ($avatar && ($avatar['type'] === 'uploaded' || $avatar['type'] === 'gravatar')): ?>
                            <img src="<?= htmlspecialchars($avatar['url']) ?>" 
                                 alt="<?= htmlspecialchars($avatar['alt']) ?>" 
                                 class="w-9 h-9 rounded-full object-cover"
                                 loading="lazy">
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-full bg-primary flex items-center justify-center text-white font-semibold">
                                <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="hidden lg:block text-left">
                            <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400 text-xs hidden md:block"></i>
                    </button>

                    <!-- Dropdown Menu -->
                    <div id="userDropdown" class="dropdown-menu absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden pb-2">
                        <!-- Welcome Header -->
                        <div class="px-4 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                            <div class="text-center">
                                <div class="relative w-12 h-12 mx-auto mb-2">
                                    <?php if ($avatar && ($avatar['type'] === 'uploaded' || $avatar['type'] === 'gravatar')): ?>
                                        <img src="<?= htmlspecialchars($avatar['url']) ?>" 
                                             alt="<?= htmlspecialchars($avatar['alt']) ?>" 
                                             class="w-12 h-12 rounded-full object-cover"
                                             loading="lazy">
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-full bg-primary flex items-center justify-center text-white font-bold text-lg">
                                            <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Online status dot -->
                                    <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white flex items-center justify-center">
                                        <div class="w-2 h-2 bg-white rounded-full"></div>
                                    </div>
                                </div>
                                <p class="text-sm font-semibold text-gray-900">Welcome back!</p>
                                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></p>
                                <!-- Role indicator -->
                                <div class="mt-2">
                                    <span class="inline-flex items-center px-2 py-1 bg-<?= $_SESSION['role'] === 'admin' ? 'amber' : 'blue' ?>-100 text-<?= $_SESSION['role'] === 'admin' ? 'amber' : 'blue' ?>-700 text-xs font-medium rounded-full">
                                        <i class="fas fa-<?= $_SESSION['role'] === 'admin' ? 'crown' : 'user' ?> mr-1"></i>
                                        <?= ucfirst($_SESSION['role'] ?? 'user') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <a href="/profile#profile" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                            <i class="fas fa-user-circle w-5 text-gray-400 mr-3"></i>
                            My Profile
                        </a>
                        
                        <a href="/profile#security" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                            <i class="fas fa-cog w-5 text-gray-400 mr-3"></i>
                            Account Settings
                        </a>
                        
                        <a href="/notifications" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                            <i class="fas fa-bell w-5 text-gray-400 mr-3"></i>
                            Notifications
                            <?php if ($unreadNotifications > 0): ?>
                                <span id="userDropdownNotifBadge" class="ml-auto px-2 py-0.5 bg-orange-500 text-white text-xs font-bold rounded-full">
                                    <?= $unreadNotifications ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        
                        <div class="border-t border-gray-200 my-1"></div>
                        
                        <a href="https://github.com/Hosteroid/domain-monitor" target="_blank" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                            <i class="fab fa-github w-5 text-gray-400 mr-3"></i>
                            Help & Support
                            <i class="fas fa-external-link-alt ml-auto text-xs text-gray-400"></i>
                        </a>
                        
                        <div class="border-t border-gray-200 my-1"></div>
                        
                        <a href="/logout" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                            <i class="fas fa-sign-out-alt w-5 mr-3"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Notification AJAX handler -->
<script>
function markNotifRead(notifId, btn) {
    fetch('/notifications/' + notifId + '/mark-read?ajax=1', {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(r => {
        if (!r.ok) throw new Error('Request failed');
        return r.json();
    })
    .then(data => {
        if (!data.success) return;

        const newCount = data.unread_count ?? 0;

        // Remove the notification item from dropdown
        const item = btn.closest('.notification-item');
        if (item) {
            const scrollable = document.querySelector('#notificationsDropdown .max-h-96');
            const isLast = scrollable && scrollable.querySelectorAll('.notification-item').length <= 1;

            if (isLast && scrollable) {
                scrollable.style.transition = 'opacity 0.2s';
                scrollable.style.opacity = '0';
                setTimeout(() => {
                    scrollable.innerHTML = '<div class="px-4 py-8 text-center">' +
                        '<i class="fas fa-bell-slash text-gray-300 text-3xl mb-2"></i>' +
                        '<p class="text-sm text-gray-600">No new notifications</p>' +
                        '<p class="text-xs text-gray-400 mt-0.5">You\'re all caught up!</p>' +
                        '</div>';
                    scrollable.style.opacity = '1';
                }, 200);
            } else {
                item.style.transition = 'opacity 0.2s, max-height 0.3s';
                item.style.opacity = '0';
                item.style.maxHeight = '0';
                item.style.overflow = 'hidden';
                item.style.padding = '0';
                item.style.margin = '0';
                setTimeout(() => item.remove(), 300);
            }
        }

        // Update all badges using server-returned count
        const headerBadge = document.getElementById('dropdownHeaderBadge');
        const userBadge = document.getElementById('userDropdownNotifBadge');
        const bellDot = document.querySelector('[onclick="toggleNotifications()"] .absolute.top-1');

        if (newCount <= 0) {
            if (headerBadge) headerBadge.remove();
            if (userBadge) userBadge.remove();
            if (bellDot) bellDot.remove();
        } else {
            if (headerBadge) headerBadge.textContent = newCount + ' new';
            if (userBadge) userBadge.textContent = newCount;
        }
    })
    .catch(() => {
        window.location.href = '/notifications/' + notifId + '/mark-read';
    });
}
</script>
