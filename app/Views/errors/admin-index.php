<?php
$title = 'Error Logs';
$pageTitle = 'Error Logs';
$pageDescription = 'Monitor and manage application errors';
$pageIcon = 'fas fa-bug';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder, $filters) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $filters;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/errors?' . http_build_query($params);
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
$currentFilters = $filters ?? ['resolved' => '', 'type' => '', 'sort' => 'last_occurred_at', 'order' => 'desc'];
?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Total Errors Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Errors</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $errorStats['total_errors'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-red-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Unresolved Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Unresolved</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $errorStats['unresolved'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-circle text-orange-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Last 24h Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last 24h</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $errorStats['last_24h'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-blue-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Total Occurrences Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Occurrences</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $errorStats['total_occurrences'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-indigo-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-layer-group text-indigo-600 text-lg"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/errors" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                <select name="resolved" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Errors</option>
                    <option value="0" <?= $currentFilters['resolved'] === '0' ? 'selected' : '' ?>>Unresolved Only</option>
                    <option value="1" <?= $currentFilters['resolved'] === '1' ? 'selected' : '' ?>>Resolved Only</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Error Type</label>
                <input type="text" name="type" value="<?= htmlspecialchars($currentFilters['type']) ?>" placeholder="e.g., PDOException" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Sort By</label>
                <select name="sort" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="last_occurred_at" <?= $currentFilters['sort'] === 'last_occurred_at' ? 'selected' : '' ?>>Last Occurred</option>
                    <option value="occurrences" <?= $currentFilters['sort'] === 'occurrences' ? 'selected' : '' ?>>Most Frequent</option>
                    <option value="occurred_at" <?= $currentFilters['sort'] === 'occurred_at' ? 'selected' : '' ?>>First Occurred</option>
                </select>
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply
                </button>
                <a href="/errors" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times mr-2"></i>
                    Clear
                </a>
            </div>
        </div>
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
    </form>
</div>

<!-- Pagination Info -->
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?></span> error(s)
    </div>
    
    <form method="GET" action="/errors" class="flex items-center gap-2">
        <!-- Preserve filters -->
        <input type="hidden" name="resolved" value="<?= htmlspecialchars($currentFilters['resolved']) ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($currentFilters['type']) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($currentFilters['sort']) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($currentFilters['order']) ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
            <option value="10" <?= $pagination['per_page'] == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= $pagination['per_page'] == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $pagination['per_page'] == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $pagination['per_page'] == 100 ? 'selected' : '' ?>>100</option>
        </select>
    </form>
</div>

<!-- Errors List -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <!-- Bulk Actions Bar (shown when errors are selected) -->
    <div id="bulk-actions" class="hidden px-6 py-3 bg-blue-50 border-b border-blue-200 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-gray-700"></span>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
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
    <?php if (!empty($errors)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-primary focus:ring-primary">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Error
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Location
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Occurrences
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Last Occurred
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Status
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($errors as $error): ?>
                        <?php
                        $errorTypeShort = substr(strrchr($error['error_type'], '\\'), 1) ?: $error['error_type'];
                        $isResolved = (bool)$error['is_resolved'];
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4">
                                <input type="checkbox" class="error-checkbox rounded border-gray-300 text-primary focus:ring-primary" value="<?= htmlspecialchars($error['error_id']) ?>" onchange="updateBulkActions()">
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 h-10 w-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                                        <i class="fas fa-bug text-red-600"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-xs font-mono font-semibold text-primary"><?= htmlspecialchars($error['error_id']) ?></span>
                                            <button onclick="copyToClipboard('<?= htmlspecialchars($error['error_id']) ?>')" class="text-gray-400 hover:text-primary" title="Copy Error ID">
                                                <i class="fas fa-copy text-xs"></i>
                                            </button>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($errorTypeShort) ?></p>
                                        <p class="text-xs text-gray-600 mt-0.5 truncate" style="max-width: 300px;" title="<?= htmlspecialchars($error['error_message']) ?>">
                                            <?= htmlspecialchars($error['error_message']) ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs">
                                    <p class="font-mono text-gray-600 truncate" style="max-width: 200px;" title="<?= htmlspecialchars($error['error_file']) ?>">
                                        <?= htmlspecialchars(basename($error['error_file'])) ?>
                                    </p>
                                    <p class="text-gray-500 mt-0.5">
                                        <i class="fas fa-hashtag mr-1"></i>
                                        Line <?= $error['error_line'] ?>
                                    </p>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $error['occurrences'] >= 10 ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800' ?>">
                                    <i class="fas fa-redo mr-1"></i>
                                    <?= $error['occurrences'] ?>×
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div class="flex items-center">
                                    <i class="far fa-clock mr-2"></i>
                                    <?= date('M d, H:i', strtotime($error['last_occurred_at'])) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($isResolved): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Resolved
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800 border border-orange-200">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        Unresolved
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="/errors/<?= htmlspecialchars($error['error_id']) ?>" class="text-blue-600 hover:text-blue-800" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (!$isResolved): ?>
                                        <button onclick="markResolved('<?= htmlspecialchars($error['error_id']) ?>')" class="text-green-600 hover:text-green-800" title="Mark as Resolved">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="deleteError('<?= htmlspecialchars($error['error_id']) ?>')" class="text-red-600 hover:text-red-800" title="Delete Error">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-check-circle text-green-500 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No Errors Found</h3>
            <p class="text-sm text-gray-500 mb-4">
                <?php if (!empty($currentFilters['resolved']) || !empty($currentFilters['type'])): ?>
                    No errors match your filter criteria.
                <?php else: ?>
                    Great! Your application is running smoothly.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination Controls -->
<?php if ($pagination['total_pages'] > 1): ?>
<div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-4">
    <div class="text-sm text-gray-600">
        Page <span class="font-semibold text-gray-900"><?= $pagination['current_page'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total_pages'] ?></span>
    </div>
    
    <div class="flex items-center gap-1">
        <?php
        $currentPage = $pagination['current_page'];
        $totalPages = $pagination['total_pages'];
        
        function paginationUrl($page, $filters, $perPage) {
            $params = $filters;
            $params['page'] = $page;
            $params['per_page'] = $perPage;
            return '/errors?' . http_build_query($params);
        }
        ?>
        
        <?php if ($currentPage > 1): ?>
            <a href="<?= paginationUrl(1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="<?= paginationUrl($currentPage - 1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
                <i class="fas fa-angle-left"></i> Previous
            </a>
        <?php endif; ?>
        
        <?php
        $range = 2;
        $start = max(1, $currentPage - $range);
        $end = min($totalPages, $currentPage + $range);
        
        if ($start > 1) {
            echo '<a href="' . paginationUrl(1, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">1</a>';
            if ($start > 2) echo '<span class="px-2 text-gray-500">...</span>';
        }
        
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
            } else {
                echo '<a href="' . paginationUrl($i, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">' . $i . '</a>';
            }
        }
        
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="px-2 text-gray-500">...</span>';
            echo '<a href="' . paginationUrl($totalPages, $currentFilters, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">' . $totalPages . '</a>';
        }
        ?>
        
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= paginationUrl($currentPage + 1, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
                Next <i class="fas fa-angle-right"></i>
            </a>
            <a href="<?= paginationUrl($totalPages, $currentFilters, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
                <i class="fas fa-angle-double-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Resolution Notes Modal -->
<div id="resolutionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="mt-3">
            <!-- Modal Header -->
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    Mark Error as Resolved
                </h3>
                <button onclick="closeResolutionModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- Modal Body -->
            <div class="mb-4">
                <label for="resolutionNotes" class="block text-sm font-medium text-gray-700 mb-2">
                    Resolution Notes (Optional)
                </label>
                <textarea 
                    id="resolutionNotes" 
                    rows="4" 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent resize-none"
                    placeholder="Describe how you resolved this error or any relevant notes..."
                ></textarea>
                <p class="mt-1 text-xs text-gray-500">
                    Add any details about the fix or resolution for future reference.
                </p>
            </div>
            
            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3">
                <button 
                    onclick="closeResolutionModal()" 
                    class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium"
                >
                    Cancel
                </button>
                <button 
                    onclick="submitResolution()" 
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium"
                >
                    <i class="fas fa-check mr-2"></i>
                    Mark as Resolved
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopySuccess();
        });
    } else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showCopySuccess();
    }
}

function showCopySuccess() {
    // Use the existing toast container from messages.php
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed bottom-4 right-4 z-[9999] space-y-3 max-w-sm';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'toast bg-white border-l-4 border-green-500 rounded-lg shadow-lg p-4 flex items-start animate-slide-in';
    toast.innerHTML = `
        <div class="flex-shrink-0">
            <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <i class="fas fa-check text-green-600 text-sm"></i>
            </div>
        </div>
        <div class="ml-3 flex-1">
            <p class="text-sm font-medium text-gray-900">Success</p>
            <p class="text-sm text-gray-600 mt-0.5">Copied to clipboard!</p>
        </div>
        <button onclick="this.parentElement.remove()" class="ml-3 flex-shrink-0 text-gray-400 hover:text-gray-600 transition-colors">
            <i class="fas fa-times text-sm"></i>
        </button>
    `;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

let currentErrorId = null;

function markResolved(errorId) {
    currentErrorId = errorId;
    document.getElementById('resolutionModal').classList.remove('hidden');
}

function closeResolutionModal() {
    document.getElementById('resolutionModal').classList.add('hidden');
    document.getElementById('resolutionNotes').value = '';
    currentErrorId = null;
}

function submitResolution() {
    if (!currentErrorId) return;
    
    const notes = document.getElementById('resolutionNotes').value;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/errors/' + currentErrorId + '/resolve';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    if (notes) {
        const notesInput = document.createElement('input');
        notesInput.type = 'hidden';
        notesInput.name = 'notes';
        notesInput.value = notes;
        form.appendChild(notesInput);
    }
    
    document.body.appendChild(form);
    form.submit();
}

function deleteError(errorId) {
    if (!confirm('Are you sure you want to delete this error and all its occurrences? This action cannot be undone.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/errors/' + errorId + '/delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Checkbox selection functions
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.error-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.error-checkbox:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    if (checkboxes.length > 0) {
        bulkActions.classList.remove('hidden');
        selectedCount.textContent = checkboxes.length + ' error(s) selected';
    } else {
        bulkActions.classList.add('hidden');
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.error-checkbox');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
    selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
}

function getSelectedErrorIds() {
    const checkboxes = document.querySelectorAll('.error-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.error-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

function bulkDelete() {
    const errorIds = getSelectedErrorIds();
    
    if (errorIds.length === 0) {
        alert('Please select at least one error to delete');
        return;
    }
    
    if (!confirm(`Are you sure you want to delete ${errorIds.length} error(s) and all their occurrences? This action cannot be undone.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/errors/bulk-delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'error_ids';
    idsInput.value = JSON.stringify(errorIds);
    form.appendChild(idsInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>

