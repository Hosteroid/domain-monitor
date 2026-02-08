<?php
$title = 'User Management';
$pageTitle = 'User Management';
$pageDescription = 'Manage system users and permissions';
$pageIcon = 'fas fa-users';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/users?' . http_build_query($params);
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
$currentFilters = $filters ?? ['search' => '', 'role' => '', 'status' => '', 'sort' => 'username', 'order' => 'asc'];

// Mock pagination for now (will need to be implemented in controller)
$pagination = $pagination ?? [
    'current_page' => 1,
    'total_pages' => 1,
    'per_page' => 25,
    'total' => count($users),
    'showing_from' => 1,
    'showing_to' => count($users)
];
?>

<!-- Action Buttons -->
<div class="mb-4 flex justify-end">
    <a href="/users/create" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
        <i class="fas fa-user-plus mr-2"></i>
        Add User
    </a>
</div>

<!-- Filters & Search -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/users" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <!-- Search -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Search</label>
                <div class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($currentFilters['search']) ?>" placeholder="Search users..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                </div>
            </div>
            
            <!-- Role Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Role</label>
                <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $currentFilters['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="user" <?= $currentFilters['role'] === 'user' ? 'selected' : '' ?>>User</option>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $currentFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $currentFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <!-- Apply/Reset Buttons -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply Filters
                </button>
                <a href="/users" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times mr-2"></i>
                    Clear
                </a>
            </div>
        </div>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
    </form>
</div>

<!-- Bulk Actions Toolbar (Hidden by default, shown when users are selected) -->
<div id="bulk-actions" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-blue-900"></span>
            
            <button type="button" onclick="bulkToggleStatus('active')" class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium">
                <i class="fas fa-user-check mr-2"></i>
                Activate Selected
            </button>
            
            <button type="button" onclick="bulkToggleStatus('inactive')" class="inline-flex items-center px-4 py-2 bg-orange-600 text-white text-sm rounded-lg hover:bg-orange-700 transition-colors font-medium">
                <i class="fas fa-user-slash mr-2"></i>
                Deactivate Selected
            </button>
            
            <button type="button" onclick="bulkDeleteUsers()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors font-medium">
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
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?></span> user(s)
    </div>
    
    <form method="GET" action="/users" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($currentFilters['search']) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($currentFilters['role']) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($currentFilters['status']) ?>">
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

<!-- Users Table -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <?php if (!empty($users)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left">
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-primary focus:ring-primary">
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('full_name', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            User <?= sortIcon('full_name', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('username', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            Username <?= sortIcon('username', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('role', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            Role <?= sortIcon('role', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('is_active', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            Status <?= sortIcon('is_active', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('email_verified', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            Email Verified <?= sortIcon('email_verified', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <a href="<?= sortUrl('last_login', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                            Last Login <?= sortIcon('last_login', $currentFilters['sort'], $currentFilters['order']) ?>
                        </a>
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                        <td class="px-6 py-4">
                            <?php if ($user['id'] != \Core\Auth::id()): ?>
                                <input type="checkbox" class="user-checkbox rounded border-gray-300 text-primary focus:ring-primary" value="<?= $user['id'] ?>" onchange="updateBulkActions()">
                            <?php else: ?>
                                <span class="text-gray-300" title="Cannot select your own account">
                                    <i class="fas fa-lock text-xs"></i>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <?php
                                // Get avatar data for this user (now fast with database caching)
                                $avatar = \App\Helpers\AvatarHelper::getAvatar($user, 40);
                                ?>
                                <div class="flex-shrink-0 h-10 w-10 rounded-lg overflow-hidden bg-primary bg-opacity-10 flex items-center justify-center">
                                    <?php if ($avatar['type'] === 'uploaded' || $avatar['type'] === 'gravatar'): ?>
                                        <img src="<?= htmlspecialchars($avatar['url']) ?>" 
                                             alt="<?= htmlspecialchars($avatar['alt']) ?>" 
                                             class="w-full h-full object-cover"
                                             loading="lazy">
                                    <?php else: ?>
                                        <span class="text-primary font-semibold text-sm">
                                            <?= $avatar['initials'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($user['full_name'] ?? 'N/A') ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($user['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-900"><?= htmlspecialchars($user['username']) ?></span>
                                <?php if (!empty($user['two_factor_enabled'])): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-semibold border border-green-200" title="Two-factor authentication enabled">
                                        <i class="fas fa-shield-alt mr-0.5"></i>2FA
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 bg-gray-100 text-gray-400 rounded text-[10px] font-medium border border-gray-200" title="Two-factor authentication not enabled">
                                        <i class="fas fa-shield-alt mr-0.5"></i>No 2FA
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border 
                                <?= $user['role'] === 'admin' ? 'bg-amber-100 text-amber-700 border-amber-200' : 'bg-blue-100 text-blue-700 border-blue-200' ?>">
                                <i class="fas fa-<?= $user['role'] === 'admin' ? 'crown' : 'user' ?> mr-1"></i>
                                <?= ucfirst($user['role']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border 
                                <?= $user['is_active'] ? 'bg-green-100 text-green-700 border-green-200' : 'bg-red-100 text-red-700 border-red-200' ?>">
                                <i class="fas fa-<?= $user['is_active'] ? 'check-circle' : 'times-circle' ?> mr-1"></i>
                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <?php if ($user['email_verified']): ?>
                                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                    <span class="text-sm text-gray-900">Verified</span>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                                    <span class="text-sm text-gray-500">Not Verified</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php if ($user['last_login']): ?>
                                <div class="flex items-center">
                                    <i class="far fa-clock mr-2"></i>
                                    <?= date('M d, H:i', strtotime($user['last_login'])) ?>
                                </div>
                            <?php else: ?>
                                <span class="text-gray-400">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex items-center justify-end space-x-2">
                                <a href="/users/<?= $user['id'] ?>" class="text-gray-600 hover:text-primary" title="View Profile">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="/users/<?= $user['id'] ?>/edit" class="text-blue-600 hover:text-blue-800" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($user['id'] != \Core\Auth::id()): ?>
                                    <a href="#" 
                                       class="text-orange-600 hover:text-orange-800" 
                                       title="<?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                       onclick="toggleUserStatus(<?= $user['id'] ?>); return false;">
                                        <i class="fas fa-<?= $user['is_active'] ? 'user-slash' : 'user-check' ?>"></i>
                                    </a>
                                    <a href="#" 
                                       class="text-red-600 hover:text-red-800" 
                                       title="Delete"
                                       onclick="deleteUser(<?= $user['id'] ?>); return false;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400" title="Cannot modify your own account">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <!-- Empty State -->
        <div class="p-12 text-center">
            <i class="fas fa-users text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Users Yet</h3>
            <p class="text-sm text-gray-500 mb-4">Start by adding your first user</p>
            <a href="/users/create" class="inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                <i class="fas fa-user-plus mr-2"></i>
                Add Your First User
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
        // Helper function to build pagination URL
        function paginationUrl($page, $filters, $perPage) {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/users?' . http_build_query($params);
        }
        
        $currentPage = $pagination['current_page'];
        $totalPages = $pagination['total_pages'];
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
        $range = 2;
        $start = max(1, $currentPage - $range);
        $end = min($totalPages, $currentPage + $range);
        
        if ($start > 1) {
            echo '<a href="' . paginationUrl(1, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
            if ($start > 2) {
                echo '<span class="px-2 text-gray-500">...</span>';
            }
        }
        
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
            }
        }
        
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
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    if (checkboxes.length > 0) {
        bulkActions.classList.remove('hidden');
        bulkActions.classList.add('flex');
        selectedCount.textContent = checkboxes.length + ' user(s) selected';
    } else {
        bulkActions.classList.add('hidden');
        bulkActions.classList.remove('flex');
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.user-checkbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
        selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function getSelectedUserIds() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function bulkToggleStatus(action) {
    const userIds = getSelectedUserIds();
    
    if (userIds.length === 0) {
        alert('Please select at least one user');
        return;
    }
    
    const actionText = action === 'active' ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${actionText} ${userIds.length} user(s)?`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/users/bulk-toggle-status';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'user_ids';
    idsInput.value = JSON.stringify(userIds);
    form.appendChild(idsInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);
    
    document.body.appendChild(form);
    form.submit();
}

function toggleUserStatus(userId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/users/' + userId + '/toggle-status';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

function deleteUser(userId) {
    if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/users/' + userId + '/delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

function bulkDeleteUsers() {
    const userIds = getSelectedUserIds();
    
    if (userIds.length === 0) {
        alert('Please select at least one user to delete');
        return;
    }
    
    if (!confirm(`Are you sure you want to delete ${userIds.length} user(s)? This action cannot be undone.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/users/bulk-delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'user_ids';
    idsInput.value = JSON.stringify(userIds);
    form.appendChild(idsInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>

