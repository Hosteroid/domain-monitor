<?php
$title = 'Notification Groups';
$pageTitle = 'Notification Groups';
$pageDescription = 'Manage notification channels and assignments';
$pageIcon = 'fas fa-bell';
ob_start();
?>

<!-- Quick Actions -->
<div class="mb-4 flex justify-end">
    <a href="/groups/create" class="inline-flex items-center px-4 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
        <i class="fas fa-plus mr-2"></i>
        Create New Group
    </a>
</div>

<!-- Info Card -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
    <div class="flex items-start">
        <div class="flex-shrink-0">
            <i class="fas fa-info-circle text-blue-500 text-lg"></i>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-semibold text-gray-900 mb-1">About Notification Groups</h3>
            <p class="text-xs text-gray-600 leading-relaxed">
                Notification groups allow you to organize your notification channels. You can create multiple channels 
                (Email, Telegram, Discord, Slack, Mattermost, Pushover, Webhook) within each group, then assign domains to the group. When a domain 
                is about to expire, all active channels in its group will receive notifications.
            </p>
        </div>
    </div>
</div>

<!-- Bulk Actions Toolbar (Hidden by default, shown when groups are selected) -->
<div id="bulk-actions" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-blue-900"></span>
            
            <?php if (\Core\Auth::isAdmin()): ?>
                <button type="button" onclick="bulkTransfer()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors font-medium">
                    <i class="fas fa-exchange-alt mr-2"></i>
                    Transfer Selected
                </button>
            <?php endif; ?>
            
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

<!-- Groups List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <?php if (!empty($groups)): ?>
        <!-- Table View (Desktop) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-primary focus:ring-primary">
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Group Name</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Channels</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Domains</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($groups as $group): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4">
                                <input type="checkbox" class="group-checkbox rounded border-gray-300 text-primary focus:ring-primary" value="<?= $group['id'] ?>" onchange="updateBulkActions()">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-bell text-primary"></i>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($group['name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-700 max-w-xs truncate">
                                    <?= htmlspecialchars($group['description'] ?? 'No description') ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                    <i class="fas fa-plug mr-1"></i>
                                    <?= $group['channel_count'] ?> channel<?= $group['channel_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                    <i class="fas fa-globe mr-1"></i>
                                    <?= $group['domain_count'] ?> domain<?= $group['domain_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="/groups/<?= $group['id'] ?>/edit" class="text-blue-600 hover:text-blue-800" title="Manage">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                    <?php if (\Core\Auth::isAdmin()): ?>
                                        <button onclick="transferGroup(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name']) ?>')" 
                                                class="text-green-600 hover:text-green-800" 
                                                title="Transfer Group">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="/groups/<?= $group['id'] ?>/delete" 
                                       class="text-red-600 hover:text-red-800" 
                                       title="Delete"
                                       onclick="return confirm('Are you sure? Domains will be unassigned from this group.')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Card View (Mobile) -->
        <div class="md:hidden divide-y divide-gray-200">
            <?php foreach ($groups as $group): ?>
                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bell text-primary"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($group['name']) ?></h3>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($group['description'] ?? 'No description') ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex space-x-3 mb-3">
                        <span class="px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-plug mr-1"></i>
                            <?= $group['channel_count'] ?> channels
                        </span>
                        <span class="px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <i class="fas fa-globe mr-1"></i>
                            <?= $group['domain_count'] ?> domains
                        </span>
                    </div>
                    
                    <div class="flex space-x-2">
                        <a href="/groups/<?= $group['id'] ?>/edit" class="flex-1 px-3 py-1.5 bg-blue-50 text-blue-600 rounded text-center text-sm hover:bg-blue-100 transition-colors">
                            <i class="fas fa-cog mr-1"></i> Manage
                        </a>
                        <a href="/groups/<?= $group['id'] ?>/delete" 
                           class="flex-1 px-3 py-1.5 bg-red-50 text-red-600 rounded text-center text-sm hover:bg-red-100 transition-colors"
                           onclick="return confirm('Are you sure? Domains will be unassigned from this group.')">
                            <i class="fas fa-trash mr-1"></i> Delete
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-bell-slash text-gray-300 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Notification Groups</h3>
            <p class="text-sm text-gray-500 mb-4">Create your first notification group to start receiving alerts</p>
            <a href="/groups/create" class="inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                <i class="fas fa-plus mr-2"></i>
                Create Your First Group
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.group-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.group-checkbox:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    if (checkboxes.length > 0) {
        bulkActions.classList.remove('hidden');
        bulkActions.classList.add('flex');
        selectedCount.textContent = checkboxes.length + ' group(s) selected';
    } else {
        bulkActions.classList.add('hidden');
        bulkActions.classList.remove('flex');
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.group-checkbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
        selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.group-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function getSelectedGroupIds() {
    const checkboxes = document.querySelectorAll('.group-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function bulkDelete() {
    const groupIds = getSelectedGroupIds();
    
    if (groupIds.length === 0) {
        alert('Please select at least one group to delete');
        return;
    }
    
    if (!confirm(`Are you sure you want to delete ${groupIds.length} group(s)? Domains will be unassigned from these groups.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/groups/bulk-delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'group_ids';
    idsInput.value = JSON.stringify(groupIds);
    form.appendChild(idsInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Transfer single group
function transferGroup(groupId, groupName) {
    const users = <?= json_encode($users ?? []) ?>;
    
    if (users.length === 0) {
        alert('No users available for transfer');
        return;
    }
    
    const userOptions = users.map(user => 
        `<option value="${user.id}">${user.username} (${user.full_name || 'No name'})</option>`
    ).join('');
            
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-6 w-96">
                    <h3 class="text-lg font-semibold mb-4">Transfer Group</h3>
                    <p class="text-sm text-gray-600 mb-4">Transfer group "${groupName}" to another user:</p>
                    
                    <form method="POST" action="/groups/transfer">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="group_id" value="${groupId}">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transfer to User:</label>
                            <select name="target_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Select User</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="this.closest('.fixed').remove()" 
                                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                Transfer
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
    document.body.appendChild(modal);
}

// Bulk transfer groups
function bulkTransfer() {
    const selectedCheckboxes = document.querySelectorAll('input[name="group_ids[]"]:checked');
    if (selectedCheckboxes.length === 0) {
        alert('Please select groups to transfer');
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
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-6 w-96">
                    <h3 class="text-lg font-semibold mb-4">Transfer Groups</h3>
                    <p class="text-sm text-gray-600 mb-4">Transfer ${selectedCheckboxes.length} selected group(s) to another user:</p>
                    
                    <form method="POST" action="/groups/bulk-transfer">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        ${Array.from(selectedCheckboxes).map(cb => 
                            `<input type="hidden" name="group_ids[]" value="${cb.value}">`
                        ).join('')}
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transfer to User:</label>
                            <select name="target_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Select User</option>
                                ${userOptions}
                            </select>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="this.closest('.fixed').remove()" 
                                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                Transfer All
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
    document.body.appendChild(modal);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
