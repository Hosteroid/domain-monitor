<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Authentication' ?> - Domain Monitor</title>
    
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
                            light: '#6BA3E8',
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        body {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full">
        <!-- Auth Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <?= $content ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6">
            <p class="text-gray-500 text-xs">
                © <?= date('Y') ?> Domain Monitor. All rights reserved.
            </p>
        </div>
    </div>

    <?php if (isset($scripts)): ?>
        <?= $scripts ?>
    <?php endif; ?>
</body>
</html>

