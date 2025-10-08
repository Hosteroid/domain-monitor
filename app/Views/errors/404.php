<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    
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
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-2xl w-full">
        <div class="bg-white rounded-2xl shadow-2xl p-12 text-center">
            <!-- 404 Icon -->
            <div class="mb-8">
                <i class="fas fa-exclamation-triangle text-yellow-500 text-8xl mb-4 animate-pulse"></i>
            </div>
            
            <!-- Error Message -->
            <h1 class="text-9xl font-bold text-gray-800 mb-4">404</h1>
            <h2 class="text-3xl font-bold text-gray-700 mb-4">Page Not Found</h2>
            <p class="text-gray-600 text-lg mb-8 leading-relaxed">
                Oops! The page you're looking for doesn't exist. It might have been moved or deleted.
            </p>
            
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
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-8">
            <p class="text-gray-600">
                <i class="fas fa-globe text-primary"></i>
                <span class="ml-2">Domain Monitor © <?= date('Y') ?></span>
            </p>
        </div>
    </div>
</body>
</html>
