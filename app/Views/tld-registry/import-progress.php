<?php
$title = $title ?? 'Import Progress';
$pageTitle = $title;
$pageDescription = 'Progressive data import with real-time progress';
$pageIcon = 'fas fa-tasks';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($title) ?></h1>
                <p class="text-gray-600 mt-1">
                    <?php
                    $descriptions = [
                        'tld_list' => 'Importing complete TLD list from IANA',
                        'rdap' => 'Importing RDAP servers for existing TLDs',
                        'whois' => 'Importing WHOIS & Registry data via RDAP API (with HTML fallback)',
                        'check_updates' => 'Checking for IANA updates',
                        'complete_workflow' => 'Complete TLD import workflow (TLD List → RDAP → WHOIS & Registry Data)'
                    ];
                    echo $descriptions[$import_type] ?? 'Processing import';
                    ?>
                </p>
            </div>
            <a href="/tld-registry" class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to TLD Registry
            </a>
        </div>
    </div>

    <!-- Progress Card -->
    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Import Status</h2>
            <div id="status-badge" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                <i class="fas fa-clock mr-2"></i>
                <span id="status-text">Starting...</span>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="mb-4">
            <div class="flex justify-between text-sm text-gray-600 mb-2">
                <span id="progress-text">0 of 0 items processed</span>
                <span id="percentage-text">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3">
                <div id="progress-bar" class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>

        <!-- Step Progress (for complete workflow) -->
        <div id="step-progress" class="mb-4" style="display: none;">
            <div class="text-sm text-gray-600 mb-2">Workflow Steps:</div>
            <div class="grid grid-cols-3 gap-2">
                <div class="step-item bg-gray-100 rounded-lg p-2 text-center">
                    <div class="text-xs font-medium text-gray-600">Step 1</div>
                    <div class="text-xs text-gray-500">TLD List</div>
                    <div id="step-1-status" class="text-xs text-gray-400">Pending</div>
                </div>
                <div class="step-item bg-gray-100 rounded-lg p-2 text-center">
                    <div class="text-xs font-medium text-gray-600">Step 2</div>
                    <div class="text-xs text-gray-500">RDAP</div>
                    <div id="step-2-status" class="text-xs text-gray-400">Pending</div>
                </div>
                <div class="step-item bg-gray-100 rounded-lg p-2 text-center">
                    <div class="text-xs font-medium text-gray-600">Step 3</div>
                    <div class="text-xs text-gray-500">WHOIS & Registry</div>
                    <div id="step-3-status" class="text-xs text-gray-400">Pending</div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-list text-blue-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Total</p>
                        <p id="total-count" class="text-xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-check text-green-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Processed</p>
                        <p id="processed-count" class="text-xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-times text-red-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Failed</p>
                        <p id="failed-count" class="text-xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-hourglass-half text-orange-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Remaining</p>
                        <p id="remaining-count" class="text-xl font-semibold text-gray-900">0</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Output -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Import Log</h3>
        <div id="log-output" class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-sm h-64 overflow-y-auto">
            <div class="text-gray-500">Initializing import process...</div>
        </div>
    </div>
</div>

<script>
let logId = <?= json_encode($log_id) ?>;
let importType = <?= json_encode($import_type) ?>;
let isComplete = false;
let totalProcessed = 0;
let totalFailed = 0;

// Show step progress for complete workflow
if (importType === 'complete_workflow') {
    document.getElementById('step-progress').style.display = 'block';
}

function addLogMessage(message, type = 'info') {
    const logOutput = document.getElementById('log-output');
    const timestamp = new Date().toLocaleTimeString();
    const colorClass = type === 'error' ? 'text-red-400' : type === 'success' ? 'text-green-400' : 'text-blue-400';
    
    const logEntry = document.createElement('div');
    logEntry.className = colorClass;
    logEntry.innerHTML = `[${timestamp}] ${message}`;
    
    logOutput.appendChild(logEntry);
    logOutput.scrollTop = logOutput.scrollHeight;
}

