<?php
$title = 'Notifications';
$pageTitle = 'Notifications';
$pageDescription = 'View and manage your notifications';
$pageIcon = 'fas fa-bell';
ob_start();

// Data is passed from the controller
$filterType = $filters['type'] ?? '';
$filterStatus = $filters['status'] ?? '';
$filterDateRange = $filters['date_range'] ?? '';
$page = $pagination['current_page'];
$totalPages = $pagination['total_pages'];
$perPage = $pagination['per_page'];
$totalNotifications = $pagination['total'];
$offset = $pagination['showing_from'] - 1;
?>

<!-- Action Buttons -->
<div class="mb-4 flex flex-wrap gap-2 justify-between items-center">
    <div class="flex gap-2">
        <!-- Placeholder for future bulk selection actions -->
    </div>
    
    <div class="flex gap-2">
        <button onclick="markAllAsRead()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium">
            <i class="fas fa-check-double mr-2"></i>
            Mark All Read
        </button>
        <button onclick="clearAll()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors font-medium">
            <i class="fas fa-trash-alt mr-2"></i>
            Clear All
        </button>
    </div>
</div>

<!-- Filters & Search -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/notifications" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <!-- Status Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">All Notifications</option>
                    <option value="unread" <?= $filterStatus === 'unread' ? 'selected' : '' ?>>Unread Only</option>
                    <option value="read" <?= $filterStatus === 'read' ? 'selected' : '' ?>>Read Only</option>
                </select>
            </div>
            
            <!-- Type Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Type</label>
                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">All Types</option>
                    <optgroup label="Domain">
                        <option value="domain_expiring" <?= $filterType === 'domain_expiring' ? 'selected' : '' ?>>Domain Expiring</option>
                        <option value="domain_expired" <?= $filterType === 'domain_expired' ? 'selected' : '' ?>>Domain Expired</option>
                        <option value="domain_updated" <?= $filterType === 'domain_updated' ? 'selected' : '' ?>>Domain Updated</option>
                        <option value="whois_failed" <?= $filterType === 'whois_failed' ? 'selected' : '' ?>>WHOIS Failed</option>
                    </optgroup>
                    <optgroup label="System">
                        <option value="session_new" <?= $filterType === 'session_new' ? 'selected' : '' ?>>New Login</option>
                        <option value="system_welcome" <?= $filterType === 'system_welcome' ? 'selected' : '' ?>>Welcome</option>
                        <option value="system_upgrade" <?= $filterType === 'system_upgrade' ? 'selected' : '' ?>>System Upgrade</option>
                    </optgroup>
                </select>
            </div>
            
            <!-- Date Range -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Date Range</label>
                <select name="date_range" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">All Time</option>
                    <option value="today" <?= $filterDateRange === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $filterDateRange === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $filterDateRange === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>
            </div>
            
            <!-- Apply/Reset Buttons -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply Filters
                </button>
                <a href="/notifications" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times mr-2"></i>
                    Clear
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Pagination Info & Per Page Selector -->
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $offset + 1 ?></span> to 
        <span class="font-semibold text-gray-900"><?= min($offset + $perPage, $totalNotifications) ?></span> of 
        <span class="font-semibold text-gray-900"><?= $totalNotifications ?></span> notification(s)
        <?php if ($unreadCount > 0): ?>
            <span class="text-gray-400">•</span>
            <span class="font-semibold text-blue-600"><?= $unreadCount ?></span> unread
        <?php endif; ?>
    </div>
    
    <form method="GET" action="/notifications" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
            <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= $perPage == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100</option>
        </select>
    </form>
</div>

