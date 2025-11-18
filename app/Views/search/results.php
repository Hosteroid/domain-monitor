<?php
$title = 'Search Results';
$pageTitle = 'Search Results';
$pageDescription = 'Results for "' . htmlspecialchars($query) . '"';
$pageIcon = 'fas fa-search';
ob_start();
?>

<!-- Search Query Display -->
<div class="mb-4 bg-white rounded-lg border border-gray-200 p-4">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-600">Searching for:</p>
            <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($query) ?></h3>
        </div>
        <form action="/search" method="GET" class="flex gap-2">
            <input type="text" 
                   name="q" 
                   value="<?= htmlspecialchars($query) ?>"
                   placeholder="Search again..." 
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary text-sm"
                   autofocus>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark text-sm font-medium">
                <i class="fas fa-search mr-2"></i>
                Search
            </button>
        </form>
    </div>
</div>

<!-- Pagination Info & Per Page Selector -->
<?php if (!empty($existingDomains) && $pagination['total'] > 0): ?>
<div class="mb-4 flex justify-between items-center">
    <div class="text-sm text-gray-600">
        Showing <span class="font-semibold text-gray-900"><?= $pagination['showing_from'] ?></span> to 
        <span class="font-semibold text-gray-900"><?= $pagination['showing_to'] ?></span> of 
        <span class="font-semibold text-gray-900"><?= $pagination['total'] ?></span> result(s)
    </div>
    
    <form method="GET" action="/search" class="flex items-center gap-2">
        <input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>">
        
        <label for="per_page" class="text-sm text-gray-600">Show:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
            <option value="10" <?= $pagination['per_page'] == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= $pagination['per_page'] == 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $pagination['per_page'] == 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $pagination['per_page'] == 100 ? 'selected' : '' ?>>100</option>
        </select>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($existingDomains)): ?>
    <!-- Existing Domains Found -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
        <div class="px-6 py-4 border-b border-gray-200 bg-green-50">
            <h2 class="text-lg font-semibold text-green-900 flex items-center">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                Found <?= $pagination['total'] ?> Matching Domain(s) in Your Portfolio
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Domain</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Registrar</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Expiration</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($existingDomains as $domain): ?>
                        <?php
                        // Display data prepared by DomainHelper in controller
                        $daysLeft = $domain['daysLeft'];
                        $expiryClass = $domain['expiryClass'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <a href="/domains/<?= $domain['id'] ?>" class="text-sm font-semibold text-primary hover:text-primary-dark">
                                    <?= htmlspecialchars($domain['domain_name']) ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?= htmlspecialchars($domain['registrar'] ?? 'Unknown') ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (!empty($domain['expiration_date'])): ?>
                                    <div class="text-sm">
                                        <div class="font-medium text-gray-900"><?= $domain['expiration_date'] ? date('M d, Y', strtotime($domain['expiration_date'])) : 'Unknown' ?></div>
                                        <div class="text-xs <?= $expiryClass ?>">
                                            <?= $daysLeft ?> days
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $statusClass = $domain['status'] === 'active' 
                                    ? 'bg-green-100 text-green-800' 
                                    : 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>">
                                    <?= ucfirst($domain['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="/domains/<?= $domain['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    View Details →
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
            function paginationUrl($page, $query, $perPage) {
                return '/search?q=' . urlencode($query) . '&page=' . $page . '&per_page=' . $perPage;
            }
            ?>
            
            <!-- First Page -->
            <?php if ($currentPage > 1): ?>
                <a href="<?= paginationUrl(1, $query, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                    <i class="fas fa-angle-double-left"></i>
                </a>
            <?php endif; ?>
            
            <!-- Previous Page -->
            <?php if ($currentPage > 1): ?>
                <a href="<?= paginationUrl($currentPage - 1, $query, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
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
                echo '<a href="' . paginationUrl(1, $query, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">1</a>';
                if ($start > 2) {
                    echo '<span class="px-2 text-gray-500">...</span>';
                }
            }
            
            // Page numbers
            for ($i = $start; $i <= $end; $i++) {
                if ($i == $currentPage) {
                    echo '<span class="px-3 py-2 text-sm bg-primary text-white rounded-lg font-semibold">' . $i . '</span>';
                } else {
                    echo '<a href="' . paginationUrl($i, $query, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $i . '</a>';
                }
            }
            
            // Show last page + ellipsis if needed
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) {
                    echo '<span class="px-2 text-gray-500">...</span>';
                }
                echo '<a href="' . paginationUrl($totalPages, $query, $pagination['per_page']) . '" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">' . $totalPages . '</a>';
            }
            ?>
            
            <!-- Next Page -->
            <?php if ($currentPage < $totalPages): ?>
                <a href="<?= paginationUrl($currentPage + 1, $query, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                    Next <i class="fas fa-angle-right"></i>
                </a>
            <?php endif; ?>
            
            <!-- Last Page -->
            <?php if ($currentPage < $totalPages): ?>
                <a href="<?= paginationUrl($totalPages, $query, $pagination['per_page']) ?>" class="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isDomainLike && $pagination['total'] == 0): ?>
    <!-- WHOIS Lookup Results -->
    <?php if ($whoisData): ?>
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
            <div class="px-6 py-4 border-b border-gray-200 bg-blue-50">
                <h2 class="text-lg font-semibold text-blue-900 flex items-center">
                    <i class="fas fa-search text-blue-600 mr-2"></i>
                    WHOIS Lookup Results
                </h2>
                <p class="text-sm text-blue-700 mt-1">Domain not found in your portfolio - showing WHOIS information</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Domain</label>
                        <p class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($whoisData['domain']) ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Registrar</label>
                        <p class="text-lg text-gray-900"><?= htmlspecialchars($whoisData['registrar']) ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Expiration Date</label>
                        <p class="text-lg text-gray-900">
                            <?= $whoisData['expiration_date'] ? date('M d, Y', strtotime($whoisData['expiration_date'])) : 'N/A' ?>
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Creation Date</label>
                        <p class="text-lg text-gray-900">
                            <?= $whoisData['creation_date'] ? date('M d, Y', strtotime($whoisData['creation_date'])) : 'N/A' ?>
                        </p>
                    </div>
                    <?php if (!empty($whoisData['nameservers'])): ?>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-600 mb-2">Nameservers</label>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($whoisData['nameservers'] as $ns): ?>
                                <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded text-sm font-mono"><?= htmlspecialchars($ns) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Add Domain Button -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <form method="POST" action="/domains/store" class="flex items-center justify-between">
                        <input type="hidden" name="domain_name" value="<?= htmlspecialchars($whoisData['domain']) ?>">
                        <p class="text-sm text-gray-600">Want to monitor this domain?</p>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>
                            Add to Portfolio
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php elseif ($whoisError): ?>
        <!-- WHOIS Error -->
        <div class="bg-red-50 border border-red-200 rounded-lg p-6">
            <div class="flex items-start">
                <i class="fas fa-exclamation-circle text-red-500 text-xl mr-3 mt-0.5"></i>
                <div>
                    <h3 class="text-sm font-semibold text-red-900">WHOIS Lookup Failed</h3>
                    <p class="text-sm text-red-700 mt-1"><?= htmlspecialchars($whoisError) ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($pagination['total'] == 0 && !$isDomainLike): ?>
    <!-- No Results -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-yellow-500 text-xl mr-3 mt-0.5"></i>
            <div>
                <h3 class="text-sm font-semibold text-yellow-900">No Results Found</h3>
                <p class="text-sm text-yellow-700 mt-1">
                    No domains match your search. Try a different search term or enter a domain name to perform a WHOIS lookup.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>

