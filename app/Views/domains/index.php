<?php
$title = 'Domains';
$pageTitle = 'Domain Management';
$pageDescription = 'Monitor and manage your domain portfolio';
$pageIcon = 'fas fa-globe';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/domains?' . http_build_query($params);
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
$currentFilters = $filters ?? ['search' => '', 'status' => '', 'group' => '', 'sort' => 'domain_name', 'order' => 'asc'];
?>

<!-- Action Buttons -->
<div class="mb-4 flex gap-2 justify-end">
    <?php if (!empty($domains)): ?>
    <form method="POST" action="/domains/bulk-refresh" id="refresh-all-form">
        <?= csrf_field() ?>
        <?php foreach ($domains as $domain): ?>
            <input type="hidden" name="domain_ids[]" value="<?= $domain['id'] ?>">
        <?php endforeach; ?>
        <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium" title="Refresh all domains on this page">
            <i class="fas fa-sync-alt mr-2"></i>
            Refresh Page (<?= count($domains) ?>)
        </button>
    </form>
    <?php endif; ?>
    <a href="/domains/bulk-add" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors font-medium">
        <i class="fas fa-layer-group mr-2"></i>
        Bulk Add
    </a>
    <a href="/domains/create" class="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
        <i class="fas fa-plus mr-2"></i>
        Add Domain
    </a>
</div>

