<?php
$title = '2FA Backup Codes';
$pageTitle = '2FA Backup Codes';
$pageDescription = 'Save these backup codes in a safe place';
$pageIcon = 'fas fa-key';
ob_start();
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">2FA Backup Codes</h3>
            <p class="text-sm text-gray-600 mt-1">Save these codes in a safe place - they can be used to access your account if you lose your authenticator device</p>
        </div>

        <div class="p-6">
            <!-- Warning -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h4 class="text-sm font-medium text-red-800">Important Security Notice</h4>
                        <p class="text-sm text-red-700 mt-1">
                            These backup codes are shown only once. Each code can only be used once. Store them securely and never share them with anyone.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Backup Codes -->
            <div class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-md font-semibold text-gray-900">Your Backup Codes</h4>
                    <button onclick="printCodes()" class="text-sm text-primary hover:text-primary-dark">
                        <i class="fas fa-print mr-1"></i>
                        Print Codes
                    </button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3" id="backup-codes">
                    <?php foreach ($backupCodes as $index => $code): ?>
                    <div class="flex items-center justify-between bg-gray-50 p-3 rounded-lg border">
                        <code class="font-mono text-sm text-gray-900"><?= htmlspecialchars($code) ?></code>
                        <button onclick="copyCode('<?= htmlspecialchars($code) ?>', this)" 
                                class="ml-2 px-2 py-1 text-xs bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Instructions -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h4 class="text-sm font-medium text-blue-800 mb-2">How to use backup codes:</h4>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li>• When logging in, enter a backup code instead of your 2FA code</li>
                    <li>• Each backup code can only be used once</li>
                    <li>• After using a code, it will be automatically removed from your account</li>
                    <li>• If you run out of backup codes, you'll need to disable and re-enable 2FA</li>
                </ul>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                <a href="/profile" class="text-sm text-gray-600 hover:text-gray-500">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Back to Profile
                </a>
                <div class="flex space-x-3">
                    <button onclick="downloadCodes()" 
                            class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        <i class="fas fa-download mr-2"></i>
                        Download
                    </button>
                    <a href="/" 
                       class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        <i class="fas fa-check mr-2"></i>
                        I've Saved These Codes
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyCode(code, button) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(code).then(() => {
            showCopySuccess(button);
        }).catch(() => {
            fallbackCopyTextToClipboard(code);
        });
    } else {
        fallbackCopyTextToClipboard(code);
    }
}

function showCopySuccess(button) {
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check"></i>';
    button.classList.remove('bg-gray-200', 'hover:bg-gray-300', 'text-gray-700');
    button.classList.add('bg-green-500', 'text-white');
    
    setTimeout(() => {
        button.innerHTML = originalHTML;
        button.classList.remove('bg-green-500', 'text-white');
        button.classList.add('bg-gray-200', 'hover:bg-gray-300', 'text-gray-700');
    }, 2000);
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
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
        document.execCommand('copy');
        alert('Code copied to clipboard!');
    } catch (err) {
        console.error('Copy failed:', err);
        alert('Failed to copy code');
    }
    
    document.body.removeChild(textArea);
}

function printCodes() {
    const printWindow = window.open('', '_blank');
    const codes = <?= json_encode($backupCodes) ?>;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>2FA Backup Codes - <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; }
                h1 { color: #333; margin-bottom: 20px; }
                .codes { background: #f5f5f5; padding: 15px; border: 1px solid #ddd; margin: 20px 0; }
                .code { margin: 5px 0; font-family: monospace; }
                .warning { background: #fee; border: 1px solid #fcc; padding: 10px; margin: 10px 0; }
            </style>
        </head>
        <body>
            <h1>2FA Backup Codes</h1>
            <p><strong>Account:</strong> <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)</p>
            <p><strong>Generated:</strong> ${new Date().toLocaleString()}</p>
            
            <div class="warning">
                <strong>Important:</strong> Store these codes in a safe place. Each code can only be used once.
            </div>
            
            <div class="codes">
                ${codes.map((code, index) => `<div class="code">${index + 1}. ${code}</div>`).join('')}
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.print();
}

function downloadCodes() {
    const codes = <?= json_encode($backupCodes) ?>;
    const content = `2FA Backup Codes
Account: <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)
Generated: ${new Date().toLocaleString()}

IMPORTANT: Store these codes in a safe place. Each code can only be used once.

${codes.map((code, index) => `${index + 1}. ${code}`).join('\n')}

If you lose access to your authenticator app, you can use these codes to log in.
Generate new codes if you run out or if you suspect they've been compromised.`;

    const blob = new Blob([content], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = '2fa-backup-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>
