<?php
$title = 'Login';
ob_start();
?>

<!-- Logo and Title -->
<div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-14 h-14 bg-primary rounded-lg mb-4">
        <i class="fas fa-globe text-white text-2xl"></i>
    </div>
    <h1 class="text-2xl font-semibold text-gray-900 mb-1">Welcome Back</h1>
    <p class="text-sm text-gray-500">Sign in to access your account</p>
</div>

<!-- Error Alert -->
<?php if (isset($_SESSION['error'])): ?>
    <div class="mb-6 bg-red-50 border border-red-200 p-3 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
            <span class="text-sm text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Login Form -->
<form method="POST" action="/login" class="space-y-5">
    <!-- Username Field -->
    <div>
        <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">
            Username or Email
        </label>
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-user text-gray-400 text-sm"></i>
            </div>
            <input 
                type="text" 
                id="username" 
                name="username" 
                required 
                autofocus
                class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Enter your username or email">
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
                class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                placeholder="Enter your password">
            <button 
                type="button" 
                onclick="togglePassword()"
                class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                <i class="fas fa-eye text-sm" id="toggleIcon"></i>
            </button>
        </div>
    </div>

    <!-- Remember Me -->
    <div class="flex items-center justify-between">
        <label class="flex items-center cursor-pointer">
            <input 
                type="checkbox" 
                name="remember"
                value="1"
                class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
            <span class="ml-2 text-sm text-gray-600">Remember me</span>
        </label>
        <a href="/forgot-password" class="text-sm text-primary hover:text-primary-dark">
            Forgot password?
        </a>
    </div>

    <!-- Submit Button -->
    <button 
        type="submit" 
        class="w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center text-sm">
        <i class="fas fa-sign-in-alt mr-2"></i>
        Sign In
    </button>
</form>

<?php if ($registrationEnabled ?? false): ?>
<!-- Sign Up Link -->
<div class="text-center mt-6 pt-6 border-t border-gray-200">
    <p class="text-sm text-gray-600">
        Don't have an account? 
        <a href="/register" class="text-primary hover:text-primary-dark font-medium">
            Create Account
        </a>
    </p>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
$scripts = <<<'SCRIPT'
<script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        
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
</script>
SCRIPT;
require __DIR__ . '/base-auth.php';
?>
