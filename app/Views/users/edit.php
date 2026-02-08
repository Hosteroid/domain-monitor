<?php
$title = 'Edit User';
$pageTitle = 'Edit User';
$pageDescription = 'Update user information and permissions';
$pageIcon = 'fas fa-user-edit';
ob_start();
?>

<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-user-edit text-gray-400 mr-2 text-sm"></i>
                User Information
            </h2>
        </div>

        <div class="p-6">
            <form method="POST" action="/users/<?= $user['id'] ?>/update" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $user['id'] ?>">

                <!-- Name & Username Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Full Name -->
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Full Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               id="full_name" 
                               name="full_name" 
                               required
                               autofocus
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                               placeholder="John Doe">
                        <p class="mt-1.5 text-xs text-gray-500">
                            The user's display name
                        </p>
                    </div>

                    <!-- Username (Read-only) -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Username
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="fas fa-at text-sm"></i>
                            </span>
                            <input type="text" 
                                   id="username" 
                                   value="<?= htmlspecialchars($user['username']) ?>" 
                                   readonly
                                   class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg bg-gray-50 text-gray-500 cursor-not-allowed text-sm">
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Username cannot be changed
                        </p>
                    </div>
                </div>

                <!-- Email & Role Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Email Address <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   required
                                   value="<?= htmlspecialchars($user['email']) ?>"
                                   class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                                   placeholder="john@example.com">
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Used for login and notifications
                        </p>
                    </div>

                    <!-- Role -->
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Role <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                <i class="fas fa-shield-alt text-sm"></i>
                            </span>
                            <select id="role" 
                                    name="role" 
                                    required
                                    class="w-full pl-9 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm appearance-none bg-white">
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <span class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-chevron-down text-xs"></i>
                            </span>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Admins have full system access
                        </p>
                    </div>
                </div>

                <!-- Status -->
                <div class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
                    <input type="checkbox" id="is_active" name="is_active" value="1"
                           <?= $user['is_active'] ? 'checked' : '' ?>
                           class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
                    <div>
                        <label for="is_active" class="text-sm font-medium text-gray-700">
                            Active Account
                        </label>
                        <p class="text-xs text-gray-500">Inactive users cannot log in to the system</p>
                    </div>
                </div>

                <!-- Password Section -->
                <div class="border-t border-gray-200 pt-5 mt-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-lock text-gray-400 mr-2"></i>
                        Change Password
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <!-- New Password -->
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">
                                New Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       id="password" 
                                       name="password" 
                                       minlength="8"
                                       class="w-full px-3 py-2.5 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                                       placeholder="••••••••">
                                <button type="button" 
                                        onclick="togglePassword('password')"
                                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye text-sm" id="password-toggle-icon"></i>
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">
                                Leave blank to keep current password
                            </p>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Confirm Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       id="password_confirm" 
                                       name="password_confirm" 
                                       minlength="8"
                                       class="w-full px-3 py-2.5 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm"
                                       placeholder="••••••••">
                                <button type="button" 
                                        onclick="togglePassword('password_confirm')"
                                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye text-sm" id="password_confirm-toggle-icon"></i>
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">
                                Re-enter the new password to confirm
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-save mr-2"></i>
                        Update User
                    </button>
                    <a href="/users" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Account Info Section -->
    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-info-circle text-white"></i>
                </div>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">Account Details</h3>
                <ul class="text-xs text-gray-600 space-y-1">
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">Email Verified: 
                            <span class="font-semibold <?= $user['email_verified'] ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $user['email_verified'] ? 'Yes' : 'No' ?>
                            </span>
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">Member Since: 
                            <span class="font-semibold text-gray-900"><?= date('M d, Y', strtotime($user['created_at'])) ?></span>
                        </span>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-circle text-blue-500" style="font-size: 6px;"></i>
                        <span class="ml-2">Last Login: 
                            <span class="font-semibold text-gray-900"><?= $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never' ?></span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '-toggle-icon');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Password confirmation validation
document.getElementById('password_confirm').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirm = this.value;
    
    if (confirm && password !== confirm) {
        this.setCustomValidity('Passwords do not match');
        this.classList.add('border-red-300');
        this.classList.remove('border-gray-300');
    } else {
        this.setCustomValidity('');
        this.classList.remove('border-red-300');
        this.classList.add('border-gray-300');
    }
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout/base.php';
?>
