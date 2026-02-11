<?php
$title = 'Notification Groups';
$pageTitle = 'Notification Groups';
$pageDescription = 'Manage notification channels and assignments';
$pageIcon = 'fas fa-bell';
ob_start();
?>

<!-- Quick Actions -->
<div class="mb-4 flex gap-2 justify-end">
    <!-- Export Dropdown -->
    <div class="relative" id="groupExportDropdownWrapper">
        <button onclick="document.getElementById('groupExportMenu').classList.toggle('hidden')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition-colors font-medium">
            <i class="fas fa-download mr-2"></i>
            Export
            <i class="fas fa-chevron-down ml-2 text-xs"></i>
        </button>
        <div id="groupExportMenu" class="hidden absolute right-0 mt-1 w-44 bg-white rounded-lg shadow-lg border border-gray-200 z-30 overflow-hidden">
            <a href="/groups/export?format=csv" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <i class="fas fa-file-csv text-green-600 mr-2.5"></i>
                Export as CSV
            </a>
            <a href="/groups/export?format=json" class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors border-t border-gray-100">
                <i class="fas fa-file-code text-blue-600 mr-2.5"></i>
                Export as JSON
            </a>
        </div>
    </div>
    <!-- Import Button -->
    <button onclick="document.getElementById('groupImportModal').classList.remove('hidden')" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
        <i class="fas fa-upload mr-2"></i>
        Import
    </button>
    <a href="/groups/create" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
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

<!-- Groups List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <!-- Bulk Actions Bar (shown when groups are selected) -->
    <div id="bulk-actions" class="hidden px-6 py-3 bg-blue-50 border-b border-blue-200 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-gray-700"></span>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <?php if (\Core\Auth::isAdmin()): ?>
                    <button type="button" onclick="bulkTransfer()" class="inline-flex items-center px-4 py-1.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                        <i class="fas fa-exchange-alt mr-1"></i> Transfer Selected
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="bulkDelete()" class="inline-flex items-center px-4 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                        <i class="fas fa-trash mr-1"></i> Delete Selected
                    </button>
                </div>
            </div>
        </div>
        <button type="button" onclick="clearSelection()" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fas fa-times mr-1.5"></i> Clear Selection
        </button>
    </div>
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
                                    <form method="POST" action="/groups/<?= $group['id'] ?>/delete" class="inline" onsubmit="return confirm('Are you sure? Domains will be unassigned from this group.')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800" title="Delete"
                                                aria-label="Delete group <?= htmlspecialchars($group['name']) ?>">
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
                        <form method="POST" action="/groups/<?= $group['id'] ?>/delete" class="flex-1" onsubmit="return confirm('Are you sure? Domains will be unassigned from this group.')">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="w-full px-3 py-1.5 bg-red-50 text-red-600 rounded text-center text-sm hover:bg-red-100 transition-colors">
                                <i class="fas fa-trash mr-1"></i> Delete
                            </button>
                        </form>
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
        selectedCount.textContent = checkboxes.length + ' group(s) selected';
    } else {
        bulkActions.classList.add('hidden');
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
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">Transfer Group</h3>
                    <p class="text-sm text-gray-600 mb-4">Transfer group "${groupName}" to another user.</p>
                    
                    <form method="POST" action="/groups/transfer">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="group_id" value="${groupId}">
                        
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

// Bulk transfer groups
function bulkTransfer() {
    const groupIds = getSelectedGroupIds();
    if (groupIds.length === 0) {
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
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">Transfer Groups</h3>
                    <p class="text-sm text-gray-600 mb-4">Transfer ${groupIds.length} selected group(s) to another user.</p>
                    
                    <form method="POST" action="/groups/bulk-transfer">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        ${groupIds.map(id => 
                            `<input type="hidden" name="group_ids[]" value="${id}">`
                        ).join('')}
                        
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

// Close export dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('groupExportDropdownWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('groupExportMenu').classList.add('hidden');
    }
});

// Close import modal on backdrop click
document.getElementById('groupImportModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.add('hidden');
    }
});
</script>

<!-- Import Modal -->
<div id="groupImportModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-upload text-primary mr-2"></i>Import Notification Groups
            </h3>
            <button onclick="document.getElementById('groupImportModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="/groups/import" enctype="multipart/form-data" id="groupImportForm">
            <?= csrf_field() ?>
            <div class="p-6 space-y-4">
                <!-- Drag & Drop Zone -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Select File</label>
                    <div id="groupDropzone" class="relative border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer transition-all hover:border-primary hover:bg-gray-50">
                        <input type="file" name="import_file" accept=".csv,.json" required id="groupFileInput"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                        <div id="groupDropzoneContent">
                            <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600 font-medium">Drag & drop your file here</p>
                            <p class="text-xs text-gray-400 my-1">or</p>
                            <span class="inline-flex items-center px-3 py-1.5 bg-primary text-white text-xs rounded-lg font-medium">
                                <i class="fas fa-folder-open mr-1.5"></i>Browse Files
                            </span>
                            <p class="mt-2.5 text-xs text-gray-400">CSV, JSON &middot; Max <?= \App\Helpers\ViewHelper::getMaxUploadSize() ?></p>
                        </div>
                        <div id="groupDropzoneFile" class="hidden">
                            <i class="fas fa-file-alt text-2xl text-primary mb-1.5"></i>
                            <p class="text-sm font-medium text-gray-700" id="groupFileName"></p>
                            <p class="text-xs text-gray-400" id="groupFileSize"></p>
                            <button type="button" id="groupFileRemove" class="mt-1.5 text-xs text-red-500 hover:text-red-700 font-medium">
                                <i class="fas fa-trash-alt mr-1"></i>Remove
                            </button>
                        </div>
                    </div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p class="text-xs text-gray-700 font-medium mb-1"><i class="fas fa-info-circle text-blue-500 mr-1"></i> Expected Format</p>
                    <p class="text-xs text-gray-600">CSV: <code class="bg-white px-1 rounded">group_name, group_description, channel_type, channel_config, is_active</code></p>
                    <p class="text-xs text-gray-600 mt-0.5">JSON: array of group objects with nested channels array</p>
                    <p class="text-xs text-gray-500 mt-1.5"><i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>Channels with masked secrets will be imported as <strong>disabled</strong>. Update the credentials and enable them manually.</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                <button type="button" onclick="document.getElementById('groupImportModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="groupImportBtn" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-primary-dark transition-colors">
                    <i class="fas fa-upload mr-1.5"></i>Import Groups
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// --- Group Import drag-and-drop & loading ---
(function() {
    const dropzone = document.getElementById('groupDropzone');
    const fileInput = document.getElementById('groupFileInput');
    const content = document.getElementById('groupDropzoneContent');
    const fileInfo = document.getElementById('groupDropzoneFile');
    const fileName = document.getElementById('groupFileName');
    const fileSize = document.getElementById('groupFileSize');
    const removeBtn = document.getElementById('groupFileRemove');
    const form = document.getElementById('groupImportForm');
    const submitBtn = document.getElementById('groupImportBtn');

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
