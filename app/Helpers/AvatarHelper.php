<?php

namespace App\Helpers;

class AvatarHelper
{
    /**
     * Cache for Gravatar existence checks to avoid repeated HTTP requests
     */
    private static array $gravatarCache = [];
    
    /**
     * Cache file path for persistent Gravatar cache
     */
    private static string $cacheFile = __DIR__ . '/../../cache/gravatar_cache.json';
    
    /**
     * Load Gravatar cache from file
     */
    private static function loadCache(): void
    {
        if (file_exists(self::$cacheFile)) {
            $cacheData = json_decode(file_get_contents(self::$cacheFile), true);
            if (is_array($cacheData)) {
                self::$gravatarCache = $cacheData;
            }
        }
    }
    
    /**
     * Save Gravatar cache to file
     */
    private static function saveCache(): void
    {
        // Ensure cache directory exists
        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents(self::$cacheFile, json_encode(self::$gravatarCache, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get cache key for email
     */
    private static function getCacheKey(string $email): string
    {
        return md5(strtolower(trim($email)));
    }
    /**
     * Get user avatar with fallback logic
     * Priority: Uploaded avatar -> Gravatar -> Initials
     * 
     * @param array $user User data array
     * @param int $size Avatar size in pixels (default: 40)
     * @param string $default Default Gravatar type (default: 'identicon')
     * @param bool $checkGravatar Whether to check Gravatar (default: true)
     * @return array Avatar data with 'type', 'url', 'initials', 'size'
     */
    public static function getAvatar(array $user, int $size = 40, string $default = 'identicon', bool $checkGravatar = true): array
    {
        $username = $user['username'] ?? 'U';
        $email = $user['email'] ?? '';
        $avatar = $user['avatar'] ?? null;
        
        // Priority 1: Check for uploaded avatar
        if (!empty($avatar) && self::avatarFileExists($avatar)) {
            return [
                'type' => 'uploaded',
                'url' => self::getAvatarUrl($avatar),
                'initials' => strtoupper(substr($username, 0, 1)),
                'size' => $size,
                'alt' => $user['full_name'] ?? $username
            ];
        }
        
        // Priority 2: Check for Gravatar (if email exists and user has actual Gravatar)
        if (!empty($email) && $checkGravatar) {
            // Check if we know the user has Gravatar from avatar field
            // 'gravatar' in avatar field means user has Gravatar
            // null/empty means unknown status
            // any other value means uploaded avatar (handled above)
            
            if ($avatar === 'gravatar') {
                // User has Gravatar, generate URL
                $gravatarUrl = self::getGravatarUrl($email, $size, 'identicon');
                return [
                    'type' => 'gravatar',
                    'url' => $gravatarUrl,
                    'initials' => strtoupper(substr($username, 0, 1)),
                    'size' => $size,
                    'alt' => $user['full_name'] ?? $username
                ];
            } elseif ($avatar === 'no_gravatar') {
                // We know user doesn't have Gravatar, skip to initials
                // Fall through to initials
            } else {
                // Unknown status - check Gravatar and update database
                $gravatarUrl = self::getGravatarUrl($email, $size, '404');
                if (self::gravatarExists($gravatarUrl)) {
                    // Update database to remember this user has Gravatar
                    self::updateGravatarStatus($user['id'], 'gravatar');
                    return [
                        'type' => 'gravatar',
                        'url' => $gravatarUrl,
                        'initials' => strtoupper(substr($username, 0, 1)),
                        'size' => $size,
                        'alt' => $user['full_name'] ?? $username
                    ];
                } else {
                    // Update database to remember this user doesn't have Gravatar
                    self::updateGravatarStatus($user['id'], 'no_gravatar');
                }
            }
        }
        
        // Priority 3: Fallback to initials
        return [
            'type' => 'initials',
            'url' => null,
            'initials' => strtoupper(substr($username, 0, 1)),
            'size' => $size,
            'alt' => $user['full_name'] ?? $username
        ];
    }
    
    /**
     * Get Gravatar URL for email
     * 
     * @param string $email User email
     * @param int $size Avatar size in pixels
     * @param string $default Default Gravatar type
     * @return string Gravatar URL
     */
    public static function getGravatarUrl(string $email, int $size = 40, string $default = 'identicon'): string
    {
        $hash = md5(strtolower(trim($email)));
        $size = max(1, min(2048, $size)); // Gravatar size limits
        $default = urlencode($default);
        
        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d={$default}&r=g";
    }
    
    /**
     * Check if Gravatar exists for the given URL (with caching)
     * 
     * @param string $gravatarUrl Gravatar URL to check
     * @return bool True if Gravatar exists
     */
    public static function gravatarExists(string $gravatarUrl): bool
    {
        // Extract email from Gravatar URL for caching
        if (preg_match('/avatar\/([a-f0-9]{32})/', $gravatarUrl, $matches)) {
            $emailHash = $matches[1];
        } else {
            return false;
        }
        
        // Load cache if not already loaded
        if (empty(self::$gravatarCache)) {
            self::loadCache();
        }
        
        // Check cache first
        if (isset(self::$gravatarCache[$emailHash])) {
            return self::$gravatarCache[$emailHash];
        }
        
        // Use a simple HTTP HEAD request to check if the Gravatar exists
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 3, // Reduced timeout for better performance
                'user_agent' => 'Domain Monitor Avatar Checker'
            ]
        ]);
        
        $headers = @get_headers($gravatarUrl, 1, $context);
        
        $exists = false;
        if ($headers !== false) {
            $statusCode = $headers[0] ?? '';
            // Return true only if we get a 200 status (user has actual Gravatar)
            $exists = strpos($statusCode, '200') !== false;
        }
        
        // Cache the result
        self::$gravatarCache[$emailHash] = $exists;
        self::saveCache();
        
        return $exists;
    }
    
