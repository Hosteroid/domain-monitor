<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Domain Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: '#4A90E2', dark: '#357ABD' }
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-2xl w-full">
        <!-- Installer Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <!-- Logo and Title -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-primary rounded-lg mb-4">
                    <i class="fas fa-globe text-white text-3xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Domain Monitor Installer</h1>
                <p class="text-gray-600">Welcome! Let's set up your monitoring system</p>
            </div>

            <!-- Installation Steps -->
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-6 mb-6">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">Installation Steps</h2>
                <div class="space-y-3">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center text-sm font-semibold">1</div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-gray-900">Database Setup</h3>
                            <p class="text-sm text-gray-600">Create tables and structure</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center text-sm font-semibold">2</div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-gray-900">Admin Account</h3>
                            <p class="text-sm text-gray-600">Set your credentials below</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center text-sm font-semibold">3</div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-gray-900">Start Monitoring</h3>
                            <p class="text-sm text-gray-600">Begin tracking your domains</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Alert -->
            <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-6 bg-red-50 border border-red-200 p-3 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                    <span class="text-sm text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></span>
                </div>
            </div>
            <?php unset($_SESSION['error']); endif; ?>

            <!-- Installation Form -->
            <form method="POST" action="/install/run" class="space-y-5">
                <div class="border-t border-gray-200 pt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Administrator Account</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="admin_email" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Address <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-envelope text-gray-400 text-sm"></i>
                                </div>
                                <input type="email" id="admin_email" name="admin_email" required
                                       class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                                       placeholder="admin@example.com">
                            </div>
                        </div>

                        <div>
                            <label for="admin_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-gray-400 text-sm"></i>
                                </div>
                                <input type="password" id="admin_password" name="admin_password" required minlength="8"
                                       class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                                       placeholder="Enter secure password">
                                <button type="button" onclick="togglePassword()"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye text-sm" id="toggleIcon"></i>
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                        </div>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mt-4">
                        <p class="text-xs text-blue-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            <strong>Note:</strong> These credentials will be used to access the admin panel. Save them securely!
                        </p>
                    </div>
                </div>

                <button type="submit" class="w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium transition-colors">
                    <i class="fas fa-rocket mr-2"></i>
                    Start Installation
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6">
            <p class="text-gray-500 text-xs">© <?= date('Y') ?> Domain Monitor</p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('admin_password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
