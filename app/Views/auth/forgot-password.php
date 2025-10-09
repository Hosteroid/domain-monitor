<?php
$title = 'Forgot Password';
ob_start();
?>

<!-- Logo and Title -->
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-14 h-14 bg-primary rounded-lg mb-4">
        <i class="fas fa-key text-white text-2xl"></i>
    </div>
    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Forgot Password?</h1>
    <p class="text-sm text-gray-500">No worries, we'll send you reset instructions</p>
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

<!-- Forgot Password Form -->
<form method="POST" action="/forgot-password" class="space-y-5">
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
                autofocus
                class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Enter your email address">
        </div>
        <p class="text-xs text-gray-500 mt-1">Enter the email associated with your account</p>
    </div>

    <!-- Submit Button -->
    <button 
        type="submit" 
        class="w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center text-sm">
        <i class="fas fa-paper-plane mr-2"></i>
        Send Reset Link
    </button>
</form>

<!-- Back to Login Link -->
<div class="text-center mt-6 pt-6 border-t border-gray-200">
    <a href="/login" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-800">
        <i class="fas fa-arrow-left mr-2"></i>
        Back to Login
    </a>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/base-auth.php';
?>
