<?php
$title = 'TLD Details';
$pageTitle = htmlspecialchars($tld['tld']);
$pageDescription = 'TLD registry information and server details';
$pageIcon = 'fas fa-globe';
ob_start();
?>

<!-- Top Action Bar -->
<div class="mb-3 flex flex-wrap gap-2 justify-between items-center">
    <div class="flex gap-2">
        <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold bg-primary text-white">
            <i class="fas fa-globe mr-1.5"></i>
            TLD Registry
        </span>
        <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold <?= $tld['is_active'] ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-gray-100 text-gray-700 border border-gray-200' ?>">
            <i class="fas <?= $tld['is_active'] ? 'fa-check-circle' : 'fa-pause-circle' ?> mr-1.5"></i>
            <?= $tld['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </div>
    <div class="flex gap-2 items-center">
        <a href="/tld-registry/<?= $tld['id'] ?>/refresh" class="inline-flex items-center justify-center px-3 py-2 bg-green-600 text-white text-xs rounded-lg hover:bg-green-700 transition-colors font-medium min-w-[80px] h-[32px]" onclick="return confirm('Refresh TLD data from IANA?')">
            <i class="fas fa-sync-alt mr-1.5"></i>
            Refresh
        </a>
        <a href="/tld-registry/<?= $tld['id'] ?>/toggle-active" class="inline-flex items-center justify-center px-3 py-2 bg-orange-600 text-white text-xs rounded-lg hover:bg-orange-700 transition-colors font-medium min-w-[80px] h-[32px]" onclick="return confirm('Toggle TLD status?')">
            <i class="fas fa-power-off mr-1.5"></i>
            Toggle
        </a>
        <a href="/tld-registry" class="inline-flex items-center justify-center px-3 py-2 border border-gray-300 text-gray-700 text-xs rounded-lg hover:bg-gray-50 transition-colors font-medium min-w-[80px] h-[32px]">
            <i class="fas fa-arrow-left mr-1.5"></i>
            Back
        </a>
    </div>
</div>

<!-- Main 2-Column Layout -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
    
    <!-- LEFT COLUMN -->
    <div class="space-y-3">
        
        <!-- TLD Information -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center">
                    <i class="fas fa-info-circle text-gray-400 mr-2" style="font-size: 10px;"></i>
                    TLD Information
                </h3>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                    <div>
                        <label class="text-gray-500 font-medium block mb-0.5">TLD</label>
                        <p class="text-gray-900 font-semibold"><?= htmlspecialchars($tld['tld']) ?></p>
                    </div>
                    <?php if ($tld['registry_url']): ?>
                    <div>
                        <label class="text-gray-500 font-medium block mb-0.5">Registry URL</label>
                        <a href="<?= htmlspecialchars($tld['registry_url']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 flex items-center">
                            <i class="fas fa-external-link-alt mr-1" style="font-size: 9px;"></i>
                            Visit Registry
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($tld['registration_date']): ?>
                    <div>
                        <label class="text-gray-500 font-medium block mb-0.5">Registration Date</label>
                        <p class="text-gray-900"><?= date('M j, Y', strtotime($tld['registration_date'])) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($tld['record_last_updated']): ?>
                    <div>
                        <label class="text-gray-500 font-medium block mb-0.5">Record Last Updated</label>
                        <p class="text-gray-900"><?= date('M j, Y', strtotime($tld['record_last_updated'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RDAP Servers -->
        <?php if ($tld['rdap_servers']): ?>
            <?php 
            $rdapServers = json_decode($tld['rdap_servers'], true);
            if (is_array($rdapServers) && !empty($rdapServers)):
            ?>
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center">
                    <i class="fas fa-database text-gray-400 mr-2" style="font-size: 10px;"></i>
                    RDAP Servers (<?= count($rdapServers) ?>)
                </h3>
            </div>
            <div class="p-4">
                <div class="space-y-1.5">
                    <?php foreach ($rdapServers as $index => $server): ?>
                    <div class="flex items-center p-2 bg-gray-50 rounded hover:bg-gray-100 transition-colors">
                        <div class="w-6 h-6 bg-purple-500 rounded flex items-center justify-center text-white font-bold text-xs mr-2">
                            <?= $index + 1 ?>
                        </div>
                        <p class="font-mono text-xs text-gray-800"><?= htmlspecialchars($server) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- WHOIS Server -->
        <?php if ($tld['whois_server']): ?>
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center">
                    <i class="fas fa-server text-gray-400 mr-2" style="font-size: 10px;"></i>
                    WHOIS Server
                </h3>
            </div>
            <div class="p-4">
                <div class="flex items-center p-2 bg-gray-50 rounded">
                    <div class="w-6 h-6 bg-orange-500 rounded flex items-center justify-center text-white font-bold text-xs mr-2">
                        <i class="fas fa-server"></i>
                    </div>
                    <p class="font-mono text-xs text-gray-800"><?= htmlspecialchars($tld['whois_server']) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="space-y-3">
        
        <!-- Import History -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center">
                    <i class="fas fa-history text-gray-400 mr-2" style="font-size: 10px;"></i>
                    Import History
                </h3>
            </div>
            <div class="p-4">
                <div class="space-y-2">
                    <div class="flex items-center p-2 bg-blue-50 rounded border border-blue-200">
                        <div class="w-7 h-7 bg-blue-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-plus text-white text-xs"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-600 font-medium">Created</p>
                            <p class="text-xs font-semibold text-gray-900"><?= date('M j, Y H:i', strtotime($tld['created_at'])) ?></p>
                        </div>
                    </div>

                    <?php if ($tld['updated_at']): ?>
                    <div class="flex items-center p-2 bg-green-50 rounded border border-green-200">
                        <div class="w-7 h-7 bg-green-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-sync text-white text-xs"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-600 font-medium">Last Updated</p>
                            <p class="text-xs font-semibold text-gray-900"><?= date('M j, Y H:i', strtotime($tld['updated_at'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($tld['iana_publication_date']): ?>
                    <div class="flex items-center p-2 bg-purple-50 rounded border border-purple-200">
                        <div class="w-7 h-7 bg-purple-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-calendar text-white text-xs"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-600 font-medium">IANA Publication</p>
                            <p class="text-xs font-semibold text-gray-900"><?= date('M j, Y H:i', strtotime($tld['iana_publication_date'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-200 bg-gray-50">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center">
                    <i class="fas fa-bolt text-gray-400 mr-2" style="font-size: 10px;"></i>
                    Quick Actions
                </h3>
            </div>
            <div class="p-4 space-y-2">
                <a href="/tld-registry/<?= $tld['id'] ?>/refresh" class="flex items-center p-3 border border-gray-200 hover:border-green-500 hover:bg-green-50 rounded-lg transition-all duration-200 group" onclick="return confirm('Refresh TLD data from IANA?')">
                    <div class="w-9 h-9 bg-green-50 group-hover:bg-green-500 rounded-lg flex items-center justify-center group-hover:text-white text-green-600 transition-colors duration-200">
                        <i class="fas fa-sync-alt text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-green-700">Refresh from IANA</span>
                </a>
                <a href="/tld-registry/<?= $tld['id'] ?>/toggle-active" class="flex items-center p-3 border border-gray-200 hover:border-orange-500 hover:bg-orange-50 rounded-lg transition-all duration-200 group" onclick="return confirm('Toggle TLD status?')">
                    <div class="w-9 h-9 bg-orange-50 group-hover:bg-orange-500 rounded-lg flex items-center justify-center group-hover:text-white text-orange-600 transition-colors duration-200">
                        <i class="fas fa-power-off text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-orange-700">Toggle Status</span>
                </a>
                <?php if ($tld['registry_url']): ?>
                <a href="<?= htmlspecialchars($tld['registry_url']) ?>" target="_blank" class="flex items-center p-3 border border-gray-200 hover:border-blue-500 hover:bg-blue-50 rounded-lg transition-all duration-200 group">
                    <div class="w-9 h-9 bg-blue-50 group-hover:bg-blue-500 rounded-lg flex items-center justify-center group-hover:text-white text-blue-600 transition-colors duration-200">
                        <i class="fas fa-external-link-alt text-sm"></i>
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-700 group-hover:text-blue-700">Visit Registry</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Raw Data (Collapsible) -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <button onclick="toggleRawData()" class="w-full px-4 py-2 border-b border-gray-200 bg-gray-50 text-left hover:bg-gray-100 transition-colors">
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider flex items-center justify-between">
                    <span class="flex items-center">
                        <i class="fas fa-code text-gray-400 mr-2" style="font-size: 10px;"></i>
                        Raw TLD Data
                    </span>
                    <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform" id="raw-data-chevron"></i>
                </h3>
            </button>
            <div id="raw-data" class="hidden p-4 bg-gray-900 max-h-64 overflow-y-auto">
                <pre class="text-xs text-green-400 font-mono"><?= htmlspecialchars(json_encode([
                    'tld' => $tld['tld'],
                    'rdap_servers' => $tld['rdap_servers'] ? json_decode($tld['rdap_servers'], true) : null,
                    'whois_server' => $tld['whois_server'],
                    'registry_url' => $tld['registry_url'],
                    'registration_date' => $tld['registration_date'],
                    'record_last_updated' => $tld['record_last_updated'],
                    'iana_publication_date' => $tld['iana_publication_date'],
                    'is_active' => $tld['is_active'],
                    'created_at' => $tld['created_at'],
                    'updated_at' => $tld['updated_at']
                ], JSON_PRETTY_PRINT)) ?></pre>
            </div>
        </div>

    </div>

</div>

<script>
function toggleRawData() {
    const dataDiv = document.getElementById('raw-data');
    const chevron = document.getElementById('raw-data-chevron');
    dataDiv.classList.toggle('hidden');
    chevron.classList.toggle('rotate-180');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>