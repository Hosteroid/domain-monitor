<!-- Sidebar Navigation -->
<aside id="sidebar" class="sidebar fixed left-0 top-0 w-64 bg-gray-900 text-white z-30">
    <div class="h-full overflow-y-auto flex flex-col">
        
        <!-- Logo Section -->
        <div class="px-4 sm:px-5 py-4 border-b border-gray-800 flex items-center justify-between flex-shrink-0">
            <a href="/" class="flex items-center min-w-0 group">
                <img src="/assets/logo.svg" alt="Domain Monitor" class="w-9 h-9 mr-3 flex-shrink-0">
                <div class="min-w-0">
                    <h1 class="text-base font-bold text-white truncate group-hover:text-primary transition-colors">Domain Monitor</h1>
                    <p class="text-xs text-gray-500 truncate">Track your domains</p>
                </div>
            </a>
            <!-- Close button for mobile -->
            <button onclick="closeSidebar()" class="md:hidden w-9 h-9 flex items-center justify-center text-gray-400 hover:text-white hover:bg-gray-800 rounded-lg transition-colors flex-shrink-0 ml-2">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        
        <!-- Navigation Links -->
        <nav class="px-4 py-3">
            <div class="space-y-0.5">
                <a href="/" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= $_SERVER['REQUEST_URI'] === '/' ? 'bg-primary text-white' : '' ?>">
                    <i class="fas fa-chart-line text-xs mr-3 w-4"></i>
                    <span class="text-sm">Dashboard</span>
                </a>
                
                <a href="/domains" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/domains') !== false ? 'bg-primary text-white' : '' ?>">
                    <i class="fas fa-globe text-xs mr-3 w-4"></i>
                    <span class="text-sm">Domains</span>
                </a>
                
                <a href="/groups" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/groups') !== false ? 'bg-primary text-white' : '' ?>">
                    <i class="fas fa-bell text-xs mr-3 w-4"></i>
                    <span class="text-sm">Notification Groups</span>
                </a>
                
                <a href="/tld-registry" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/tld-registry') !== false ? 'bg-primary text-white' : '' ?>">
                    <i class="fas fa-database text-xs mr-3 w-4"></i>
                    <span class="text-sm">TLD Registry</span>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin'): ?>
                    <span class="ml-auto text-xs bg-gray-700 px-1.5 py-0.5 rounded text-gray-400">View</span>
                    <?php endif; ?>
                </a>
                
                <a href="/tags" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/tags') !== false ? 'bg-primary text-white' : '' ?>">
                    <i class="fas fa-tags text-xs mr-3 w-4"></i>
                    <span class="text-sm">Tag Management</span>
                </a>
            </div>

            <!-- Tools Section -->
            <div class="mt-4 pt-3 border-t border-gray-800">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-1">Tools</p>
                <div class="space-y-0.5">
                    <a href="/debug/whois" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/debug/whois') !== false ? 'bg-primary text-white' : '' ?>">
                        <i class="fas fa-search text-xs mr-3 w-4"></i>
                        <span class="text-sm">WHOIS Lookup</span>
                    </a>
                </div>
            </div>

            <!-- System Section (Admin Only) -->
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <div class="mt-4 pt-3 border-t border-gray-800">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-1">System</p>
                <div class="space-y-0.5">
                    <a href="/settings" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/settings') !== false ? 'bg-primary text-white' : '' ?>">
                        <i class="fas fa-cog text-xs mr-3 w-4"></i>
                        <span class="text-sm">Settings</span>
                    </a>
                    <a href="/users" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/users') !== false ? 'bg-primary text-white' : '' ?>">
                        <i class="fas fa-users text-xs mr-3 w-4"></i>
                        <span class="text-sm">Users</span>
                    </a>
                    <a href="/errors" class="sidebar-link flex items-center px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-white transition-colors duration-150 <?= strpos($_SERVER['REQUEST_URI'], '/errors') !== false ? 'bg-primary text-white' : '' ?>">
                        <i class="fas fa-bug text-xs mr-3 w-4"></i>
                        <span class="text-sm">Error Logs</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </nav>

        <!-- Quick Stats Cards - Pinned to Bottom -->
        <div class="mt-auto px-4 pb-3 border-t border-gray-800 pt-3">
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-2">Quick Stats</div>
            <div class="space-y-1.5">
                <div class="bg-gray-800 rounded-md p-2.5 border border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-7 h-7 bg-blue-500/20 rounded flex items-center justify-center mr-2.5">
                                <i class="fas fa-globe text-blue-400 text-xs"></i>
                            </div>
                            <span class="text-gray-400 text-xs">Total</span>
                        </div>
                        <span class="text-white font-semibold text-sm"><?= $domainStats['total'] ?? 0 ?></span>
                    </div>
                </div>
                
                <div class="bg-gray-800 rounded-md p-2.5 border border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-7 h-7 bg-orange-500/20 rounded flex items-center justify-center mr-2.5">
                                <i class="fas fa-exclamation-triangle text-orange-400 text-xs"></i>
                            </div>
                            <span class="text-gray-400 text-xs" title="Within <?= $domainStats['expiring_threshold'] ?? 30 ?> days">Expiring</span>
                        </div>
                        <span class="text-orange-400 font-semibold text-sm"><?= $domainStats['expiring_soon'] ?? 0 ?></span>
                    </div>
                </div>
                
                <div class="bg-gray-800 rounded-md p-2.5 border border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-7 h-7 bg-green-500/20 rounded flex items-center justify-center mr-2.5">
                                <i class="fas fa-check-circle text-green-400 text-xs"></i>
                            </div>
                            <span class="text-gray-400 text-xs">Active</span>
                        </div>
                        <span class="text-green-400 font-semibold text-sm"><?= $domainStats['active'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-4 py-3 border-t border-gray-800">
            <div class="text-center">
                <p class="text-xs text-gray-500">© <?= date('Y') ?> <a href="https://github.com/Hosteroid/domain-monitor" target="_blank" class="hover:text-blue-300 transition-colors duration-150" title="Visit Domain Monitor on GitHub">Domain Monitor</a></p>
                <p class="text-xs text-gray-600 mt-0.5">v<?= $appVersion ?></p>
            </div>
        </div>
    </div>
</aside>

