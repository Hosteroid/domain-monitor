<?php
$title = 'Tag: ' . htmlspecialchars($tag['name']);
$pageTitle = 'Tag: ' . htmlspecialchars($tag['name']);
$pageDescription = 'View all domains that have this tag assigned';
$pageIcon = 'fas fa-tag';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder, $tagId) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/tags/' . $tagId . '?' . http_build_query($params);
}

// Helper function for sort icon
function sortIcon($column, $currentSort, $currentOrder) {
    if ($currentSort !== $column) {
        return '<i class="fas fa-sort text-gray-400 ml-1 text-xs"></i>';
    }
    $icon = $currentOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
    return '<i class="fas ' . $icon . ' text-primary ml-1 text-xs"></i>';
}

// Get current filters
$currentFilters = $filters ?? ['search' => '', 'status' => '', 'registrar' => '', 'sort' => 'domain_name', 'order' => 'asc'];
?>

<!-- Back Navigation -->
<div class="mb-4">
    <a href="/tags" class="inline-flex items-center px-3 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
        <i class="fas fa-arrow-left mr-2"></i>
        Back to Tags
    </a>
</div>

<!-- Tag Info Card -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
    <div class="flex items-start">
        <div class="flex-shrink-0">
            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-sm font-medium border <?= htmlspecialchars($tag['color']) ?>">
                <i class="fas fa-tag mr-1"></i>
                <?= htmlspecialchars($tag['name']) ?>
            </span>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-semibold text-gray-900 mb-1">Tag Description</h3>
            <p class="text-xs text-gray-600 leading-relaxed">
                <?php if (!empty($tag['description'])): ?>
                    <?= htmlspecialchars($tag['description']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<!-- Filters & Search -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/tags/<?= $tag['id'] ?>" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Search</label>
                <div class="relative">
                    <input type="text" name="search" id="domainSearch" value="<?= htmlspecialchars($currentFilters['search']) ?>" placeholder="Search domains..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                <select name="status" id="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $currentFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="expiring_soon" <?= $currentFilters['status'] === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon</option>
                    <option value="expired" <?= $currentFilters['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="available" <?= $currentFilters['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="redemption_period" <?= $currentFilters['status'] === 'redemption_period' ? 'selected' : '' ?>>Redemption Period</option>
                    <option value="pending_delete" <?= $currentFilters['status'] === 'pending_delete' ? 'selected' : '' ?>>Pending Delete</option>
                    <option value="error" <?= $currentFilters['status'] === 'error' ? 'selected' : '' ?>>Error</option>
                    <option value="inactive" <?= $currentFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Registrar</label>
                <select name="registrar" id="registrarFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Registrars</option>
                    <?php 
                    $registrars = array_unique(array_column($domains, 'registrar'));
                    $registrars = array_filter($registrars);
                    foreach ($registrars as $registrar): 
                    ?>
                        <option value="<?= htmlspecialchars($registrar) ?>" <?= ($currentFilters['registrar'] ?? '') === $registrar ? 'selected' : '' ?>><?= htmlspecialchars($registrar) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply
                </button>
                <a href="/tags/<?= $tag['id'] ?>" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </div>
        </div>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
    </form>
</div>

<!-- Pagination Info & Per Page Selector -->
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?? 1 ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?? count($domains) ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?? count($domains) ?></span> domain(s)
    </div>
    
    <form method="GET" action="/tags/<?= $tag['id'] ?>" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($currentFilters['search']) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($currentFilters['status']) ?>">
        <input type="hidden" name="registrar" value="<?= htmlspecialchars($currentFilters['registrar']) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
            <option value="10" <?= ($pagination['per_page'] ?? 25) == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= ($pagination['per_page'] ?? 25) == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= ($pagination['per_page'] ?? 25) == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= ($pagination['per_page'] ?? 25) == 100 ? 'selected' : '' ?>>100</option>
        </select>
    </form>
</div>

<!-- Domains List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <?php if (!empty($domains)): ?>
        <!-- Table View (Desktop) -->
        <div class="hidden lg:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('domain_name', $currentFilters['sort'], $currentFilters['order'], $tag['id']) ?>" class="hover:text-primary flex items-center">
                                Domain <?= sortIcon('domain_name', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('registrar', $currentFilters['sort'], $currentFilters['order'], $tag['id']) ?>" class="hover:text-primary flex items-center">
                                Registrar <?= sortIcon('registrar', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('expiration_date', $currentFilters['sort'], $currentFilters['order'], $tag['id']) ?>" class="hover:text-primary flex items-center">
                                Expiration <?= sortIcon('expiration_date', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('status', $currentFilters['sort'], $currentFilters['order'], $tag['id']) ?>" class="hover:text-primary flex items-center">
                                Status <?= sortIcon('status', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('last_checked', $currentFilters['sort'], $currentFilters['order'], $tag['id']) ?>" class="hover:text-primary flex items-center">
                                Last Checked <?= sortIcon('last_checked', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($domains as $domain): ?>
                        <?php
                        // Display data prepared by DomainHelper in controller
                        $daysLeft = $domain['daysLeft'];
                        $expiryClass = $domain['expiryClass'];
                        $statusClass = $domain['statusClass'];
                        $statusText = $domain['statusText'];
                        $statusIcon = $domain['statusIcon'];
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-globe text-primary"></i>
                                    </div>
                                    <div class="ml-4">
                                        <a href="/domains/<?= $domain['id'] ?>" class="text-sm font-semibold text-gray-900 hover:text-primary"><?= htmlspecialchars($domain['domain_name']) ?></a>
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
                                        <div class="font-medium text-gray-900 flex items-center">
                                            <?= $domain['expiration_date'] ? date('M d, Y', strtotime($domain['expiration_date'])) : 'Unknown' ?>
                                        </div>
                                        <div class="text-xs <?= $expiryClass ?>">
                                            <?= $daysLeft ?> days
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border <?= $statusClass ?>">
                                    <i class="fas <?= $statusIcon ?> mr-1"></i>
                                    <?= $statusText ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if (!empty($domain['last_checked'])): ?>
                                    <div class="flex items-center">
                                        <i class="far fa-clock mr-2"></i>
                                        <?= date('M d, H:i', strtotime($domain['last_checked'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="/domains/<?= $domain['id'] ?>" class="text-blue-600 hover:text-blue-800" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" action="/domains/<?= $domain['id'] ?>/refresh" class="inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-green-600 hover:text-green-800" title="Refresh WHOIS">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </form>
                                    <a href="/domains/<?= $domain['id'] ?>/edit?from=/tags/<?= $tag['id'] ?>" class="text-yellow-600 hover:text-yellow-800" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Card View (Mobile) -->
        <div class="lg:hidden divide-y divide-gray-200">
            <?php foreach ($domains as $domain): ?>
                <?php
                // Display data prepared by DomainHelper in controller
                $daysLeft = $domain['daysLeft'];
                $expiryClass = $domain['expiryClass'];
                $statusClass = $domain['statusClass'];
                $statusText = $domain['statusText'];
                $statusIcon = $domain['statusIcon'];
                ?>
                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                    <div class="flex items-center justify-between mb-3">
                        <a href="/domains/<?= $domain['id'] ?>" class="text-lg font-semibold text-gray-900 hover:text-primary"><?= htmlspecialchars($domain['domain_name']) ?></a>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border <?= $statusClass ?>">
                            <i class="fas <?= $statusIcon ?> mr-1"></i>
                            <?= $statusText ?>
                        </span>
                    </div>
                    
                    <div class="space-y-2 text-sm">
                        <?php if (!empty($domain['registrar'])): ?>
                        <div class="flex items-center">
                            <i class="fas fa-building text-gray-400 mr-2 w-4"></i>
                            <span><?= htmlspecialchars($domain['registrar']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($domain['expiration_date'])): ?>
                        <div class="flex items-center">
                            <i class="fas fa-calendar-alt text-gray-400 mr-2 w-4"></i>
                            <span>Expires: <?= $domain['expiration_date'] ? date('M d, Y', strtotime($domain['expiration_date'])) : 'Unknown' ?> (<?= $daysLeft ?> days)</span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-center">
                            <i class="far fa-clock text-gray-400 mr-2 w-4"></i>
                            <span><?= $domain['last_checked'] ? date('M d, H:i', strtotime($domain['last_checked'])) : 'Never checked' ?></span>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2 mt-3">
                        <a href="/domains/<?= $domain['id'] ?>" class="flex-1 px-3 py-1.5 bg-blue-50 text-blue-600 rounded text-center text-sm hover:bg-blue-100 transition-colors">
                            <i class="fas fa-eye mr-1"></i> View
                        </a>
                        <form method="POST" action="/domains/<?= $domain['id'] ?>/refresh" class="flex-1">
                            <?= csrf_field() ?>
                            <button type="submit" class="w-full px-3 py-1.5 bg-green-50 text-green-600 rounded text-center text-sm hover:bg-green-100 transition-colors">
                                <i class="fas fa-sync-alt mr-1"></i> Refresh
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-globe text-gray-300 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Domains Found</h3>
            <p class="text-sm text-gray-500 mb-4">This tag is not currently assigned to any domains</p>
            <a href="/domains" class="inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                <i class="fas fa-plus mr-2"></i>
                <span>Add Domains</span>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination Controls -->
<?php if (($pagination['total_pages'] ?? 1) > 1): ?>
<div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-4">
    <!-- Page Info -->
    <div class="text-sm text-gray-600">
        Page <span class="font-semibold text-gray-900"><?= $pagination['current_page'] ?? 1 ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total_pages'] ?? 1 ?></span>
    </div>
    
    <!-- Pagination Buttons -->
    <div class="flex items-center gap-1">
        <?php
        $currentPage = $pagination['current_page'] ?? 1;
        $totalPages = $pagination['total_pages'] ?? 1;
        
        // Helper function to build pagination URL
        function paginationUrl($page, $filters, $perPage, $tagId) {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/tags/' . $tagId . '?' . http_build_query($params);
        }
        ?>
        
        <!-- First Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl(1, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-left"></i>
            </a>
        <?php endif; ?>
        
        <!-- Previous Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl($currentPage - 1, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-left"></i> Previous
            </a>
        <?php endif; ?>
        
        <!-- Page Numbers -->
        <?php
        $range = 2; // Show 2 pages on each side of current page
        $start = max(1, $currentPage - $range);
        $end = min($totalPages, $currentPage + $range);
        
        // Show first page + ellipsis if needed
        if ($start > 1) {
            echo '<a href="' . paginationUrl(1, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
            if ($start > 2) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
        }
        
        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
            }
        }
        
        // Show last page + ellipsis if needed
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
            echo '<a href="' . paginationUrl($totalPages, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $totalPages . '</a>';
        }
        ?>
        
        <!-- Next Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($currentPage + 1, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Next <i class="fas fa-angle-right"></i>
            </a>
        <?php endif; ?>
        
        <!-- Last Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($totalPages, $currentFilters ?? [], $pagination['per_page'] ?? 25, $tag['id']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
