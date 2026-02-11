<?php
$title = 'Tag Management';
$pageTitle = 'Tag Management';
$pageDescription = 'Manage your domain tags, colors, and organization';
$pageIcon = 'fas fa-tags';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/tags?' . http_build_query($params);
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
$currentFilters = $filters ?? ['search' => '', 'color' => '', 'type' => '', 'sort' => 'name', 'order' => 'asc'];
?>

<!-- Action Buttons -->
<div class="mb-4 flex gap-2 justify-end">
    <!-- Export Dropdown -->
    <div class="relative" id="exportDropdownWrapper">
        <button onclick="document.getElementById('exportDropdownMenu').classList.toggle('hidden')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition-colors font-medium">
            <i class="fas fa-download mr-2"></i>
            Export
            <i class="fas fa-chevron-down ml-2 text-xs"></i>
        </button>
        <div id="exportDropdownMenu" class="hidden absolute right-0 mt-1 w-44 bg-white rounded-lg shadow-lg border border-gray-200 z-30 overflow-hidden">
            <a href="/tags/export?format=csv" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <i class="fas fa-file-csv text-green-600 mr-2.5"></i>
                Export as CSV
            </a>
            <a href="/tags/export?format=json" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors border-t border-gray-100">
                <i class="fas fa-file-code text-blue-600 mr-2.5"></i>
                Export as JSON
            </a>
        </div>
    </div>
    <!-- Import Button -->
    <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
        <i class="fas fa-upload mr-2"></i>
        Import
    </button>
    <button onclick="openCreateModal()" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
        <i class="fas fa-plus mr-2"></i>
        Create New Tag
    </button>
</div>

<!-- Filters & Search -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/tags" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Search</label>
                <div class="relative">
                    <input type="text" name="search" id="tagSearch" value="<?= htmlspecialchars($currentFilters['search'] ?? '') ?>" placeholder="Search tags..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Color</label>
                <select name="color" id="colorFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Colors</option>
                    <?php foreach ($availableColors as $colorValue => $colorName): ?>
                        <option value="<?= htmlspecialchars($colorValue) ?>" <?= ($currentFilters['color'] ?? '') === $colorValue ? 'selected' : '' ?>><?= htmlspecialchars($colorName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Type</label>
                <select name="type" id="typeFilter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Types</option>
                    <option value="global" <?= ($currentFilters['type'] ?? '') === 'global' ? 'selected' : '' ?>>Global Tags</option>
                    <option value="user" <?= ($currentFilters['type'] ?? '') === 'user' ? 'selected' : '' ?>>My Tags</option>
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply
                </button>
                <a href="/tags" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </div>
        </div>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort'] ?? 'name') ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order'] ?? 'asc') ?>">
    </form>
</div>

<!-- Pagination Info & Per Page Selector -->
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?? 1 ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?? count($tags) ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?? count($tags) ?></span> tag(s)
    </div>
    
    <form method="GET" action="/tags" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($currentFilters['search'] ?? '') ?>">
        <input type="hidden" name="color" value="<?= htmlspecialchars($currentFilters['color'] ?? '') ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($currentFilters['type'] ?? '') ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort'] ?? 'name') ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order'] ?? 'asc') ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
            <option value="10" <?= ($pagination['per_page'] ?? 25) == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= ($pagination['per_page'] ?? 25) == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= ($pagination['per_page'] ?? 25) == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= ($pagination['per_page'] ?? 25) == 100 ? 'selected' : '' ?>>100</option>
        </select>
    </form>
</div>

