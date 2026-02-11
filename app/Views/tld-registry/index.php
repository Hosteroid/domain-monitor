<?php
$title = 'TLD Registry';
$pageTitle = 'TLD Registry';
$pageDescription = 'Manage Top-Level Domain registry information';
$pageIcon = 'fas fa-database';
ob_start();

// Helper function to generate sort URL
function sortUrl($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    return '/tld-registry?' . http_build_query($params);
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
$currentFilters = $filters ?? ['search' => '', 'sort' => 'tld', 'order' => 'asc'];
?>

<!-- Action Buttons -->
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
<div class="mb-4 flex justify-end gap-2">
    <a href="/tld-registry/import-logs" class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
        <i class="fas fa-history mr-2"></i>
        Import Logs
    </a>
    <form method="POST" action="/tld-registry/start-progressive-import" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="import_type" value="check_updates">
        <button type="submit" <?= $tldStats['total'] == 0 ? 'disabled' : '' ?> class="inline-flex items-center px-4 py-2 <?= $tldStats['total'] == 0 ? 'bg-gray-400 cursor-not-allowed' : 'bg-primary hover:bg-primary-dark' ?> text-white text-sm rounded-lg transition-colors font-medium" title="<?= $tldStats['total'] == 0 ? 'Import TLDs first' : 'Check for IANA updates' ?>">
            <i class="fas fa-sync-alt mr-2"></i>
            Check Updates
        </button>
    </form>
    <form method="POST" action="/tld-registry/start-progressive-import" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="import_type" value="complete_workflow">
        <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium" title="Complete TLD import workflow: TLD List → RDAP → WHOIS → Registry URLs">
            <i class="fas fa-rocket mr-2"></i>
            Import TLDs
        </button>
    </form>
</div>
<?php else: ?>
<div class="mb-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
    <div class="flex items-center">
        <i class="fas fa-info-circle text-yellow-600 mr-2"></i>
        <p class="text-sm text-yellow-800">
            View-only mode. Contact admin to import or modify TLD data.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Total TLDs Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total TLDs</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $tldStats['total'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-globe text-blue-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Active TLDs Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Active</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $tldStats['active'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- With RDAP Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">With RDAP</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $tldStats['with_rdap'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-indigo-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-database text-indigo-600 text-lg"></i>
            </div>
        </div>
    </div>

    <!-- With WHOIS Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">With WHOIS</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1"><?= $tldStats['with_whois'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                <i class="fas fa-server text-orange-600 text-lg"></i>
            </div>
        </div>
    </div>
</div>


<!-- Search and Filters -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
    <form method="GET" action="/tld-registry" id="filter-form">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Search -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Search TLDs</label>
                <div class="relative">
                    <input type="text" name="search" id="tldSearch" value="<?= htmlspecialchars($currentFilters['search']) ?>" placeholder="Search TLDs..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs"></i>
                </div>
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Status</option>
                    <option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($_GET['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <!-- Data Type Filter -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1.5">Data Type</label>
                <select name="data_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm">
                    <option value="">All Types</option>
                    <option value="with_rdap" <?= ($_GET['data_type'] ?? '') === 'with_rdap' ? 'selected' : '' ?>>With RDAP</option>
                    <option value="with_whois" <?= ($_GET['data_type'] ?? '') === 'with_whois' ? 'selected' : '' ?>>With WHOIS</option>
                    <option value="with_registry" <?= ($_GET['data_type'] ?? '') === 'with_registry' ? 'selected' : '' ?>>With Registry URL</option>
                    <option value="missing_data" <?= ($_GET['data_type'] ?? '') === 'missing_data' ? 'selected' : '' ?>>Missing Data</option>
                </select>
            </div>
            
            <!-- Actions -->
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                    <i class="fas fa-filter mr-2"></i>
                    Apply
                </button>
                <a href="/tld-registry" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium">
                    <i class="fas fa-times mr-2"></i>
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
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?></span> TLD(s)
    </div>
    
    <form method="GET" action="/tld-registry" class="flex items-center gap-2">
        <!-- Preserve current filters -->
        <input type="hidden" name="search" value="<?= htmlspecialchars($currentFilters['search']) ?>">
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

<!-- TLD Registry Table -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <!-- Bulk Actions Bar (shown when TLDs are selected) -->
    <div id="bulk-actions" class="hidden px-6 py-3 bg-blue-50 border-b border-blue-200 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span id="selected-count" class="text-sm font-medium text-gray-700"></span>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <form method="POST" action="/tld-registry/bulk-delete" id="bulk-delete-form" class="inline">
                        <?= csrf_field() ?>
                        <button type="button" onclick="confirmBulkDelete()" class="inline-flex items-center px-4 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                            <i class="fas fa-trash mr-1"></i> Delete Selected
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <button type="button" onclick="clearSelection()" class="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fas fa-times mr-1.5"></i> Clear Selection
        </button>
    </div>
    <?php endif; ?>
    <?php if (!empty($tlds)): ?>
        <!-- Table View (Desktop) -->
        <div class="hidden lg:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <input type="checkbox" id="select-all" class="rounded border-gray-300 text-primary focus:ring-primary" onchange="toggleAllCheckboxes(this)">
                        </th>
                        <?php endif; ?>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('tld', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                TLD <?= sortIcon('tld', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('rdap_servers', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                RDAP Servers <?= sortIcon('rdap_servers', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('whois_server', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                WHOIS Server <?= sortIcon('whois_server', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('updated_at', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Last Updated <?= sortIcon('updated_at', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <a href="<?= sortUrl('is_active', $currentFilters['sort'], $currentFilters['order']) ?>" class="hover:text-primary flex items-center">
                                Status <?= sortIcon('is_active', $currentFilters['sort'], $currentFilters['order']) ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($tlds as $tld): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <input type="checkbox" name="tld_ids[]" value="<?= $tld['id'] ?>" class="tld-checkbox rounded border-gray-300 text-primary focus:ring-primary">
                            </td>
                            <?php endif; ?>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-globe text-primary"></i>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($tld['tld']) ?></div>
                                        <?php if ($tld['registry_url']): ?>
                                        <div class="text-sm text-gray-500">
                                            <a href="<?= htmlspecialchars($tld['registry_url']) ?>" target="_blank" class="text-primary hover:text-primary-dark">
                                                <i class="fas fa-external-link-alt mr-1"></i>
                                                Registry
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($tld['rdap_servers']): ?>
                                    <?php 
                                    $rdapServers = json_decode($tld['rdap_servers'], true);
                                    if (is_array($rdapServers) && !empty($rdapServers)):
                                    ?>
                                    <div class="text-sm text-gray-900">
                                        <?php foreach (array_slice($rdapServers, 0, 2) as $server): ?>
                                        <div class="font-mono text-xs bg-gray-50 px-2 py-1 rounded mb-1"><?= htmlspecialchars($server) ?></div>
                                        <?php endforeach; ?>
                                        <?php if (count($rdapServers) > 2): ?>
                                        <div class="text-xs text-gray-500">+<?= count($rdapServers) - 2 ?> more</div>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-sm text-gray-400">None</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <span class="text-sm text-gray-400">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($tld['whois_server']): ?>
                                <div class="text-sm font-mono text-gray-900 bg-gray-50 px-2 py-1 rounded"><?= htmlspecialchars($tld['whois_server']) ?></div>
                                <?php else: ?>
                                <span class="text-sm text-gray-400">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if ($tld['updated_at']): ?>
                                <div class="flex items-center">
                                    <i class="far fa-clock mr-2"></i>
                                    <?= date('M d, H:i', strtotime($tld['updated_at'])) ?>
                                </div>
                                <?php else: ?>
                                <span class="text-gray-400">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border <?= $tld['is_active'] ? 'bg-green-100 text-green-700 border-green-200' : 'bg-gray-100 text-gray-700 border-gray-200' ?>">
                                    <i class="fas <?= $tld['is_active'] ? 'fa-check-circle' : 'fa-pause-circle' ?> mr-1"></i>
                                    <?= $tld['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="/tld-registry/<?= $tld['id'] ?>" class="text-blue-600 hover:text-blue-800" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                    <a href="/tld-registry/<?= $tld['id'] ?>/refresh" class="text-green-600 hover:text-green-800" title="Refresh" onclick="return confirm('Refresh TLD data from IANA?')">
                                        <i class="fas fa-sync-alt"></i>
                                    </a>
                                    <a href="/tld-registry/<?= $tld['id'] ?>/toggle-active" class="text-orange-600 hover:text-orange-800" title="Toggle Status" onclick="return confirm('Toggle TLD status?')">
                                        <i class="fas fa-power-off"></i>
                                    </a>
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
            <?php foreach ($tlds as $tld): ?>
                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-globe text-primary"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($tld['tld']) ?></h3>
                                <?php if ($tld['registry_url']): ?>
                                <a href="<?= htmlspecialchars($tld['registry_url']) ?>" target="_blank" class="text-xs text-primary hover:text-primary-dark">
                                    <i class="fas fa-external-link-alt mr-1"></i>
                                    Registry
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold <?= $tld['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' ?>">
                            <?= $tld['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    
                    <div class="space-y-2 text-sm">
                        <?php if ($tld['rdap_servers']): ?>
                            <?php 
                            $rdapServers = json_decode($tld['rdap_servers'], true);
                            if (is_array($rdapServers) && !empty($rdapServers)):
                            ?>
                            <div class="flex items-start">
                                <i class="fas fa-database text-gray-400 mr-2 w-4 mt-0.5"></i>
                                <div class="flex-1">
                                    <div class="font-mono text-xs bg-gray-50 px-2 py-1 rounded mb-1"><?= htmlspecialchars($rdapServers[0]) ?></div>
                                    <?php if (count($rdapServers) > 1): ?>
                                    <div class="text-xs text-gray-500">+<?= count($rdapServers) - 1 ?> more RDAP server(s)</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($tld['whois_server']): ?>
                        <div class="flex items-center">
                            <i class="fas fa-server text-gray-400 mr-2 w-4"></i>
                            <span class="font-mono text-xs bg-gray-50 px-2 py-1 rounded"><?= htmlspecialchars($tld['whois_server']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-center">
                            <i class="far fa-clock text-gray-400 mr-2 w-4"></i>
                            <span class="text-gray-500"><?= $tld['updated_at'] ? date('M d, H:i', strtotime($tld['updated_at'])) : 'Never updated' ?></span>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2 mt-3">
                        <a href="/tld-registry/<?= $tld['id'] ?>" class="<?= (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'flex-1' : 'w-full' ?> px-3 py-1.5 bg-blue-50 text-blue-600 rounded text-center text-sm hover:bg-blue-100 transition-colors">
                            <i class="fas fa-eye mr-1"></i> View
                        </a>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <a href="/tld-registry/<?= $tld['id'] ?>/refresh" class="flex-1 px-3 py-1.5 bg-green-50 text-green-600 rounded text-center text-sm hover:bg-green-100 transition-colors" onclick="return confirm('Refresh TLD data?')">
                            <i class="fas fa-sync-alt mr-1"></i> Refresh
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-12 px-6">
            <div class="mb-4">
                <i class="fas fa-globe text-gray-300 text-6xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 mb-1">No TLDs Found</h3>
            <p class="text-sm text-gray-500 mb-4">
                <?php if (!empty($currentFilters['search'])): ?>
                    No TLDs match your search criteria.
                <?php else: ?>
                    Start by importing the TLD list from IANA.
                <?php endif; ?>
            </p>
            <?php if (empty($currentFilters['search'])): ?>
            <form method="POST" action="/tld-registry/start-progressive-import" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="import_type" value="complete_workflow">
                <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    <i class="fas fa-rocket mr-2"></i>
                    Import TLDs
                </button>
            </form>
            <?php endif; ?>
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
            return '/tld-registry?' . http_build_query($params);
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
function toggleAllCheckboxes(selectAllCheckbox) {
    const checkboxes = document.querySelectorAll('.tld-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.tld-checkbox:checked');
    const count = checkboxes.length;
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAllCheckbox = document.getElementById('select-all');
    
    if (count > 0) {
        bulkActions.classList.remove('hidden');
        selectedCount.textContent = count + ' TLD(s) selected';
    } else {
        bulkActions.classList.add('hidden');
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.tld-checkbox');
    if (selectAllCheckbox) {
        if (count === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (count === allCheckboxes.length) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
        }
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.tld-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    const selectAllCheckbox = document.getElementById('select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
    }
    updateSelectedCount();
}

function confirmBulkDelete() {
    const checkboxes = document.querySelectorAll('.tld-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select TLDs to delete');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${checkboxes.length} selected TLD(s)? This action cannot be undone.`)) {
        // Add selected checkboxes to form
        const form = document.getElementById('bulk-delete-form');
        checkboxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'tld_ids[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
        form.submit();
    }
}

// Add event listeners to checkboxes
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.tld-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    updateSelectedCount();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>