<!-- Filters & Search -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/domains" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
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
                    <option value="available" <?= $currentFilters['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="error" <?= $currentFilters['status'] === 'error' ? 'selected' : '' ?>>Error</option>
                    <option value="inactive" <?= $currentFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Tags</label>
                <select name="tag" id="tagFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Tags</option>
                    <?php 
                    $tagIcons = [
                        'production' => '🟢',
                        'staging' => '🟡',
                        'development' => '🔵',
                        'client' => '🟣',
                        'personal' => '🟠',
                        'archived' => '⚪'
                    ];
                    foreach ($allTags as $tagOption): 
                        $icon = $tagIcons[$tagOption] ?? '🏷️';
                        $selected = ($currentFilters['tag'] ?? '') === $tagOption ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($tagOption) ?>" <?= $selected ?>>
                            <?= $icon ?> <?= htmlspecialchars(ucfirst($tagOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Group</label>
                <select name="group" id="groupFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['id'] ?>" <?= $currentFilters['group'] == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply
                </button>
                <a href="/domains" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </div>
        </div>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
    </form>
</div>

<!-- Bulk Actions Toolbar (Hidden by default, shown when domains are selected) -->
<div id="bulk-actions" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-blue-900"></span>
            
            <button type="button" onclick="bulkRefresh()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium">
                <i class="fas fa-sync-alt mr-2"></i>
                Refresh Selected
            </button>
            
            <?php if (\Core\Auth::isAdmin()): ?>
                <button type="button" onclick="bulkTransfer()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                    <i class="fas fa-exchange-alt mr-2"></i>
                    Transfer Selected
                </button>
            <?php endif; ?>
            
            <div class="relative inline-block">
                <button type="button" onclick="toggleAssignTagsDropdown()" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors font-medium">
                    <i class="fas fa-tags mr-2"></i>
                    Manage Tags
                    <i class="fas fa-chevron-down ml-2 text-xs"></i>
                </button>
                <div id="assign-tags-dropdown" class="hidden absolute left-0 mt-2 w-72 bg-white rounded-lg shadow-lg border border-gray-200 z-10">
                    <div class="p-3">
                        <label class="block text-xs font-medium text-gray-700 mb-2">Add Tags to Selected Domains</label>
                        <div class="flex flex-wrap gap-1.5 mb-3">
                            <button type="button" onclick="bulkAddTag('production')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-green-50 text-green-700 border-green-200 hover:bg-green-100">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Production
                            </button>
                            <button type="button" onclick="bulkAddTag('staging')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-yellow-50 text-yellow-700 border-yellow-200 hover:bg-yellow-100">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Staging
                            </button>
                            <button type="button" onclick="bulkAddTag('development')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Development
                            </button>
                            <button type="button" onclick="bulkAddTag('client')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Client
                            </button>
                            <button type="button" onclick="bulkAddTag('personal')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-orange-50 text-orange-700 border-orange-200 hover:bg-orange-100">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Personal
                            </button>
                        </div>
                        <div class="border-t border-gray-200 pt-2">
                            <button type="button" onclick="bulkRemoveAllTags()" class="w-full px-3 py-1.5 bg-gray-100 text-gray-700 text-xs rounded hover:bg-gray-200 font-medium">
                                <i class="fas fa-times mr-1"></i>
                                Remove All Tags
                            </button>
                        </div>
                    </div>
                    <div class="border-t border-gray-200 p-2">
                        <button type="button" onclick="toggleAssignTagsDropdown()" class="w-full px-3 py-1.5 bg-gray-200 text-gray-700 text-xs rounded hover:bg-gray-300">
                            Close
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="relative inline-block">
                <button type="button" onclick="toggleAssignGroupDropdown()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    <i class="fas fa-bell mr-2"></i>
                    Assign Group
                    <i class="fas fa-chevron-down ml-2 text-xs"></i>
                </button>
                <div id="assign-group-dropdown" class="hidden absolute left-0 mt-2 w-64 bg-white rounded-lg shadow-lg border border-gray-200 z-10">
                    <form method="POST" action="/domains/bulk-assign-group" id="bulk-assign-form">
                        <?= csrf_field() ?>
                        <div class="p-3">
                            <select name="group_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="">-- No Group --</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="border-t border-gray-200 p-2 flex gap-2">
                            <button type="submit" class="flex-1 px-3 py-1.5 bg-primary text-white text-xs rounded hover:bg-primary-dark">
                                Assign
                            </button>
                            <button type="button" onclick="toggleAssignGroupDropdown()" class="flex-1 px-3 py-1.5 bg-gray-200 text-gray-700 text-xs rounded hover:bg-gray-300">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <button type="button" onclick="bulkDelete()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors font-medium">
                <i class="fas fa-trash mr-2"></i>
                Delete Selected
            </button>
            
            <button type="button" onclick="clearSelection()" class="inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
                <i class="fas fa-times mr-2"></i>
                Clear Selection
            </button>
        </div>
    </div>
</div>

<!-- Pagination Info & Per Page Selector -->
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?></span> domain(s)
    </div>
    
    <form method="GET" action="/domains" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($currentFilters['search']) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($currentFilters['status']) ?>">
        <input type="hidden" name="group" value="<?= htmlspecialchars($currentFilters['group']) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
            <option value="10" <?= $pagination['per_page'] == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= $pagination['per_page'] == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $pagination['per_page'] == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $pagination['per_page'] == 100 ? 'selected' : '' ?>>100</option>
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
                        <th class="px-6 py-3 text-left">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-primary focus:ring-primary">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('domain_name', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Domain <?= sortIcon('domain_name', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('registrar', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Registrar <?= sortIcon('registrar', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('expiration_date', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Expiration <?= sortIcon('expiration_date', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('status', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Status <?= sortIcon('status', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('group_name', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Group <?= sortIcon('group_name', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('last_checked', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
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
                        <tr class="hover:bg-gray-50 transition-colors duration-150 domain-row">
                            <td class="px-6 py-4">
                                <input type="checkbox" class="domain-checkbox rounded border-gray-300 text-primary focus:ring-primary" value="<?= $domain['id'] ?>" onchange="updateBulkActions()">
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-globe text-primary"></i>
                                    </div>
                                    <div class="ml-4">
                                        <a href="/domains/<?= $domain['id'] ?>" class="text-sm font-semibold text-gray-900 hover:text-primary"><?= htmlspecialchars($domain['domain_name']) ?></a>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <?php
                                            // Display tags (temporary hardcoded for UI demo - will be dynamic later)
                                            $tags = !empty($domain['tags']) ? explode(',', $domain['tags']) : [];
                                            $tagColors = [
                                                'production' => 'bg-green-100 text-green-700 border-green-200',
                                                'staging' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                                                'development' => 'bg-blue-100 text-blue-700 border-blue-200',
                                                'client' => 'bg-purple-100 text-purple-700 border-purple-200',
                                                'personal' => 'bg-orange-100 text-orange-700 border-orange-200',
                                                'archived' => 'bg-gray-100 text-gray-600 border-gray-200'
                                            ];
                                            foreach ($tags as $tag):
                                                $tag = trim($tag);
                                                $colorClass = $tagColors[$tag] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                                            ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border <?= $colorClass ?>">
                                                    <i class="fas fa-tag mr-1" style="font-size: 9px;"></i>
                                                    <?= htmlspecialchars(ucfirst($tag)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (!empty($domain['nameservers']) && empty($tags)): ?>
                                                <span class="text-xs text-gray-500">NS: <?= htmlspecialchars(explode(',', $domain['nameservers'])[0]) ?></span>
                                            <?php endif; ?>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php if (!empty($domain['group_name'])): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <i class="fas fa-bell mr-1"></i>
                                        <?= htmlspecialchars($domain['group_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">No Group</span>
                                <?php endif; ?>
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
                                    <a href="/domains/<?= $domain['id'] ?>/edit" class="text-yellow-600 hover:text-yellow-800" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="/domains/<?= $domain['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this domain?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-red-600 hover:text-red-800" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Card View (Mobile) - Simplified for brevity -->
        <div class="lg:hidden divide-y divide-gray-200">
            <?php foreach ($domains as $domain): ?>
                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                    <div class="flex items-center mb-3">
                        <input type="checkbox" class="domain-checkbox-mobile rounded border-gray-300 text-primary focus:ring-primary mr-3" value="<?= $domain['id'] ?>" onchange="updateBulkActions()">
                        <a href="/domains/<?= $domain['id'] ?>" class="text-lg font-semibold text-gray-900 hover:text-primary"><?= htmlspecialchars($domain['domain_name']) ?></a>
                    </div>
                    <!-- Add mobile view content here if needed -->
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-globe text-gray-300 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Domains Yet</h3>
            <p class="text-sm text-gray-500 mb-4">Start monitoring your domains by adding your first one</p>
            <a href="/domains/create" class="inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                <i class="fas fa-plus mr-2"></i>
                <span>Add Your First Domain</span>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination Controls -->
<?php if ($pagination['total_pages'] > 1): ?>
<div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-4">
    <!-- Page Info -->
    <div class="text-sm text-gray-600">
        Page <span class="font-semibold text-gray-900"><?= $pagination['current_page'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total_pages'] ?></span>
    </div>
    
    <!-- Pagination Buttons -->
    <div class="flex items-center gap-1">
        <?php
        $currentPage = $pagination['current_page'];
        $totalPages = $pagination['total_pages'];
        
        // Helper function to build pagination URL
        function paginationUrl($page, $filters, $perPage) {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/domains?' . http_build_query($params);
        }
        ?>
        
        <!-- First Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl(1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-left"></i>
            </a>
        <?php endif; ?>
        
        <!-- Previous Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl($currentPage - 1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
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
            echo '<a href="' . paginationUrl(1, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
            if ($start > 2) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
        }
        
        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
            }
        }
        
        // Show last page + ellipsis if needed
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
            echo '<a href="' . paginationUrl($totalPages, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $totalPages . '</a>';
        }
        ?>
        
        <!-- Next Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($currentPage + 1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Next <i class="fas fa-angle-right"></i>
            </a>
        <?php endif; ?>
        
        <!-- Last Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($totalPages, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
// Multi-select functionality
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.domain-checkbox, .domain-checkbox-mobile');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.domain-checkbox:checked, .domain-checkbox-mobile:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    // Get unique domain IDs (avoid counting both desktop and mobile checkboxes)
    const uniqueIds = new Set(Array.from(checkboxes).map(cb => cb.value));
    const count = uniqueIds.size;
    
    if (count > 0) {
        bulkActions.classList.remove('hidden');
        bulkActions.classList.add('flex');
        selectedCount.textContent = `${count} domain(s) selected`;
    } else {
        bulkActions.classList.add('hidden');
        bulkActions.classList.remove('flex');
    }
    
    // Update select all checkbox state
    // Only count desktop checkboxes to avoid double counting
    const allCheckboxes = document.querySelectorAll('.domain-checkbox');
    const checkedDesktopBoxes = document.querySelectorAll('.domain-checkbox:checked');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allCheckboxes.length > 0 && checkedDesktopBoxes.length === allCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedDesktopBoxes.length > 0 && checkedDesktopBoxes.length < allCheckboxes.length;
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.domain-checkbox, .domain-checkbox-mobile');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.domain-checkbox:checked, .domain-checkbox-mobile:checked');
    // Return unique IDs only (avoid duplicates from desktop and mobile views)
    const ids = Array.from(checkboxes).map(cb => cb.value);
    return [...new Set(ids)];
}

function bulkRefresh() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/domains/bulk-refresh';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'domain_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function bulkDelete() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    
    if (!confirm(`Delete ${ids.length} domain(s)? This action cannot be undone.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/domains/bulk-delete';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'domain_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function toggleAssignTagsDropdown() {
    const dropdown = document.getElementById('assign-tags-dropdown');
    dropdown.classList.toggle('hidden');
}

function toggleAssignGroupDropdown() {
    const dropdown = document.getElementById('assign-group-dropdown');
    dropdown.classList.toggle('hidden');
}

function bulkAddTag(tagName) {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one domain');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/domains/bulk-add-tags';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    // Add tag to add
    const tagInput = document.createElement('input');
    tagInput.type = 'hidden';
    tagInput.name = 'tag';
    tagInput.value = tagName;
    form.appendChild(tagInput);
    
    // Add domain IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'domain_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function bulkRemoveAllTags() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one domain');
        return;
    }
    
    if (!confirm(`Remove all tags from ${ids.length} domain(s)?`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/domains/bulk-remove-tags';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    // Add domain IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'domain_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// Bulk transfer domains
function bulkTransfer() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one domain');
        return;
    }
    
    // Get list of users for transfer
    const users = <?= json_encode($users ?? []) ?>;
    if (users.length === 0) {
        alert('No users available for transfer');
        return;
    }
    
    // Create user selection options
    let userOptions = users.map(user => 
        `<option value="${user.id}">${user.username} (${user.full_name || user.email})</option>`
    ).join('');
    
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
    modal.innerHTML = `
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Transfer ${ids.length} Domain(s)</h3>
                <p class="text-sm text-gray-500 mb-4">Select the user to transfer the selected domains to:</p>
                
                <form method="POST" action="/domains/bulk-transfer">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    ${ids.map(id => `<input type="hidden" name="domain_ids[]" value="${id}">`).join('')}
                    
                    <div class="mb-4">
                        <label for="target_user_id" class="block text-sm font-medium text-gray-700 mb-2">Transfer to User:</label>
                        <select name="target_user_id" id="target_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select a user...</option>
                            ${userOptions}
                        </select>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                            Transfer Domains
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// Update bulk assign form with selected IDs
document.getElementById('bulk-assign-form')?.addEventListener('submit', function(e) {
    const ids = getSelectedIds();
    const container = this;
    
    // Clear existing hidden inputs
    container.querySelectorAll('input[name="domain_ids[]"]').forEach(el => el.remove());
    
    // Add selected IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'domain_ids[]';
        input.value = id;
        container.appendChild(input);
    });
});


// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const groupDropdown = document.getElementById('assign-group-dropdown');
    const tagsDropdown = document.getElementById('assign-tags-dropdown');
    const groupButton = event.target.closest('button[onclick="toggleAssignGroupDropdown()"]');
    const tagsButton = event.target.closest('button[onclick="toggleAssignTagsDropdown()"]');
    
    if (!groupButton && !groupDropdown.contains(event.target)) {
        groupDropdown?.classList.add('hidden');
    }
    
    if (!tagsButton && !tagsDropdown.contains(event.target)) {
        tagsDropdown?.classList.add('hidden');
    }
});

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
