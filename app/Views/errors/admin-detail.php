<?php
$title = 'Error Details';
$pageTitle = 'Error Details';
$pageDescription = 'Detailed information about this error';
$pageIcon = 'fas fa-bug';
ob_start();

$isResolved = (bool)$error['is_resolved'];
$errorTypeShort = substr(strrchr($error['error_type'], '\\'), 1) ?: $error['error_type'];
?>

<!-- Back Navigation -->
<div class="mb-4 flex items-center justify-between">
    <a href="/errors" class="inline-flex items-center px-3 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
        <i class="fas fa-arrow-left mr-2"></i>
        Back to Error Logs
    </a>
    
    <div class="flex items-center space-x-2">
        <button onclick="copyErrorReport()" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition-colors text-sm font-medium">
            <i class="fas fa-clipboard mr-2"></i>
            Copy Error Report
        </button>
        <?php if ($isResolved): ?>
            <form method="POST" action="/errors/<?= htmlspecialchars($error['error_id']) ?>/unresolve" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors text-sm font-medium">
                    <i class="fas fa-undo mr-2"></i>
                    Mark as Unresolved
                </button>
            </form>
        <?php else: ?>
            <button onclick="markResolved()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                <i class="fas fa-check mr-2"></i>
                Mark as Resolved
            </button>
        <?php endif; ?>
        
        <button onclick="deleteError()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
            <i class="fas fa-trash mr-2"></i>
            Delete Error
        </button>
    </div>
</div>

<!-- Error Header Card -->
<div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
    <div class="flex items-start justify-between mb-4">
        <div class="flex items-start">
            <div class="flex-shrink-0 h-14 w-14 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                <i class="fas fa-bug text-red-600 text-2xl"></i>
            </div>
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <h2 class="text-2xl font-semibold text-gray-900"><?= htmlspecialchars($errorTypeShort) ?></h2>
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
                </div>
                <p class="text-gray-600 mb-3"><?= htmlspecialchars($error['error_message']) ?></p>
                <div class="flex items-center gap-4 text-sm text-gray-500">
                    <div class="flex items-center">
                        <i class="fas fa-hashtag mr-1.5"></i>
                        <span class="font-mono font-semibold text-primary"><?= htmlspecialchars($error['error_id']) ?></span>
                        <button onclick="copyToClipboard('<?= htmlspecialchars($error['error_id']) ?>')" class="ml-2 text-gray-400 hover:text-primary" title="Copy Error ID">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-redo mr-1.5"></i>
                        <span><?= $error['occurrences'] ?? 1 ?> occurrence<?= ($error['occurrences'] ?? 1) != 1 ? 's' : '' ?></span>
                    </div>
                    <div class="flex items-center">
                        <i class="far fa-clock mr-1.5"></i>
                        <span>Last: <?= date('M d, Y H:i:s', strtotime($error['last_occurred_at'] ?? $error['occurred_at'])) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Location Info -->
    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">File</p>
                <p class="font-mono text-sm text-gray-900 break-all"><?= htmlspecialchars($error['error_file']) ?></p>
            </div>
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Line</p>
                <p class="font-mono text-sm text-gray-900"><?= $error['error_line'] ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Resolution Info (if resolved) -->
