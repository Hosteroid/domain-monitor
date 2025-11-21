<?php
$title = 'WHOIS Debug Tool';
$pageTitle = 'WHOIS Debug Tool';
$pageDescription = 'Test and debug WHOIS data extraction';
$pageIcon = 'fas fa-search';
ob_start();
?>

<?php if (empty($domain)): ?>
    <!-- Search Form -->
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <form method="GET" action="/debug/whois" class="space-y-4">
                <div>
                    <label for="domain" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Domain Name
                    </label>
                    <input type="text" 
                           id="domain" 
                           name="domain" 
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                           placeholder="Enter domain (e.g., google.com)"
                           required
                           autofocus>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Enter a domain name without http:// or www.
                    </p>
                </div>

                <button type="submit" 
                        class="w-full inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                    <i class="fas fa-search mr-2"></i>
                    Check WHOIS
                </button>
            </form>
        </div>

        <!-- Info Card -->
        <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-500 text-lg"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">What is this tool?</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        This debug tool shows you the raw WHOIS data for any domain and how our system parses it. 
                        Use it to troubleshoot issues with domain information extraction.
                    </p>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Back Button & Copy Report -->
    <div class="mb-4 flex justify-between items-center">
        <a href="/debug/whois" class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
            <i class="fas fa-arrow-left mr-2"></i>
            Check Another Domain
        </a>
        <button onclick="copyDebugReport(this)" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-medium">
            <i class="fas fa-copy mr-2"></i>
            Copy Debug Report
        </button>
    </div>

    <!-- Domain Info Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Domain</p>
                <p class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars($domain) ?></p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">WHOIS Server</p>
                <p class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars($server) ?></p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">TLD</p>
                <p class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars($tld) ?></p>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Parsed Data -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200 bg-green-50">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-2 text-sm"></i>
                    Extracted Data (What We Save)
                </h2>
            </div>
            <div class="p-5">
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Domain</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['domain'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Registrar</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['registrar'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Expiration Date</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['expiration_date'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Creation Date</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['creation_date'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Updated Date</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['updated_date'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Registrar URL</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['registrar_url'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-xs font-medium text-gray-600">Abuse Email</span>
                        <span class="text-xs text-gray-900 font-mono"><?= htmlspecialchars($info['abuse_email'] ?? 'N/A') ?></span>
                    </div>
                    <div class="py-2">
                        <span class="text-xs font-medium text-gray-600 block mb-2">Nameservers</span>
                        <div class="space-y-1">
                            <?php if (!empty($info['nameservers'])): ?>
                                <?php foreach ($info['nameservers'] as $ns): ?>
                                    <div class="text-xs text-gray-900 font-mono bg-gray-50 px-2 py-1 rounded"><?= htmlspecialchars($ns) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-xs text-gray-400">N/A</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key-Value Pairs -->
        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200 bg-blue-50">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-table text-blue-600 mr-2 text-sm"></i>
                    All Key-Value Pairs
                </h2>
            </div>
            <div class="overflow-y-auto" style="max-height: 500px;">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Key</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Value</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php foreach ($parsedData as $item): ?>
                            <?php if (!empty($item['value'])): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-xs font-medium text-gray-700"><?= htmlspecialchars($item['key']) ?></td>
                                    <td class="px-4 py-2 text-xs text-gray-900 font-mono"><?= htmlspecialchars($item['value']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Raw Response -->
    <div class="mt-4 bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
            <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                <i class="fas fa-file-code text-gray-600 mr-2 text-sm"></i>
                Raw WHOIS Response
            </h2>
        </div>
        <div class="p-5">
            <pre class="text-xs font-mono bg-gray-50 p-4 rounded border border-gray-200 overflow-x-auto"><?= htmlspecialchars($response) ?></pre>
        </div>
    </div>

    <!-- Hidden data for JS -->
    <script id="debug-data" type="application/json">
    {
        "domain": <?= json_encode($domain) ?>,
        "tld": <?= json_encode($tld) ?>,
        "server": <?= json_encode($server) ?>,
        "extractedData": <?= json_encode($info) ?>,
        "rawResponse": <?= json_encode($response) ?>,
        "parsedKeyValuePairs": <?= json_encode($parsedData) ?>
    }
    </script>

    <script>
    function copyDebugReport(button) {
        const data = JSON.parse(document.getElementById('debug-data').textContent);
        
        let report = `=== WHOIS DEBUG REPORT ===\n\n`;
        report += `Domain: ${data.domain}\n`;
        report += `TLD: ${data.tld}\n`;
        report += `WHOIS Server: ${data.server}\n`;
        report += `Date: ${new Date().toISOString()}\n\n`;
        
        report += `--- EXTRACTED DATA (What We Save) ---\n`;
        report += `Domain: ${data.extractedData.domain || 'N/A'}\n`;
        report += `Registrar: ${data.extractedData.registrar || 'N/A'}\n`;
        report += `Registrar URL: ${data.extractedData.registrar_url || 'N/A'}\n`;
        report += `Expiration Date: ${data.extractedData.expiration_date || 'N/A'}\n`;
        report += `Creation Date: ${data.extractedData.creation_date || 'N/A'}\n`;
        report += `Updated Date: ${data.extractedData.updated_date || 'N/A'}\n`;
        report += `Abuse Email: ${data.extractedData.abuse_email || 'N/A'}\n`;
        report += `Nameservers: ${data.extractedData.nameservers && data.extractedData.nameservers.length > 0 ? data.extractedData.nameservers.join(', ') : 'N/A'}\n`;
        report += `Status: ${data.extractedData.status && data.extractedData.status.length > 0 ? data.extractedData.status.join(', ') : 'N/A'}\n\n`;
        
        report += `--- ALL KEY-VALUE PAIRS ---\n`;
        if (data.parsedKeyValuePairs && data.parsedKeyValuePairs.length > 0) {
            data.parsedKeyValuePairs.forEach(item => {
                if (item.value) {
                    report += `${item.key}: ${item.value}\n`;
                }
            });
        } else {
            report += 'No key-value pairs found\n';
        }
        
        report += `\n--- RAW WHOIS RESPONSE ---\n`;
        report += data.rawResponse;
        report += `\n\n=== END OF REPORT ===`;
        
        // Copy to clipboard with fallback
        copyToClipboard(report, button);
    }

    // Robust clipboard copy function with fallback
    function copyToClipboard(text, button) {
        // Try modern clipboard API first
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showCopySuccess(button);
            }).catch(err => {
                console.error('Modern clipboard API failed:', err);
                // Fallback to legacy method
                fallbackCopyTextToClipboard(text, button);
            });
        } else {
            // Use fallback for non-HTTPS or older browsers
            fallbackCopyTextToClipboard(text, button);
        }
    }

    function fallbackCopyTextToClipboard(text, button) {
        // Create a temporary textarea
        const textArea = document.createElement('textarea');
        textArea.value = text;
        
        // Make it invisible but accessible
        textArea.style.position = 'fixed';
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.width = '2em';
        textArea.style.height = '2em';
        textArea.style.padding = '0';
        textArea.style.border = 'none';
        textArea.style.outline = 'none';
        textArea.style.boxShadow = 'none';
        textArea.style.background = 'transparent';
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess(button);
            } else {
                showCopyError(button);
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            showCopyError(button);
        }
        
        document.body.removeChild(textArea);
    }

    function showCopySuccess(button) {
        if (!button) return;
        
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
        button.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        button.classList.add('bg-green-600', 'hover:bg-green-700');
        
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('bg-green-600', 'hover:bg-green-700');
            button.classList.add('bg-blue-600', 'hover:bg-blue-700');
        }, 2000);
    }

    function showCopyError(button) {
        alert('Failed to copy to clipboard.\n\nYour browser may not support this feature, or the site needs HTTPS.\n\nPlease manually select and copy the text from the Raw WHOIS Response section below.');
    }
    </script>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>