function updateProgress(data) {
    const total = data.total || 0;
    const processed = data.processed || 0;
    const failed = data.failed || 0;
    const remaining = data.remaining || 0;
    
    // Update counts (use absolute values, not cumulative)
    document.getElementById('total-count').textContent = total;
    document.getElementById('processed-count').textContent = processed;
    document.getElementById('failed-count').textContent = failed;
    document.getElementById('remaining-count').textContent = remaining;
    
    // Update progress bar
    const totalToProcess = processed + remaining;
    const percentage = totalToProcess > 0 ? Math.round((processed / totalToProcess) * 100) : 0;
    
    document.getElementById('progress-bar').style.width = percentage + '%';
    document.getElementById('progress-text').textContent = `${processed} of ${totalToProcess} items processed`;
    document.getElementById('percentage-text').textContent = percentage + '%';
    
    // Update step progress for complete workflow
    if (importType === 'complete_workflow' && data.message) {
        updateStepProgress(data.message, processed, total);
    }

    // Update status
    const statusBadge = document.getElementById('status-badge');
    const statusText = document.getElementById('status-text');
    
    if (data.status === 'complete') {
        statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800';
        statusText.innerHTML = '<i class="fas fa-check mr-2"></i>Complete';
        isComplete = true;
        addLogMessage('Import completed successfully!', 'success');
        
        // Mark all steps as completed for complete workflow
        if (importType === 'complete_workflow') {
            for (let i = 1; i <= 3; i++) {
                updateStepStatus(i, 'completed');
            }
        }
    } else if (data.status === 'in_progress') {
        statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800';
        statusText.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>In Progress';
        addLogMessage(data.message || 'Processing batch...', 'info');
    } else if (data.status === 'error') {
        statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800';
        statusText.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>Error';
        addLogMessage(data.message || 'An error occurred', 'error');
        isComplete = true;
    }
}

function checkProgress() {
    if (isComplete) {
        return;
    }
    
    fetch(`/tld-registry/api/import-progress?log_id=${logId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                addLogMessage('Error: ' + data.error, 'error');
                isComplete = true;
                return;
            }
            
            updateProgress(data);
            
            if (data.status !== 'complete' && data.status !== 'error') {
                setTimeout(checkProgress, 2000); // Check again in 2 seconds
            }
        })
        .catch(error => {
            addLogMessage('Network error: ' + error.message, 'error');
            isComplete = true;
        });
}

function updateStepProgress(message, currentStep, totalSteps) {
    // Extract step number from message (handle both /3 and /4 formats)
    const stepMatch = message.match(/Step (\d+)\/(\d+)/);
    if (stepMatch) {
        const stepNumber = parseInt(stepMatch[1]);
        const totalSteps = parseInt(stepMatch[2]);
        
        // Check if this step is completed
        const isCompleted = message.toLowerCase().includes('completed');
        
        if (isCompleted) {
            // Mark all steps up to and including this one as completed
            for (let i = 1; i <= stepNumber; i++) {
                updateStepStatus(i, 'completed');
            }
            
            // Mark next step as in progress if not the last step
            if (stepNumber < totalSteps) {
                updateStepStatus(stepNumber + 1, 'in_progress');
            }
        } else {
            // Step is in progress
            // Mark previous steps as completed
            for (let i = 1; i < stepNumber; i++) {
                updateStepStatus(i, 'completed');
            }
            
            // Mark current step as in progress
            updateStepStatus(stepNumber, 'in_progress');
        }
    }
}

function updateStepStatus(stepNumber, status) {
    const stepElement = document.getElementById(`step-${stepNumber}-status`);
    const stepItem = stepElement.closest('.step-item');
    
    if (status === 'completed') {
        stepElement.textContent = 'Completed';
        stepElement.className = 'text-xs text-green-600';
        stepItem.className = 'step-item bg-green-100 rounded-lg p-2 text-center';
    } else if (status === 'in_progress') {
        stepElement.textContent = 'In Progress';
        stepElement.className = 'text-xs text-blue-600';
        stepItem.className = 'step-item bg-blue-100 rounded-lg p-2 text-center';
    } else if (status === 'failed') {
        stepElement.textContent = 'Failed';
        stepElement.className = 'text-xs text-red-600';
        stepItem.className = 'step-item bg-red-100 rounded-lg p-2 text-center';
    }
}

// Start checking progress
checkProgress();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