<!-- Tags List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <!-- Bulk Actions Bar (shown when tags are selected) -->
    <div id="bulk-actions" class="hidden px-6 py-3 bg-blue-50 border-b border-blue-200 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-gray-700"></span>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <?php if (\Core\Auth::isAdmin()): ?>
                    <button type="button" onclick="bulkTransferTags()" class="inline-flex items-center px-4 py-1.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                        <i class="fas fa-exchange-alt mr-1"></i> Transfer Selected
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="bulkDeleteTags()" class="inline-flex items-center px-4 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                        <i class="fas fa-trash mr-1"></i> Delete Selected
                    </button>
                </div>
            </div>
        </div>
        <button type="button" onclick="clearSelection()" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fas fa-times mr-1.5"></i> Clear Selection
        </button>
    </div>
    <?php if (!empty($tags)): ?>
        <!-- Table View (Desktop) -->
        <div class="hidden lg:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-primary focus:ring-primary">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('name', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>" class="hover:text-primary flex items-center">
                                Tag <?= sortIcon('name', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('description', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>" class="hover:text-primary flex items-center">
                                Description <?= sortIcon('description', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('usage_count', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>" class="hover:text-primary flex items-center">
                                Usage <?= sortIcon('usage_count', $currentFilters['sort'] ?? 'name', $currentFilters['order'] ?? 'asc') ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($tags as $tag): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150 tag-row">
                            <td class="px-6 py-4">
                                <input type="checkbox" class="tag-checkbox rounded border-gray-300 text-primary focus:ring-primary" value="<?= $tag['id'] ?>" onchange="updateBulkActions()">
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border <?= htmlspecialchars($tag['color']) ?>">
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
                                    <?= $tag['usage_count'] ?> domain<?= $tag['usage_count'] !== 1 ? 's' : '' ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="/tags/<?= $tag['id'] ?>" class="text-blue-600 hover:text-blue-800" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (\Core\Auth::isAdmin()): ?>
                                        <button type="button" class="tag-transfer-btn text-green-600 hover:text-green-800" title="Transfer Tag"
                                                data-tag-id="<?= (int)$tag['id'] ?>" data-tag-name="<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($tag['user_id'] === null && !\Core\Auth::isAdmin()): ?>
                                        <!-- Global tag - only admins can edit/delete -->
                                        <span class="text-xs text-gray-500 italic">Global tag</span>
                                    <?php else: ?>
                                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($tag)) ?>)" 
                                                class="text-yellow-600 hover:text-yellow-800" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deleteTag(<?= $tag['id'] ?>, <?= htmlspecialchars(json_encode($tag['name'])) ?>)" 
                                                class="text-red-600 hover:text-red-800" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Card View (Mobile) -->
        <div class="lg:hidden divide-y divide-gray-200">
            <?php foreach ($tags as $tag): ?>
                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                    <div class="flex items-center mb-3">
                        <input type="checkbox" class="tag-checkbox-mobile rounded border-gray-300 text-primary focus:ring-primary mr-3" value="<?= $tag['id'] ?>" onchange="updateBulkActions()">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border <?= htmlspecialchars($tag['color']) ?>">
                                    <i class="fas fa-tag mr-1" style="font-size: 9px;"></i>
                                    <?= htmlspecialchars($tag['name']) ?>
                                </span>
                                <?php if ($tag['user_id'] === null): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <i class="fas fa-globe mr-1" style="font-size: 8px;"></i>
                                        Global
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($tag['description'])): ?>
                                <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($tag['description']) ?></p>
                            <?php endif; ?>
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-link mr-1"></i>
                                Used on <?= $tag['usage_count'] ?> domain<?= $tag['usage_count'] !== 1 ? 's' : '' ?>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <a href="/tags/<?= $tag['id'] ?>" 
                               class="text-blue-600 hover:text-blue-800" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (\Core\Auth::isAdmin()): ?>
                                <button type="button" class="tag-transfer-btn text-green-600 hover:text-green-800" title="Transfer Tag"
                                        data-tag-id="<?= (int)$tag['id'] ?>" data-tag-name="<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            <?php endif; ?>
                            <?php if ($tag['user_id'] === null && !\Core\Auth::isAdmin()): ?>
                                <!-- Global tag - only admins can edit/delete -->
                                <span class="text-xs text-gray-500 italic">Global tag</span>
                            <?php else: ?>
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($tag)) ?>)" 
                                        class="text-yellow-600 hover:text-yellow-800" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteTag(<?= $tag['id'] ?>, <?= htmlspecialchars(json_encode($tag['name'])) ?>)" 
                                        class="text-red-600 hover:text-red-800" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-tags text-gray-300 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Tags Yet</h3>
            <p class="text-sm text-gray-500 mb-4">Start organizing your domains by creating your first tag</p>
            <button onclick="openCreateModal()" class="inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                <i class="fas fa-plus mr-2"></i>
                <span>Create Your First Tag</span>
            </button>
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
        function paginationUrl($page, $filters, $perPage) {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/tags?' . http_build_query($params);
        }
        ?>
        
        <!-- First Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl(1, $currentFilters ?? [], $pagination['per_page'] ?? 25) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-left"></i>
            </a>
        <?php endif; ?>
        
        <!-- Previous Page -->
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl($currentPage - 1, $currentFilters ?? [], $pagination['per_page'] ?? 25) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
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
            echo '<a href="' . paginationUrl(1, $currentFilters ?? [], $pagination['per_page'] ?? 25) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
            if ($start > 2) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
        }
        
        // Page numbers
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $currentFilters ?? [], $pagination['per_page'] ?? 25) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
            }
        }
        
        // Show last page + ellipsis if needed
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
            echo '<a href="' . paginationUrl($totalPages, $currentFilters ?? [], $pagination['per_page'] ?? 25) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $totalPages . '</a>';
        }
        ?>
        
        <!-- Next Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($currentPage + 1, $currentFilters ?? [], $pagination['per_page'] ?? 25) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Next <i class="fas fa-angle-right"></i>
            </a>
        <?php endif; ?>
        
        <!-- Last Page -->
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($totalPages, $currentFilters ?? [], $pagination['per_page'] ?? 25) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Create Tag Modal -->
<div id="createModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <form method="POST" action="/tags/create">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Create New Tag</h3>
                </div>
                
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label for="create_name" class="block text-sm font-medium text-gray-700 mb-1">Tag Name</label>
                        <input type="text" id="create_name" name="name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="e.g., production, staging">
                        <p class="text-xs text-gray-500 mt-1">Use only letters, numbers, and hyphens</p>
                    </div>
                    
                    <div>
                        <label for="create_color" class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                        <select id="create_color" name="color" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($availableColors as $colorValue => $colorName): ?>
                                <option value="<?= htmlspecialchars($colorValue) ?>"><?= htmlspecialchars($colorName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="create_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                        <textarea id="create_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Describe what this tag is used for"></textarea>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
                    <button type="button" onclick="closeCreateModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                        Create Tag
                    </button>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            </form>
        </div>
    </div>
</div>

<!-- Edit Tag Modal -->
<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <form method="POST" action="/tags/update">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Edit Tag</h3>
                </div>
                
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700 mb-1">Tag Name</label>
                        <input type="text" id="edit_name" name="name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label for="edit_color" class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                        <select id="edit_color" name="color" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($availableColors as $colorValue => $colorName): ?>
                                <option value="<?= htmlspecialchars($colorValue) ?>"><?= htmlspecialchars($colorName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                        <textarea id="edit_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                        Update Tag
                    </button>
                </div>
                
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-upload text-primary mr-2"></i>Import Tags
            </h3>
            <button onclick="document.getElementById('importModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="/tags/import" enctype="multipart/form-data" id="tagImportForm">
            <?= csrf_field() ?>
            <div class="p-6 space-y-4">
                <!-- Drag & Drop Zone -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Select File</label>
                    <div id="tagDropzone" class="relative border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer transition-all hover:border-primary hover:bg-gray-50">
                        <input type="file" name="import_file" accept=".csv,.json" required id="tagFileInput"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                        <div id="tagDropzoneContent">
                            <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600 font-medium">Drag & drop your file here</p>
                            <p class="text-xs text-gray-400 my-1">or</p>
                            <span class="inline-flex items-center px-3 py-1.5 bg-primary text-white text-xs rounded-lg font-medium">
                                <i class="fas fa-folder-open mr-1.5"></i>Browse Files
                            </span>
                            <p class="mt-2.5 text-xs text-gray-400">CSV, JSON &middot; Max <?= \App\Helpers\ViewHelper::getMaxUploadSize() ?></p>
                        </div>
                        <div id="tagDropzoneFile" class="hidden">
                            <i class="fas fa-file-alt text-2xl text-primary mb-1.5"></i>
                            <p class="text-sm font-medium text-gray-700" id="tagFileName"></p>
                            <p class="text-xs text-gray-400" id="tagFileSize"></p>
                            <button type="button" id="tagFileRemove" class="mt-1.5 text-xs text-red-500 hover:text-red-700 font-medium">
                                <i class="fas fa-trash-alt mr-1"></i>Remove
                            </button>
                        </div>
                    </div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p class="text-xs text-gray-700 font-medium mb-1"><i class="fas fa-info-circle text-blue-500 mr-1"></i> Expected Format</p>
                    <p class="text-xs text-gray-600">CSV columns: <code class="bg-white px-1 rounded">name, color, description</code></p>
                    <p class="text-xs text-gray-600 mt-0.5">JSON: array of objects with same fields</p>
                    <p class="text-xs text-gray-500 mt-1">Tags that already exist will be skipped. Only your private tags are imported.</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="tagImportBtn" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-primary-dark transition-colors">
                    <i class="fas fa-upload mr-1.5"></i>Import Tags
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Multi-select functionality
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.tag-checkbox, .tag-checkbox-mobile');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.tag-checkbox:checked, .tag-checkbox-mobile:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    // Get unique tag IDs (avoid counting both desktop and mobile checkboxes)
    const uniqueIds = new Set(Array.from(checkboxes).map(cb => cb.value));
    const count = uniqueIds.size;
    
    if (count > 0) {
        bulkActions.classList.remove('hidden');
        selectedCount.textContent = count + ' tag(s) selected';
    } else {
        bulkActions.classList.add('hidden');
    }
    
    // Update select all checkbox state
    // Only count desktop checkboxes to avoid double counting
    const allCheckboxes = document.querySelectorAll('.tag-checkbox');
    const checkedDesktopBoxes = document.querySelectorAll('.tag-checkbox:checked');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allCheckboxes.length > 0 && checkedDesktopBoxes.length === allCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedDesktopBoxes.length > 0 && checkedDesktopBoxes.length < allCheckboxes.length;
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.tag-checkbox, .tag-checkbox-mobile');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.tag-checkbox:checked, .tag-checkbox-mobile:checked');
    // Return unique IDs only (avoid duplicates from desktop and mobile views)
    const ids = Array.from(checkboxes).map(cb => cb.value);
    return [...new Set(ids)];
}