    /**
     * Check if uploaded avatar file exists
     * 
     * @param string $avatarFilename Avatar filename
     * @return bool True if file exists
     */
    public static function avatarFileExists(string $avatarFilename): bool
    {
        $avatarPath = self::getAvatarPath($avatarFilename);
        return file_exists($avatarPath);
    }
    
    /**
     * Get avatar file path
     * 
     * @param string $avatarFilename Avatar filename
     * @return string Full path to avatar file
     */
    public static function getAvatarPath(string $avatarFilename): string
    {
        // Get the web root directory dynamically
        $webRoot = self::getWebRoot();
        return $webRoot . '/assets/uploads/avatars/' . $avatarFilename;
    }
    
    /**
     * Get avatar URL for display
     * 
     * @param string $avatarFilename Avatar filename
     * @return string Avatar URL
     */
    public static function getAvatarUrl(string $avatarFilename): string
    {
        return '/assets/uploads/avatars/' . $avatarFilename;
    }
    
    /**
     * Get the web root directory dynamically
     * 
     * @return string Path to web root directory
     */
    private static function getWebRoot(): string
    {
        // Use the document root from the web server - this is the most reliable way
        return $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3) . '/public_html';
    }
    
    /**
     * Generate unique avatar filename
     * 
     * @param string $originalFilename Original uploaded filename
     * @param int $userId User ID
     * @return string Unique filename
     */
    public static function generateAvatarFilename(string $originalFilename, int $userId): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        
        return "user_{$userId}_{$timestamp}_{$random}.{$extension}";
    }
    
    /**
     * Validate uploaded avatar file
     * 
     * @param array $file $_FILES array element
     * @return array Validation result with 'valid' boolean and 'error' message
     */
    public static function validateAvatarFile(array $file): array
    {
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by extension'
            ];
            
            $error = $errorMessages[$file['error']] ?? 'Unknown upload error';
            
            return [
                'valid' => false,
                'error' => $error
            ];
        }
        
        // Check file size (2MB limit)
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            return [
                'valid' => false,
                'error' => 'File too large. Maximum size is 2MB.'
            ];
        }
        
        // Check file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return [
                'valid' => false,
                'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed. Detected: ' . $mimeType
            ];
        }
        
        // Check file extension
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            return [
                'valid' => false,
                'error' => 'Invalid file extension. Only .jpg, .jpeg, .png, .gif, and .webp files are allowed.'
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Create avatar uploads directory if it doesn't exist
     * 
     * @return bool True if directory exists or was created successfully
     */
    public static function ensureUploadDirectory(): bool
    {
        $webRoot = self::getWebRoot();
        $uploadDir = $webRoot . '/assets/uploads/avatars';
        
        if (!is_dir($uploadDir)) {
            $result = mkdir($uploadDir, 0755, true);
            return $result;
        }
        
        return true;
    }
    
    /**
     * Delete old avatar file
     * 
     * @param string $avatarFilename Avatar filename to delete
     * @return bool True if file was deleted or didn't exist
     */
    public static function deleteAvatarFile(string $avatarFilename): bool
    {
        if (empty($avatarFilename)) {
            return true;
        }
        
        $avatarPath = self::getAvatarPath($avatarFilename);
        
        if (file_exists($avatarPath)) {
            return unlink($avatarPath);
        }
        
        return true;
    }
    
    /**
     * Render avatar HTML
     * 
     * @param array $user User data array
     * @param int $size Avatar size in pixels
     * @param string $cssClass Additional CSS classes
     * @param bool $showOnlineStatus Show online status indicator
     * @return string HTML for avatar
     */
    public static function renderAvatar(array $user, int $size = 40, string $cssClass = '', bool $showOnlineStatus = false): string
    {
        $avatar = self::getAvatar($user, $size);
        $sizeClass = "w-{$size} h-{$size}";
        
        $html = '<div class="' . $sizeClass . ' rounded-full flex items-center justify-center text-white font-semibold ' . $cssClass . '">';
        
        if ($avatar['type'] === 'uploaded' || $avatar['type'] === 'gravatar') {
            $html .= '<img src="' . htmlspecialchars($avatar['url']) . '" ';
            $html .= 'alt="' . htmlspecialchars($avatar['alt']) . '" ';
            $html .= 'class="w-full h-full rounded-full object-cover" ';
            $html .= 'loading="lazy">';
        } else {
            $html .= $avatar['initials'];
        }
        
        if ($showOnlineStatus) {
            $html .= '<div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white flex items-center justify-center">';
            $html .= '<div class="w-2 h-2 bg-white rounded-full"></div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Clear Gravatar cache
     */
    public static function clearCache(): void
    {
        self::$gravatarCache = [];
        if (file_exists(self::$cacheFile)) {
            unlink(self::$cacheFile);
        }
    }
    
    /**
     * Update Gravatar status in database using avatar field
     */
    private static function updateGravatarStatus(int $userId, string $status): void
    {
        try {
            $pdo = \Core\Database::getConnection();
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$status, $userId]);
        } catch (\Exception $e) {
            // Silently fail - don't break avatar display
        }
    }
    
    /**
     * Get detected web root for debugging
     */
    public static function getDetectedWebRoot(): string
    {
        return self::getWebRoot();
    }
    
    /**
     * Get cache statistics
     */
    public static function getCacheStats(): array
    {
        if (empty(self::$gravatarCache)) {
            self::loadCache();
        }
        
        $total = count(self::$gravatarCache);
        $withGravatar = array_sum(self::$gravatarCache);
        $withoutGravatar = $total - $withGravatar;
        
        return [
            'total_checked' => $total,
            'with_gravatar' => $withGravatar,
            'without_gravatar' => $withoutGravatar,
            'cache_file' => self::$cacheFile,
            'cache_size' => file_exists(self::$cacheFile) ? filesize(self::$cacheFile) : 0,
            'web_root' => self::getWebRoot()
        ];
    }
}
