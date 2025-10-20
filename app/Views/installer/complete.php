<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete</title>
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
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <!-- Success Icon -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-4">
                    <i class="fas fa-check-circle text-green-600 text-5xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Installation Complete!</h1>
                <p class="text-gray-600">Domain Monitor is ready to use</p>
            </div>

            <!-- Important Notice -->
            <div class="bg-amber-50 border-2 border-amber-400 rounded-lg p-6 mb-6">
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-amber-600 text-2xl mr-4"></i>
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-amber-900 mb-2">Save Your Credentials!</h3>
                        <p class="text-sm text-amber-800 mb-4">This password will not be shown again. Save it to a secure password manager.</p>
                        
                        <div class="bg-white rounded-lg border border-amber-300 p-4">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-600">Username:</span>
                                    <span class="text-sm font-mono font-bold text-gray-900 select-all"><?= htmlspecialchars($adminUsername ?? 'admin') ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-600">Password:</span>
                                    <span class="text-sm font-mono font-bold text-gray-900 select-all"><?= htmlspecialchars($adminPassword ?? '********') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Success Checklist -->
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-6 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">Installation Summary</h3>
                <div class="space-y-3">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <span class="text-sm text-gray-700">Database tables created</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <span class="text-sm text-gray-700">Admin account configured</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <span class="text-sm text-gray-700">Encryption key generated</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <span class="text-sm text-gray-700">All migrations applied</span>
                    </div>
                </div>
            </div>

            <!-- Next Steps -->
            <div class="bg-blue-50 rounded-lg border border-blue-200 p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-3">
                    <i class="fas fa-lightbulb mr-2"></i>Next Steps
                </h3>
                <ol class="text-sm text-blue-800 space-y-1 ml-5 list-decimal">
                    <li>Log in with your admin credentials</li>
                    <li>Configure email settings (Settings → Email)</li>
                    <li>Import TLD registry data (TLD Registry → Import TLDs)</li>
                    <li>Add your first domain</li>
                    <li>Set up notification groups</li>
                    <li>Configure cron job for automated monitoring</li>
                </ol>
            </div>

            <a href="/login" class="block w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium text-center transition-colors">
                <i class="fas fa-sign-in-alt mr-2"></i>
                Go to Login
            </a>
        </div>

        <div class="text-center mt-6">
            <p class="text-gray-500 text-xs">© <?= date('Y') ?> <a href="https://github.com/Hosteroid/domain-monitor" target="_blank" class="hover:text-blue-600 transition-colors duration-150" title="Visit Domain Monitor on GitHub">Domain Monitor</a></p>
        </div>
    </div>
</body>
</html>