function bulkDeleteTags() {
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    
    if (!confirm(`Delete ${ids.length} tag(s)? This will remove them from all domains.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/tags/bulk-delete';
    
    // Add CSRF token
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'tag_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function transferTag(tagId, tagName) {
    const users = <?= json_encode($users ?? []) ?>;
    if (users.length === 0) {
        alert('No users available for transfer');
        return;
    }
    const escapeHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const userOptions = users.map(user =>
        `<option value="${user.id}">${escapeHtml(user.username)} (${escapeHtml(user.full_name || 'No name')})</option>`
    ).join('');
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
    modal.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Transfer Tag</h3>
            <p class="text-sm text-gray-600 mb-4">Transfer tag "${escapeHtml(tagName)}" to another user.</p>
            
            <form method="POST" action="/tags/transfer">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="tag_id" value="${tagId}">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Transfer to User</label>
                    <select name="target_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                        <option value="">Select User</option>
                        ${userOptions}
                    </select>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="this.closest('.fixed').remove()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark text-sm font-medium">
                        Transfer
                    </button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

// Delegate click for table/card Transfer buttons (avoids onclick quote issues)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.tag-transfer-btn');
    if (btn) {
        e.preventDefault();
        transferTag(parseInt(btn.dataset.tagId, 10), btn.dataset.tagName || '');
    }
});

function bulkTransferTags() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select tags to transfer');
        return;
    }
    
    const users = <?= json_encode($users ?? []) ?>;
    if (users.length === 0) {
        alert('No users available for transfer');
        return;
    }
    
    const userOptions = users.map(user =>
        `<option value="${user.id}">${user.username} (${user.full_name || 'No name'})</option>`
    ).join('');
    
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
    modal.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Transfer Tags</h3>
            <p class="text-sm text-gray-600 mb-4">Transfer ${ids.length} selected tag(s) to another user.</p>
            
            <form method="POST" action="/tags/bulk-transfer">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                ${ids.map(id => `<input type="hidden" name="tag_ids[]" value="${id}">`).join('')}
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Transfer to User</label>
                    <select name="target_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                        <option value="">Select User</option>
                        ${userOptions}
                    </select>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="this.closest('.fixed').remove()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark text-sm font-medium">
                        Transfer All
                    </button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('create_name').focus();
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.querySelector('#createModal form').reset();
}

function openEditModal(tag) {
    document.getElementById('edit_id').value = tag.id;
    document.getElementById('edit_name').value = tag.name;
    document.getElementById('edit_color').value = tag.color;
    document.getElementById('edit_description').value = tag.description || '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('edit_name').focus();
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function deleteTag(id, name) {
    if (confirm(`Are you sure you want to delete the tag "${name}"? This will remove it from all domains.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/tags/delete';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        form.appendChild(idInput);
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?= csrf_token() ?>';
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateModal();
        closeEditModal();
    }
});

