<?php
$title = 'Setup Two-Factor Authentication';
$pageTitle = 'Setup 2FA';
$pageDescription = 'Configure two-factor authentication for your account';
$pageIcon = 'fas fa-shield-alt';
ob_start();
?>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-shield-alt text-gray-400 mr-2 text-sm"></i>
                Setup Two-Factor Authentication
            </h2>
        </div>

        <div class="p-6 space-y-5">
            <!-- Step 1: Download Authenticator App -->
            <div class="border-l-4 border-blue-500 pl-4">
                <h4 class="text-base font-semibold text-gray-900 mb-2">Step 1: Install an Authenticator App</h4>
                <p class="text-sm text-gray-600 mb-3">Download one of these apps on your mobile device:</p>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <i class="fab fa-google text-2xl text-blue-600 mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Google Authenticator</p>
                        <p class="text-xs text-gray-500">iOS & Android</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <i class="fas fa-mobile-alt text-2xl text-blue-600 mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Authy</p>
                        <p class="text-xs text-gray-500">iOS & Android</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <i class="fab fa-microsoft text-2xl text-blue-600 mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Microsoft Authenticator</p>
                        <p class="text-xs text-gray-500">iOS & Android</p>
                    </div>
                </div>
            </div>

            <!-- Step 2: Scan QR Code -->
            <div class="border-l-4 border-green-500 pl-4">
                <h4 class="text-base font-semibold text-gray-900 mb-2">Step 2: Scan QR Code</h4>
                <p class="text-sm text-gray-600 mb-4">Open your authenticator app and scan this QR code:</p>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        <p class="text-sm text-blue-800">
                            <strong>Note:</strong> This QR code will remain the same even if you refresh the page. 
                            Once you scan it, you can enter the verification code below.
                        </p>
                    </div>
                </div>
                
                <div class="flex flex-col items-center space-y-4">
                    <div class="bg-white border-2 border-gray-200 rounded-lg p-4">
                        <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="QR Code for 2FA setup" class="w-48 h-48">
                    </div>
                    
                    <div class="text-center">
                        <p class="text-xs text-gray-500 mb-2">Can't scan? Enter this code manually:</p>
                        <div class="bg-gray-100 rounded-lg p-3 font-mono text-sm">
                            <code class="text-gray-800"><?= htmlspecialchars($secret) ?></code>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Verify Code -->
            <div class="border-l-4 border-yellow-500 pl-4">
                <h4 class="text-base font-semibold text-gray-900 mb-2">Step 3: Verify Setup</h4>
                <p class="text-sm text-gray-600 mb-4">Enter the 6-digit code from your authenticator app:</p>
                
                <form method="POST" action="/2fa/verify-setup" id="verifyForm">
                    <?= csrf_field() ?>
                    
                    <div class="max-w-xs mx-auto">
                        <input type="text" 
                               name="verification_code" 
                               id="verification_code"
                               maxlength="6" 
                               pattern="[0-9]{6}"
                               placeholder="123456"
                               class="w-full px-4 py-3 text-center text-2xl font-mono border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                               required>
                        <p class="text-xs text-gray-500 mt-2 text-center">Enter 6-digit code</p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 pt-3">
                        <button type="submit" 
                                class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                            <i class="fas fa-check mr-2"></i>
                            Verify & Enable 2FA
                        </button>
                        <a href="/2fa/cancel-setup" 
                           class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                            <i class="fas fa-times mr-2"></i>
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

            <!-- Security Notice -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                    <div>
                        <p class="text-sm font-medium text-yellow-900">Important Security Notice</p>
                        <p class="text-sm text-yellow-700 mt-1">
                            Once 2FA is enabled, you'll need your authenticator app to log in. 
                            Make sure to save your backup codes in a secure location.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const codeInput = document.getElementById('verification_code');
    
    // Auto-focus on code input
    codeInput.focus();
    
    // Only allow digits
    codeInput.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
    
    // Auto-submit when 6 digits are entered
    codeInput.addEventListener('input', function(e) {
        if (this.value.length === 6) {
            // Small delay to let user see the complete code
            setTimeout(() => {
                document.getElementById('verifyForm').submit();
            }, 500);
        }
    });
    
    // Handle form submission
    document.getElementById('verifyForm').addEventListener('submit', function(e) {
        const code = codeInput.value.trim();
        if (code.length !== 6 || !/^\d{6}$/.test(code)) {
            e.preventDefault();
            alert('Please enter a valid 6-digit code');
            codeInput.focus();
            return false;
        }
    });
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>
