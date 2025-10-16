<?php
$title = '2FA Verification';
ob_start();

$twoFactorService = new \App\Services\TwoFactorService();
$canSendEmailCode = $user['email_verified'] && $twoFactorService->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '', $user['id']);
?>

<div class="text-center mb-6">
    <div class="mx-auto h-12 w-12 flex items-center justify-center rounded-full bg-primary bg-opacity-10 mb-4">
        <i class="fas fa-shield-alt text-primary text-xl"></i>
    </div>
    <h2 class="text-2xl font-bold text-gray-900 mb-2">
        2FA Verification
    </h2>
    <p class="text-sm text-gray-600">
        Hello, <strong><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></strong>!<br>
        Please enter your 2FA code to complete login.
    </p>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
            <span class="text-sm text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-green-500 mr-2"></i>
            <span class="text-sm text-green-700"><?= htmlspecialchars($_SESSION['success']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<form class="space-y-4" method="POST" action="/2fa/verify" id="verifyForm">
    <?= csrf_field() ?>
    
    <!-- Security verification completed during login -->
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-green-500 mr-2"></i>
            <span class="text-sm text-green-700">Security verification completed during login</span>
        </div>
    </div>
    
    <div>
        <label for="code" class="block text-sm font-medium text-gray-700 mb-2">
            2FA Code
        </label>
        <input id="code" name="verification_code" type="text" required 
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-center text-lg font-mono tracking-widest focus:ring-2 focus:ring-primary focus:border-primary transition-colors" 
               placeholder="000000" maxlength="8" autocomplete="one-time-code" autofocus>
        <p class="text-xs text-gray-500 mt-1 text-center">Enter 6-digit code from your authenticator app, email code, or 8-character backup code</p>
    </div>

    <button type="submit" 
            class="w-full bg-primary text-white py-2 px-4 rounded-lg hover:bg-primary-dark focus:ring-2 focus:ring-primary focus:ring-offset-2 transition-colors">
        <i class="fas fa-check mr-2"></i>
        Verify Code
    </button>

    <div class="flex items-center justify-between text-sm">
        <?php if ($canSendEmailCode): ?>
        <button type="button" onclick="sendEmailCode()" 
                class="text-primary hover:text-primary-dark transition-colors">
            <i class="fas fa-envelope mr-1"></i>
            Send Email Code
        </button>
        <?php else: ?>
        <span class="text-gray-400">
            <i class="fas fa-envelope mr-1"></i>
            Email code unavailable
        </span>
        <?php endif; ?>
        <a href="/logout" class="text-gray-600 hover:text-gray-500 transition-colors">
            <i class="fas fa-sign-out-alt mr-1"></i>
            Sign out instead
        </a>
    </div>

    <div class="mt-6 pt-4 border-t border-gray-200">
        <div class="text-center">
            <p class="text-sm text-gray-600">
                Having trouble? You can also use a backup code or contact your administrator for help.
            </p>
        </div>
    </div>
</form>

<script>
function sendEmailCode() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Sending...';
    
    fetch('/2fa/send-email-code', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': document.querySelector('input[name="csrf_token"]').value
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check mr-1"></i>Code Sent';
            btn.classList.remove('text-primary', 'hover:text-primary-dark');
            btn.classList.add('text-green-600');
            
            // Reset button after 30 seconds
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.classList.remove('text-green-600');
                btn.classList.add('text-primary', 'hover:text-primary-dark');
            }, 30000);
        } else {
            alert('Failed to send email code: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(error => {
        alert('Failed to send email code');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const codeInput = document.getElementById('code');
    const form = document.getElementById('verifyForm');

    // Auto-focus on code input
    codeInput.focus();

    // Handle input validation and auto-submit
    codeInput.addEventListener('input', function(e) {
        // Allow digits, letters for backup codes
        this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
        
        // Auto-submit when 6 digits are entered (TOTP/email codes)
        if (this.value.length === 6 && /^\d{6}$/.test(this.value)) {
            setTimeout(() => {
                form.submit();
            }, 500);
        }
        
        // Auto-submit when 8 characters are entered (backup codes)
        if (this.value.length === 8 && /^[A-Z0-9]{8}$/i.test(this.value)) {
            setTimeout(() => {
                form.submit();
            }, 500);
        }
    });

    // Form validation
    form.addEventListener('submit', function(e) {
        const code = codeInput.value.trim();

        // Check if code is entered
        if (!code) {
            e.preventDefault();
            alert('Please enter a verification code');
            codeInput.focus();
            return false;
        }

        // Validate code format
        if (code.length === 6 && !/^\d{6}$/.test(code)) {
            e.preventDefault();
            alert('Please enter a valid 6-digit code');
            codeInput.focus();
            return false;
        }

        if (code.length === 8 && !/^[A-Z0-9]{8}$/i.test(code)) {
            e.preventDefault();
            alert('Please enter a valid 8-character backup code');
            codeInput.focus();
            return false;
        }

        if (code.length < 6 || code.length > 8) {
            e.preventDefault();
            alert('Please enter a valid verification code (6 digits or 8 characters)');
            codeInput.focus();
            return false;
        }
    });
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../auth/base-auth.php';
?>
