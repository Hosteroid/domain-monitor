<?php
$title = 'Register';
ob_start();
?>

<!-- Logo and Title -->
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-14 h-14 bg-primary rounded-lg mb-4">
        <i class="fas fa-user-plus text-white text-2xl"></i>
    </div>
    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Create Account</h1>
    <p class="text-sm text-gray-500">Join Domain Monitor today</p>
</div>

<!-- Error/Success Alert -->
<?php if (isset($_SESSION['error'])): ?>
    <div class="mb-6 bg-red-50 border border-red-200 p-3 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
            <span class="text-sm text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="mb-6 bg-green-50 border border-green-200 p-3 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-green-500 mr-2"></i>
            <span class="text-sm text-green-700"><?= htmlspecialchars($_SESSION['success']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<!-- Registration Form -->
<form method="POST" action="/register" class="space-y-4">
    <?= csrf_field() ?>
    <!-- Full Name Field -->
    <div>
        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1.5">
            Full Name
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-user text-gray-400 text-sm"></i>
            </div>
            <input 
                type="text" 
                id="full_name" 
                name="full_name" 
                required 
                autofocus
                class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Enter your full name">
        </div>
    </div>

    <!-- Username Field -->
    <div>
        <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">
            Username
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-at text-gray-400 text-sm"></i>
            </div>
            <input 
                type="text" 
                id="username" 
                name="username" 
                required
                pattern="[a-zA-Z0-9_]+"
                class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Choose a username">
        </div>
        <p class="text-xs text-gray-500 mt-1">Letters, numbers, and underscores only</p>
    </div>

    <!-- Email Field -->
    <div>
        <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
            Email Address
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-envelope text-gray-400 text-sm"></i>
            </div>
            <input 
                type="email" 
                id="email" 
                name="email" 
                required
                class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="your.email@example.com">
        </div>
    </div>

    <!-- Password Field -->
    <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">
            Password
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-lock text-gray-400 text-sm"></i>
            </div>
            <input 
                type="password" 
                id="password" 
                name="password" 
                required
                minlength="8"
                class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Create a strong password">
            <button 
                type="button" 
                onclick="togglePassword('password')"
                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                <i class="fas fa-eye text-sm" id="toggleIcon-password"></i>
            </button>
        </div>
        <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
    </div>

    <!-- Confirm Password Field -->
    <div>
        <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-1.5">
            Confirm Password
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-lock text-gray-400 text-sm"></i>
            </div>
            <input 
                type="password" 
                id="password_confirm" 
                name="password_confirm" 
                required
                minlength="8"
                class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Re-enter your password">
            <button 
                type="button" 
                onclick="togglePassword('password_confirm')"
                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                <i class="fas fa-eye text-sm" id="toggleIcon-password_confirm"></i>
            </button>
        </div>
    </div>

    <!-- Terms Checkbox -->
    <div class="flex items-start pt-2">
        <div class="flex items-center h-5">
            <input 
                type="checkbox" 
                id="terms" 
                name="terms"
                required 
                class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
        </div>
        <label for="terms" class="ml-2 text-xs text-gray-600">
            I agree to the Terms of Service and Privacy Policy
        </label>
    </div>

    <!-- CAPTCHA Widget -->
    <?php include __DIR__ . '/captcha-widget.php'; ?>

    <!-- Submit Button -->
    <button 
        type="submit" 
        class="w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center text-sm mt-6">
        <i class="fas fa-user-plus mr-2"></i>
        Create Account
    </button>
</form>

<!-- Sign In Link -->
<div class="text-center mt-6 pt-6 border-t border-gray-200">
    <p class="text-sm text-gray-600">
        Already have an account? 
        <a href="/login" class="text-primary hover:text-primary-dark font-medium">
            Sign In
        </a>
    </p>
</div>

<?php
$content = ob_get_clean();
$scripts = <<<'SCRIPT'
<script>
    function togglePassword(fieldId) {
        const passwordInput = document.getElementById(fieldId);
        const toggleIcon = document.getElementById('toggleIcon-' + fieldId);
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    // Client-side password match validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const passwordConfirm = document.getElementById('password_confirm').value;
        
        if (password !== passwordConfirm) {
            e.preventDefault();
            alert('Passwords do not match!');
        }
    });
</script>
SCRIPT;
require __DIR__ . '/base-auth.php';
?>