// Close modals on backdrop click
document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCreateModal();
    }
});

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

document.getElementById('importModal').addEventListener('click', function(e) {
    if (e.target === this) {
        document.getElementById('importModal').classList.add('hidden');
    }
});

// Close export dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('exportDropdownWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('exportDropdownMenu').classList.add('hidden');
    }
});

// --- Import drag-and-drop & loading ---
(function() {
    const dropzone = document.getElementById('tagDropzone');
    const fileInput = document.getElementById('tagFileInput');
    const content = document.getElementById('tagDropzoneContent');
    const fileInfo = document.getElementById('tagDropzoneFile');
    const fileName = document.getElementById('tagFileName');
    const fileSize = document.getElementById('tagFileSize');
    const removeBtn = document.getElementById('tagFileRemove');
    const form = document.getElementById('tagImportForm');
    const submitBtn = document.getElementById('tagImportBtn');

    function formatSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function showFile(file) {
        fileName.textContent = file.name;
        fileSize.textContent = formatSize(file.size);
        content.classList.add('hidden');
        fileInfo.classList.remove('hidden');
        dropzone.classList.remove('border-gray-300');
        dropzone.classList.add('border-primary', 'bg-primary/5');
    }

    function resetDropzone() {
        fileInput.value = '';
        content.classList.remove('hidden');
        fileInfo.classList.add('hidden');
        dropzone.classList.add('border-gray-300');
        dropzone.classList.remove('border-primary', 'bg-primary/5');
    }

    fileInput.addEventListener('change', function() {
        if (this.files.length) showFile(this.files[0]);
    });

    removeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        resetDropzone();
    });

    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropzone.classList.add('border-primary', 'bg-primary/5');
            dropzone.classList.remove('border-gray-300');
        });
    });

    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            if (!fileInput.files.length) {
                dropzone.classList.remove('border-primary', 'bg-primary/5');
                dropzone.classList.add('border-gray-300');
            }
        });
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        const files = e.dataTransfer.files;
        if (files.length) {
            fileInput.files = files;
            showFile(files[0]);
        }
    });

    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>Importing...';
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