<!-- Notifications List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <?php if (!empty($notifications)): ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $bgClass = $notification['is_read'] ? '' : 'bg-blue-50';
                $iconBgClass = "bg-{$notification['color']}-100";
                $iconTextClass = "text-{$notification['color']}-600";
                ?>
                <div class="px-4 py-3 hover:bg-gray-50 transition-colors <?= $bgClass ?>">
                    <div class="flex items-center gap-3">
                        <!-- Icon -->
                        <div class="w-8 h-8 <?= $iconBgClass ?> rounded-lg flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-<?= $notification['icon'] ?> <?= $iconTextClass ?> text-xs"></i>
                        </div>
                        
                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-medium text-gray-900"><?= htmlspecialchars($notification['title']) ?></h3>
                                <?php if (!$notification['is_read']): ?>
                                    <span class="flex h-1.5 w-1.5">
                                        <span class="animate-ping absolute inline-flex h-1.5 w-1.5 rounded-full bg-blue-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-blue-500"></span>
                                    </span>
                                <?php endif; ?>
                                <span class="text-xs text-gray-400 ml-auto">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?= $notification['time_ago'] ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-600 mt-0.5"><?= htmlspecialchars($notification['message']) ?></p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1 ml-2">
                            <?php if (!$notification['is_read']): ?>
                                <a href="/notifications/<?= $notification['id'] ?>/mark-read" class="w-7 h-7 flex items-center justify-center text-gray-400 hover:text-green-600 hover:bg-green-50 rounded transition-colors" title="Mark as read">
                                    <i class="fas fa-check text-xs"></i>
                                </a>
                            <?php endif; ?>
                            <a href="/notifications/<?= $notification['id'] ?>/delete" onclick="return confirm('Delete this notification?')" class="w-7 h-7 flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors" title="Delete">
                                <i class="fas fa-times text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <!-- Empty State -->
        <div class="p-12 text-center">
            <i class="fas fa-bell-slash text-gray-300 text-4xl mb-3"></i>
            <p class="text-sm text-gray-600">No notifications found</p>
            <p class="text-xs text-gray-400 mt-1">Try adjusting your filters</p>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination Controls -->
<?php if ($totalPages > 1): ?>
<div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-4">
    <!-- Page Info -->
    <div class="text-sm text-gray-600">
        Page <span class="font-semibold text-gray-900"><?= $page ?></span> of 
        <span class="font-semibold text-gray-900"><?= $totalPages ?></span>
    </div>
    
    <!-- Pagination Buttons -->
    <div class="flex items-center gap-1">
        <?php
        // Helper function to build pagination URL
        function paginationUrl($page, $status, $type) {
            $params = $_GET;
            $params['page'] = $page;
            if ($status) $params['status'] = $status;
            if ($type) $params['type'] = $type;
            return '/notifications?' . http_build_query($params);
        }
        ?>
        
        <!-- First Page -->
        <?php if ($page > 1): ?>
            <a href="<?= paginationUrl(1, $filterStatus, $filterType) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-left"></i>
            </a>
        <?php endif; ?>
        
        <!-- Previous Page -->
        <?php if ($page > 1): ?>
            <a href="<?= paginationUrl($page - 1, $filterStatus, $filterType) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-left"></i> Previous
            </a>
        <?php endif; ?>
        
        <!-- Page Numbers -->
        <?php
        $range = 2; // Show 2 pages on each side of current page
        $start = max(1, $page - $range);
        $end = min($totalPages, $page + $range);
        
        // Show first page + ellipsis if needed
        if ($start > 1) {
            echo '<a href="' . paginationUrl(1, $filterStatus, $filterType) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
            if ($start > 2) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
        }
        
        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $page) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $filterStatus, $filterType) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
            }
        }
        
        // Show last page + ellipsis if needed
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
            echo '<a href="' . paginationUrl($totalPages, $filterStatus, $filterType) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $totalPages . '</a>';
        }
        ?>
        
        <!-- Next Page -->
        <?php if ($page < $totalPages): ?>
            <a href="<?= paginationUrl($page + 1, $filterStatus, $filterType) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Next <i class="fas fa-angle-right"></i>
            </a>
        <?php endif; ?>
        
        <!-- Last Page -->
        <?php if ($page < $totalPages): ?>
            <a href="<?= paginationUrl($totalPages, $filterStatus, $filterType) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function markAllAsRead() {
    if (confirm('Mark all notifications as read?')) {
        window.location.href = '/notifications/mark-all-read';
    }
}

function clearAll() {
    if (confirm('Clear all notifications? This action cannot be undone.')) {
        window.location.href = '/notifications/clear-all';
    }
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>

