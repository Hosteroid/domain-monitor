<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Internal Server Error</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            DEFAULT: '#4A90E2',
                            dark: '#357ABD',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-red-50 to-orange-100 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-2xl w-full">
        <div class="bg-white rounded-2xl shadow-2xl p-12 text-center">
            <!-- Error Icon -->
            <div class="mb-8">
                <i class="fas fa-exclamation-circle text-red-500 text-8xl mb-4"></i>
            </div>
            
            <!-- Error Message -->
            <h1 class="text-9xl font-bold text-gray-800 mb-4">500</h1>
            <h2 class="text-3xl font-bold text-gray-700 mb-4">Internal Server Error</h2>
            <p class="text-gray-600 text-lg mb-8 leading-relaxed">
                Oops! Something went wrong on our end. We're working to fix the issue.
            </p>
            
            <!-- Error Reference ID -->
            <?php if (!empty($error_id)): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 mb-8">
                <div class="flex items-center justify-center space-x-3">
                    <div class="flex-shrink-0">
                        <i class="fas fa-fingerprint text-blue-600 text-2xl"></i>
                    </div>
                    <div class="text-left">
                        <p class="text-sm font-medium text-gray-700 mb-1">Error Reference ID:</p>
                        <div class="flex items-center space-x-2">
                            <code class="text-lg font-mono font-bold text-primary bg-white px-3 py-1 rounded border border-blue-200">
                                <?= htmlspecialchars($error_id) ?>
                            </code>
                            <button onclick="copyErrorId()" 
                                    class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
                                    title="Copy Error ID">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-600 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Please include this ID when reporting the issue
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="/" class="inline-flex items-center justify-center px-8 py-4 bg-primary text-white rounded-lg hover:bg-primary-dark transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                    <i class="fas fa-home mr-2"></i>
                    Go to Dashboard
                </a>
                <button onclick="history.back()" class="inline-flex items-center justify-center px-8 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all duration-200 shadow-md hover:shadow-lg">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Go Back
                </button>
                <button onclick="location.reload()" class="inline-flex items-center justify-center px-8 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all duration-200 shadow-md hover:shadow-lg">
                    <i class="fas fa-redo mr-2"></i>
                    Try Again
                </button>
            </div>
            
            <!-- Helpful Links -->
            <div class="mt-12 pt-8 border-t border-gray-200">
                <p class="text-sm text-gray-500 mb-4">Quick Links:</p>
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="/domains" class="text-primary hover:text-primary-dark transition-colors duration-150">
                        <i class="fas fa-globe mr-1"></i>
                        Domains
                    </a>
                    <a href="/groups" class="text-primary hover:text-primary-dark transition-colors duration-150">
                        <i class="fas fa-bell mr-1"></i>
                        Notification Groups
                    </a>
                    <a href="/debug/whois" class="text-primary hover:text-primary-dark transition-colors duration-150">
                        <i class="fas fa-search mr-1"></i>
                        WHOIS Lookup
                    </a>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="/settings" class="text-primary hover:text-primary-dark transition-colors duration-150">
                        <i class="fas fa-cog mr-1"></i>
                        Settings
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Support Info -->
            <div class="mt-8 bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-600">
                    <i class="fas fa-life-ring text-primary mr-1"></i>
                    If this problem persists, please contact your system administrator
                    <?php if (!empty($error_id)): ?>
                        and provide the error reference ID above.
                    <?php else: ?>
                        .
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-8">
            <p class="text-gray-600">
                <i class="fas fa-globe text-primary"></i>
                <span class="ml-2">Domain Monitor &copy; <?= date('Y') ?></span>
            </p>
        </div>
    </div>

    <script>
        function copyErrorId() {
            const errorId = '<?= htmlspecialchars($error_id ?? '') ?>';
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(errorId).then(() => {
                    showSuccess();
                }).catch(() => {
                    fallbackCopy(errorId);
                });
            } else {
                fallbackCopy(errorId);
            }
        }
        
        function fallbackCopy(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            
            try {
                document.execCommand('copy');
                showSuccess();
            } catch (err) {
                console.error('Copy failed:', err);
            }
            
            document.body.removeChild(textArea);
        }
        
        function showSuccess() {
            const btn = event.target.closest('button');
            if (btn) {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.add('bg-green-600', 'hover:bg-green-700');
                btn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                    btn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                }, 2000);
            }
        }
    </script>
</body>
</html>

