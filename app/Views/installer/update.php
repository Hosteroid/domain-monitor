<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Update</title>
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
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-primary rounded-lg mb-4">
                    <i class="fas fa-arrow-up text-white text-3xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 mb-2">System Update</h1>
                <p class="text-gray-600">New database migrations are available</p>
            </div>

            <!-- Warning -->
            <div class="bg-amber-50 border border-amber-300 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-amber-600 text-xl mr-3"></i>
                    <div>
                        <h3 class="font-semibold text-amber-900 mb-1">Backup Recommended</h3>
                        <p class="text-sm text-amber-800">Please backup your database before running updates.</p>
                    </div>
                </div>
            </div>

            <!-- Pending Migrations -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Pending Migrations</h2>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <ul class="space-y-2">
                        <?php foreach ($migrations as $migration): ?>
                        <li class="flex items-center text-sm">
                            <i class="fas fa-circle text-xs text-gray-400 mr-3"></i>
                            <span class="font-mono text-gray-700"><?= htmlspecialchars($migration) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-3 pt-3 border-t border-gray-300">
                        <p class="text-sm font-semibold text-gray-900">
                            <i class="fas fa-database mr-2"></i>
                            Total: <?= count($migrations) ?> migration(s)
                        </p>
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

            <!-- Actions -->
            <form method="POST" action="/install/update" class="space-y-3">
                <button type="submit" class="w-full bg-primary hover:bg-primary-dark text-white py-2.5 rounded-lg font-medium transition-colors">
                    <i class="fas fa-download mr-2"></i>
                    Run Update Now
                </button>
                <a href="/" class="block w-full text-center px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    <i class="fas fa-times mr-2"></i>
                    Cancel
                </a>
            </form>
        </div>

        <div class="text-center mt-6">
            <p class="text-gray-500 text-xs">© <?= date('Y') ?> <a href="https://github.com/Hosteroid/domain-monitor" target="_blank" class="hover:text-blue-600 transition-colors duration-150" title="Visit Domain Monitor on GitHub">Domain Monitor</a></p>
        </div>
    </div>
</body>
</html>