<?php if ($isResolved && $error['resolved_at']): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
    <div class="flex items-start">
        <i class="fas fa-check-circle text-green-600 mt-0.5 mr-3"></i>
        <div class="flex-1">
            <h3 class="text-sm font-semibold text-green-900 mb-2">Resolved</h3>
            <div class="text-sm text-green-800 space-y-1">
                <p><strong>Date:</strong> <?= date('M d, Y H:i:s', strtotime($error['resolved_at'])) ?></p>
                <?php if (!empty($error['notes'])): ?>
                    <p><strong>Notes:</strong> <?= htmlspecialchars($error['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex">
            <button onclick="switchTab('stack-trace')" id="tab-stack-trace" class="tab-button active px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">
                <i class="fas fa-layer-group mr-2"></i>
                Stack Trace
            </button>
            <button onclick="switchTab('request')" id="tab-request" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                <i class="fas fa-exchange-alt mr-2"></i>
                Request Data
            </button>
            <button onclick="switchTab('session')" id="tab-session" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                <i class="fas fa-user mr-2"></i>
                Session Data
            </button>
            <button onclick="switchTab('occurrences')" id="tab-occurrences" class="tab-button px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                <i class="fas fa-history mr-2"></i>
                Occurrence Details (<?= $error['occurrences'] ?? 1 ?>)
            </button>
        </nav>
    </div>

    <!-- Tab Content -->
    <div class="p-6">
        <!-- Stack Trace Tab -->
        <div id="content-stack-trace" class="tab-content">
            <?php if (!empty($error['stack_trace_array'])): ?>
                <div class="space-y-2">
                    <?php foreach ($error['stack_trace_array'] as $index => $trace): ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 hover:border-primary transition-colors">
                            <div class="flex items-start">
                                <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-semibold text-sm mr-3">
                                    <?= $index ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <?php if (isset($trace['file'])): ?>
                                        <p class="font-mono text-xs text-gray-600 break-all mb-1">
                                            <?= htmlspecialchars($trace['file']) ?> 
                                            <span class="text-primary font-semibold">line <?= $trace['line'] ?? '?' ?></span>
                                        </p>
                                    <?php endif; ?>
                                    <?php if (isset($trace['function'])): ?>
                                        <p class="font-mono text-sm text-gray-900">
                                            <?php if (isset($trace['class'])): ?>
                                                <span class="text-blue-600"><?= htmlspecialchars($trace['class']) ?></span><?= htmlspecialchars($trace['type']) ?>
                                            <?php endif; ?>
                                            <span class="text-indigo-600"><?= htmlspecialchars($trace['function']) ?></span>()
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-500 text-center py-8">No stack trace available</p>
            <?php endif; ?>
        </div>

        <!-- Request Data Tab -->
        <div id="content-request" class="tab-content hidden">
            <?php if (!empty($error['request_data'])): ?>
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Request Info</h3>
                        <div class="bg-gray-50 rounded-lg p-4 font-mono text-xs">
                            <p><strong>Method:</strong> <?= htmlspecialchars($error['request_method']) ?></p>
                            <p><strong>URI:</strong> <?= htmlspecialchars($error['request_uri']) ?></p>
                            <p><strong>IP:</strong> <?= htmlspecialchars($error['ip_address']) ?></p>
                            <p><strong>User Agent:</strong> <?= htmlspecialchars($error['user_agent']) ?></p>
                        </div>
                    </div>
                    <?php foreach ($error['request_data'] as $key => $value): ?>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700 mb-2"><?= htmlspecialchars(strtoupper($key)) ?></h3>
                            <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-xs"><?= htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT)) ?></pre>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-500 text-center py-8">No request data available</p>
            <?php endif; ?>
        </div>

        <!-- Session Data Tab -->
        <div id="content-session" class="tab-content hidden">
            <?php if (!empty($error['session_data'])): ?>
                <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-xs"><?= htmlspecialchars(json_encode($error['session_data'], JSON_PRETTY_PRINT)) ?></pre>
            <?php else: ?>
                <p class="text-gray-500 text-center py-8">No session data available</p>
            <?php endif; ?>
        </div>

        <!-- Occurrences Tab -->
        <div id="content-occurrences" class="tab-content hidden">
            <div class="space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-3"></i>
                        <div>
                            <p class="text-sm font-semibold text-blue-900 mb-1">Error Occurrence Tracking</p>
                            <p class="text-sm text-blue-800">
                                This error has occurred <strong><?= $error['occurrences'] ?? 1 ?> time<?= ($error['occurrences'] ?? 1) != 1 ? 's' : '' ?></strong>.
                                Similar errors are automatically grouped together and the occurrence count is incremented.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Occurrence Information</h3>
                    <div class="space-y-3">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">First Occurred</p>
                            <p class="text-sm text-gray-900"><?= date('M d, Y H:i:s', strtotime($error['occurred_at'])) ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Last Occurred</p>
                            <p class="text-sm text-gray-900"><?= date('M d, Y H:i:s', strtotime($error['last_occurred_at'] ?? $error['occurred_at'])) ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Occurrences</p>
                            <p class="text-sm text-gray-900"><?= $error['occurrences'] ?? 1 ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Request Details</p>
                            <p class="text-xs text-gray-600">
                                <?= htmlspecialchars($error['request_method']) ?> 
                                <?= htmlspecialchars($error['request_uri']) ?>
                            </p>
                            <p class="text-xs text-gray-600 mt-1">
                                IP: <?= htmlspecialchars($error['ip_address']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- System Information -->
<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">System Information</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">PHP Version</p>
            <p class="text-sm text-gray-900"><?= htmlspecialchars($error['php_version']) ?></p>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Memory Usage</p>
            <p class="text-sm text-gray-900"><?= htmlspecialchars($error['memory_usage']) ?></p>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">First Occurred</p>
            <p class="text-sm text-gray-900"><?= date('M d, Y H:i:s', strtotime($error['occurred_at'])) ?></p>
        </div>
    </div>
</div>

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
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-primary', 'text-primary');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('active', 'border-primary', 'text-primary');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
}

function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopySuccess();
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.select();
    try {
        document.execCommand('copy');
        showCopySuccess();
    } catch (err) {
        console.error('Copy failed:', err);
    }
    document.body.removeChild(textArea);
}

function copyErrorReport() {
    const errorType = <?= json_encode($error['error_type'] ?? 'Error') ?>;
    const errorMessage = <?= json_encode($error['error_message'] ?? 'Unknown error') ?>;
    const errorFile = <?= json_encode($error['error_file'] ?? 'Unknown') ?>;
    const errorLine = <?= json_encode($error['error_line'] ?? '?') ?>;
    const errorId = <?= json_encode($error['error_id'] ?? 'N/A') ?>;
    const phpVersion = <?= json_encode($error['php_version'] ?? 'Unknown') ?>;
    const memoryUsage = <?= json_encode($error['memory_usage'] ?? 'Unknown') ?>;
    const requestMethod = <?= json_encode($error['request_method'] ?? 'GET') ?>;
    const requestUri = <?= json_encode($error['request_uri'] ?? '/') ?>;
    const userAgent = <?= json_encode($error['user_agent'] ?? 'Unknown') ?>;
    const ipAddress = <?= json_encode($error['ip_address'] ?? 'Unknown') ?>;
    const occurredAt = <?= json_encode(date('Y-m-d H:i:s', strtotime($error['occurred_at']))) ?>;
    const lastOccurredAt = <?= json_encode(date('Y-m-d H:i:s', strtotime($error['last_occurred_at'] ?? $error['occurred_at']))) ?>;
    const occurrences = <?= json_encode($error['occurrences'] ?? 1) ?>;
    const isResolved = <?= json_encode($isResolved) ?>;
    const requestData = <?= json_encode($error['request_data'] ?? null) ?>;
    const sessionData = <?= json_encode($error['session_data'] ?? null) ?>;

    // Get stack trace from the rendered elements
    const traceFrames = document.querySelectorAll('#content-stack-trace .bg-gray-50');
    let stackTrace = 'Not available';
    if (traceFrames.length > 0) {
        let traceLines = [];
        traceFrames.forEach((frame, i) => {
            const fileLine = frame.querySelector('.font-mono.text-xs');
            const funcLine = frame.querySelector('.font-mono.text-sm');
            let line = '#' + i + ' ';
            if (fileLine) line += fileLine.textContent.trim().replace(/\s+/g, ' ');
            if (funcLine) line += ' ' + funcLine.textContent.trim().replace(/\s+/g, '');
            traceLines.push(line);
        });
        stackTrace = traceLines.join('\n');
    }

    // Format request data sections
    let requestDataText = 'Not available';
    if (requestData && typeof requestData === 'object' && Object.keys(requestData).length > 0) {
        let sections = [];
        for (const [key, value] of Object.entries(requestData)) {
            sections.push(`  [${key.toUpperCase()}]\n  ${JSON.stringify(value, null, 2).split('\n').join('\n  ')}`);
        }
        requestDataText = sections.join('\n\n');
    }

    // Format session data
    let sessionDataText = 'Not available';
    if (sessionData && typeof sessionData === 'object' && Object.keys(sessionData).length > 0) {
        sessionDataText = '  ' + JSON.stringify(sessionData, null, 2).split('\n').join('\n  ');
    }

    const errorReport = `=== DOMAIN MONITOR ERROR REPORT ===

ERROR INFORMATION:
- Error ID: ${errorId}
- Type: ${errorType}
- Message: ${errorMessage}
- Status: ${isResolved ? 'Resolved' : 'Unresolved'}
- Occurrences: ${occurrences}

LOCATION:
- File: ${errorFile}
- Line: ${errorLine}

REQUEST DETAILS:
- Method: ${requestMethod}
- URI: ${requestUri}
- IP Address: ${ipAddress}
- User Agent: ${userAgent}
- First Occurred: ${occurredAt}
- Last Occurred: ${lastOccurredAt}

REQUEST DATA:
${requestDataText}

SESSION DATA:
${sessionDataText}

SYSTEM INFORMATION:
- PHP Version: ${phpVersion}
- Memory Usage: ${memoryUsage}

STACK TRACE:
${stackTrace}

=== END OF ERROR REPORT ===

Reference ID: ${errorId}
Please include this report when reporting bugs.`;

    copyToClipboard(errorReport);
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

function markResolved() {
    document.getElementById('resolutionModal').classList.remove('hidden');
}

function closeResolutionModal() {
    document.getElementById('resolutionModal').classList.add('hidden');
    document.getElementById('resolutionNotes').value = '';
}

function submitResolution() {
    const notes = document.getElementById('resolutionNotes').value;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/errors/<?= htmlspecialchars($error['error_id']) ?>/resolve';
    
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

function deleteError() {
    if (!confirm('Are you sure you want to delete this error and all its occurrences? This action cannot be undone.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/errors/<?= htmlspecialchars($error['error_id']) ?>/delete';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?= csrf_token() ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>

