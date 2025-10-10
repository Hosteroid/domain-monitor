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
            <div class="flex-1 max-w-2xl mx-8">
                <form action="/search" method="GET" class="relative hidden md:block" id="globalSearchForm">
                    <input type="text" 
                           name="q"
                           placeholder="Search domains or lookup WHOIS..." 
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                           id="globalSearchInput"
                           autocomplete="off">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    
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
            <div class="flex items-center space-x-2">
                <!-- Quick Add Domain -->
                <a href="/domains/create" title="Add Domain" class="flex items-center justify-center w-9 h-9 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors duration-150">
                    <i class="fas fa-plus"></i>
                </a>
                
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
                    <div id="notificationsDropdown" class="dropdown-menu absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border border-gray-200 max-h-[32rem] overflow-hidden">
                        <!-- Header -->
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs font-semibold rounded"><?= $unreadNotifications ?> new</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Notifications List (Scrollable) -->
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (!empty($recentNotifications)): ?>
                                <?php foreach ($recentNotifications as $notif): ?>
                                    <div class="px-4 py-3 hover:bg-gray-50 border-b border-gray-100 bg-blue-50 transition-colors cursor-pointer">
                                        <div class="flex items-start space-x-3">
                                            <div class="w-8 h-8 bg-<?= $notif['color'] ?>-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <i class="fas fa-<?= $notif['icon'] ?> text-<?= $notif['color'] ?>-600 text-sm"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($notif['title']) ?></p>
                                                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                                                </div>
                                                <p class="text-xs text-gray-600 mt-0.5"><?= htmlspecialchars($notif['message']) ?></p>
                                                <p class="text-xs text-gray-400 mt-1"><?= $notif['time_ago'] ?></p>
                                            </div>
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
                        <div class="w-9 h-9 rounded-full bg-primary flex items-center justify-center text-white font-semibold">
                            <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                        </div>
                        <div class="hidden lg:block text-left">
                            <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400 text-xs hidden md:block"></i>
                    </button>

                    <!-- Dropdown Menu -->
                    <div id="userDropdown" class="dropdown-menu absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg py-2 border border-gray-200">
                        <div class="px-4 py-3 border-b border-gray-200">
                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></p>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($_SESSION['email'] ?? 'user@example.com') ?></p>
                            <div class="flex items-center gap-2 mt-2">
                                <span class="inline-flex items-center px-2.5 py-1 bg-<?= $_SESSION['role'] === 'admin' ? 'amber' : 'blue' ?>-100 text-<?= $_SESSION['role'] === 'admin' ? 'amber' : 'blue' ?>-800 text-xs font-semibold rounded border border-<?= $_SESSION['role'] === 'admin' ? 'amber' : 'blue' ?>-200">
                                    <i class="fas fa-<?= $_SESSION['role'] === 'admin' ? 'crown' : 'user' ?> mr-1.5"></i>
                                    <?= ucfirst($_SESSION['role'] ?? 'user') ?>
                                </span>
                                <span class="inline-flex items-center px-2.5 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded border border-green-200">
                                    <i class="fas fa-circle text-xs mr-1"></i>
                                    Online
                                </span>
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
                                <span class="ml-auto px-2 py-0.5 bg-orange-500 text-white text-xs font-bold rounded-full">
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
