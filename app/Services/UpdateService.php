<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;

class UpdateService
{
    private const GITHUB_REPO = 'Hosteroid/domain-monitor';
    private const GITHUB_API_BASE = 'https://api.github.com';
    private const CACHE_TTL_HOURS = 1;

    private const PROTECTED_PATHS = [
        '.env',
        '.installed',
        'vendor',
        'logs',
        'storage',
        'domain-monitor-docker',
        '.git',
        '.gitignore',
        'node_modules',
    ];

    private Setting $settingModel;
    private Logger $logger;
    private Client $httpClient;
    private string $rootPath;

    public function __construct()
    {
        $this->settingModel = new Setting();
        $this->logger = new Logger('updater');
        $this->rootPath = realpath(__DIR__ . '/../../');
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'DomainMonitor/' . $this->settingModel->getAppVersion(),
            ],
        ]);
    }

    /**
     * Check for available updates based on the user's chosen update channel
     * Returns structured info about what's available
     */
    public function checkForUpdate(bool $forceCheck = false): array
    {
        $channel = $this->settingModel->getValue('update_channel', 'stable');
        $currentVersion = $this->settingModel->getAppVersion();
        $localSha = $this->settingModel->getValue('installed_commit_sha', null);

        // Check cache (unless forced)
        if (!$forceCheck) {
            $lastCheck = $this->settingModel->getValue('last_update_check', null);
            if ($lastCheck && $this->isCacheValid($lastCheck)) {
                return $this->getCachedResult($currentVersion, $channel, $localSha);
            }
        }

        $this->logger->info('Checking for updates', [
            'channel' => $channel,
            'current_version' => $currentVersion,
            'local_sha' => $localSha ? substr($localSha, 0, 7) : 'unknown',
        ]);

        $result = [
            'available' => false,
            'type' => null,
            'current_version' => $currentVersion,
            'channel' => $channel,
            'error' => null,
        ];

        try {
            // Always check for tagged releases
            $release = $this->fetchLatestRelease();

            if ($release) {
                $latestVersion = ltrim($release['tag_name'], 'v');

                $result['latest_version'] = $latestVersion;
                $result['release_notes'] = $release['body'] ?? '';
                $result['release_url'] = $release['html_url'] ?? '';
                $result['published_at'] = $release['published_at'] ?? null;
                $result['download_url'] = $release['zipball_url'] ?? null;

                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $result['available'] = true;
                    $result['type'] = 'release';
                }

                // Cache release info
                $this->settingModel->setValue('latest_available_version', $latestVersion);
                $this->settingModel->setValue('latest_release_notes', $release['body'] ?? '');
                $this->settingModel->setValue('latest_release_url', $release['html_url'] ?? '');
                $this->settingModel->setValue('latest_release_published_at', $release['published_at'] ?? '');
            }

            // If on "latest" channel, also check for untagged commits
            if ($channel === 'latest' && $localSha) {
                $commits = $this->fetchCommitsSince($localSha);

                if ($commits !== null && !empty($commits)) {
                    // If there's no new version release but there ARE new commits, it's a hotfix
                    if (!$result['available']) {
                        $result['available'] = true;
                        $result['type'] = 'hotfix';
                    }

                    $result['commits_behind'] = count($commits);
                    $result['commit_messages'] = array_map(function ($c) {
                        return [
                            'sha' => substr($c['sha'], 0, 7),
                            'message' => $c['commit']['message'] ?? '',
                            'author' => $c['commit']['author']['name'] ?? 'Unknown',
                            'date' => $c['commit']['author']['date'] ?? null,
                        ];
                    }, array_slice($commits, 0, 20)); // Limit to 20 most recent

                    $result['remote_sha'] = $commits[0]['sha'] ?? null;

                    // Cache commit info
                    $this->settingModel->setValue('latest_remote_sha', $result['remote_sha'] ?? '');
                    $this->settingModel->setValue('commits_behind_count', count($commits));
                }
            } elseif ($channel === 'latest' && !$localSha) {
                $result['commit_tracking_unavailable'] = true;
            }

            // Update last check timestamp
            $this->settingModel->setValue('last_update_check', date('Y-m-d H:i:s'));

            $this->logger->info('Update check completed', [
                'available' => $result['available'],
                'type' => $result['type'],
                'latest_version' => $result['latest_version'] ?? 'N/A',
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Update check failed', [
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Failed to check for updates: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Download and apply an update (release or hotfix)
     */
    public function performUpdate(string $type = 'release'): array
    {
        $this->logger->startOperation('Application Update');

        $result = [
            'success' => false,
            'from_version' => $this->settingModel->getAppVersion(),
            'files_updated' => 0,
            'backup_path' => null,
            'errors' => [],
        ];

        try {
            // Step 1: Pre-flight checks
            $this->logger->info('Running pre-flight checks');
            $preflight = $this->preflightChecks();
            if (!$preflight['pass']) {
                $result['errors'] = $preflight['errors'];
                return $result;
            }

            // Step 2: Determine download URL
            $downloadUrl = $this->getDownloadUrl($type);
            if (!$downloadUrl) {
                $result['errors'][] = 'Could not determine download URL for update';
                return $result;
            }

            // Step 3a: Create database backup
            $this->logger->info('Creating database backup');
            $dbBackupResult = $this->createDatabaseBackup();
            if ($dbBackupResult['success']) {
                $result['db_backup_path'] = $dbBackupResult['path'];
                $this->settingModel->setValue('update_db_backup_path', $dbBackupResult['path']);
                $this->logger->info('Database backup created', ['path' => $dbBackupResult['path'], 'method' => $dbBackupResult['method']]);
            } else {
                $this->logger->warning('Database backup skipped: ' . $dbBackupResult['reason']);
                $result['db_backup_warning'] = $dbBackupResult['reason'];
            }

            // Step 3b: Create file backup
            $this->logger->info('Creating file backup');
            $backupPath = $this->createBackup();
            $result['backup_path'] = $backupPath;
            $this->settingModel->setValue('update_backup_path', $backupPath);

            // Step 4: Download the archive
            $this->logger->info('Downloading update', ['url' => $downloadUrl]);
            $archivePath = $this->downloadArchive($downloadUrl);

            // Step 5: Extract to staging directory
            $this->logger->info('Extracting archive');
            $stagingDir = $this->extractArchive($archivePath);

            // Step 5b: Verify extracted archive matches expected commit (integrity check)
            $this->verifyExtractedCommitSha($stagingDir, $type);

            // Step 5c: Check if composer dependencies changed (before we overwrite root)
            $composerChanged = $this->checkComposerChanged($stagingDir);

            // Step 6: Copy files (respecting protected paths)
            $this->logger->info('Applying update files');
            $filesUpdated = $this->applyFiles($stagingDir);
            $result['files_updated'] = $filesUpdated;

            // Step 7: Run composer install if dependencies changed (skip if exec disabled, e.g. cPanel)
            $result['composer_manual_required'] = false;
            if ($composerChanged) {
                if (!$this->canRunShellCommands()) {
                    $this->logger->warning('Composer dependencies changed but shell commands are disabled (e.g. exec in disable_functions). Run composer install manually.');
                    $result['composer_manual_required'] = true;
                } elseif (!$this->runComposerInstall()) {
                    $result['composer_manual_required'] = true;
                }
                if ($result['composer_manual_required']) {
                    $this->logger->info('Composer manual action required. If dependencies changed, run: composer install --no-dev (e.g. via SSH or cPanel Terminal).');
                }
            }

            // Step 8: Update commit SHA tracking
            $this->updateCommitSha();

            // Step 9: Clean up
            $this->cleanup($archivePath, $stagingDir);

            $result['success'] = true;
            // Report the version we actually applied (DB app_version only changes after migrations)
            $result['to_version'] = $type === 'release'
                ? ($this->settingModel->getValue('latest_available_version') ?: $this->settingModel->getAppVersion())
                : ($this->settingModel->getValue('latest_remote_sha') ? substr($this->settingModel->getValue('latest_remote_sha'), 0, 7) : 'latest');

            $this->logger->endOperation('Application Update', [
                'success' => true,
                'files_updated' => $filesUpdated,
                'composer_updated' => $composerChanged,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result['errors'][] = $e->getMessage();

            // Attempt rollback
            if (!empty($result['backup_path'])) {
                $this->logger->info('Attempting rollback');
                try {
                    $this->restoreBackup($result['backup_path']);
                    $result['errors'][] = 'Update failed but rollback was successful';
                } catch (\Exception $rollbackError) {
                    $result['errors'][] = 'Rollback also failed: ' . $rollbackError->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * Rollback to last backup
     */
    public function rollback(): array
    {
        $backupPath = $this->settingModel->getValue('update_backup_path', null);

        if (!$backupPath || !file_exists($backupPath)) {
            return [
                'success' => false,
                'error' => 'No backup available for rollback',
            ];
        }

        try {
            $this->logger->startOperation('Rollback');

            // Restore database first (if backup exists)
            $dbBackupPath = $this->settingModel->getValue('update_db_backup_path', null);
            if ($dbBackupPath && file_exists($dbBackupPath)) {
                $this->logger->info('Restoring database from backup', ['path' => $dbBackupPath]);
                $dbRestored = $this->restoreDatabaseBackup($dbBackupPath);
                if (!$dbRestored) {
                    $this->logger->warning('Database restore failed or skipped. SQL file is still available for manual import.', ['path' => $dbBackupPath]);
                }
            } else {
                $this->logger->info('No database backup found, restoring files only');
            }

            // Restore files
            $this->restoreBackup($backupPath);
            $this->logger->endOperation('Rollback', ['success' => true]);

            return ['success' => true, 'db_restored' => isset($dbRestored) ? $dbRestored : null];
        } catch (\Exception $e) {
            $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // Private: GitHub API methods
    // ========================================================================

    /**
     * Fetch the latest release from GitHub
     */
    private function fetchLatestRelease(): ?array
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/releases/latest';
            $response = $this->httpClient->get($url);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                // No releases yet
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch commits on main since a given SHA
     * Uses the compare API: /repos/{owner}/{repo}/compare/{base}...{head}
     */
    private function fetchCommitsSince(string $sinceCommitSha): ?array
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO
                . '/compare/' . $sinceCommitSha . '...main';
            $response = $this->httpClient->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['status']) && $data['status'] === 'identical') {
                return [];
            }

            return $data['commits'] ?? [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $this->logger->warning('Commit comparison failed - SHA may not exist on remote', [
                    'sha' => substr($sinceCommitSha, 0, 7),
                ]);
                return null;
            }
            throw $e;
        }
    }

    /**
     * Get the latest commit SHA from the main branch
     */
    private function fetchLatestCommitSha(): ?string
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/commits/main';
            $response = $this->httpClient->get($url);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['sha'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch latest commit SHA', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ========================================================================
    // Private: Cache methods
    // ========================================================================

    private function isCacheValid(string $lastCheckTimestamp): bool
    {
        $lastCheck = strtotime($lastCheckTimestamp);
        $ttlSeconds = self::CACHE_TTL_HOURS * 3600;
        return (time() - $lastCheck) < $ttlSeconds;
    }

    private function getCachedResult(string $currentVersion, string $channel, ?string $localSha): array
    {
        $latestVersion = $this->settingModel->getValue('latest_available_version', null);

        $result = [
            'available' => false,
            'type' => null,
            'current_version' => $currentVersion,
            'channel' => $channel,
            'cached' => true,
            'last_check' => $this->settingModel->getValue('last_update_check'),
            'error' => null,
        ];

        if ($latestVersion) {
            $result['latest_version'] = $latestVersion;
            $result['release_notes'] = $this->settingModel->getValue('latest_release_notes', '');
            $result['release_url'] = $this->settingModel->getValue('latest_release_url', '');
            $result['published_at'] = $this->settingModel->getValue('latest_release_published_at', '');

            if (version_compare($latestVersion, $currentVersion, '>')) {
                $result['available'] = true;
                $result['type'] = 'release';
            }
        }

        // Check cached commit info for "latest" channel
        if ($channel === 'latest' && $localSha) {
            $commitsBehind = (int) $this->settingModel->getValue('commits_behind_count', 0);
            if ($commitsBehind > 0 && !$result['available']) {
                $result['available'] = true;
                $result['type'] = 'hotfix';
                $result['commits_behind'] = $commitsBehind;
                $result['remote_sha'] = $this->settingModel->getValue('latest_remote_sha', '');
            }
        }

        return $result;
    }

    // ========================================================================
    // Private: Update process methods
    // ========================================================================

    /**
     * Run pre-flight checks before updating
     */
    private function preflightChecks(): array
    {
        $errors = [];

        // Check PHP extensions
        if (!extension_loaded('zip')) {
            $errors[] = 'PHP zip extension is required for updates';
        }

        // Check write permissions on key directories
        $dirsToCheck = [
            $this->rootPath . '/app',
            $this->rootPath . '/core',
            $this->rootPath . '/public',
            $this->rootPath . '/database',
            $this->rootPath . '/routes',
        ];

        foreach ($dirsToCheck as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $errors[] = "Directory not writable: $dir";
            }
        }

        // Check temp directory is writable
        $tempDir = sys_get_temp_dir();
        if (!is_writable($tempDir)) {
            $errors[] = "System temp directory not writable: $tempDir";
        }

        // Check available disk space (require at least 50MB free)
        $freeSpace = disk_free_space($this->rootPath);
        if ($freeSpace !== false && $freeSpace < 50 * 1024 * 1024) {
            $errors[] = 'Insufficient disk space. At least 50MB required';
        }

        return [
            'pass' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Determine the download URL based on update type
     */
    private function getDownloadUrl(string $type): ?string
    {
        if ($type === 'release') {
            // Use the cached release download URL or fetch fresh
            $release = $this->fetchLatestRelease();
            return $release['zipball_url'] ?? null;
        }

        // For hotfix updates, download the latest main branch as zip
        return self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/zipball/main';
    }

    /**
     * Download archive from URL to temp file
     */
    private function downloadArchive(string $url): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dm_update_') . '.zip';

        $response = $this->httpClient->get($url, [
            'sink' => $tempFile,
            'timeout' => 120,
        ]);

        $fileSize = filesize($tempFile);
        $sha256 = hash_file('sha256', $tempFile);
        $this->logger->info('Archive downloaded', [
            'size_bytes' => $fileSize,
            'sha256' => $sha256,
            'path' => $tempFile,
        ]);

        if ($fileSize < 1000) {
            unlink($tempFile);
            throw new \RuntimeException('Downloaded file is too small - likely an error response');
        }

        return $tempFile;
    }

    /**
     * Extract zip archive to a staging directory
     */
    private function extractArchive(string $archivePath): string
    {
        $stagingDir = sys_get_temp_dir() . '/dm_staging_' . uniqid();
        mkdir($stagingDir, 0755, true);

        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath);

        if ($openResult !== true) {
            throw new \RuntimeException("Failed to open zip archive (error code: $openResult)");
        }

        $zip->extractTo($stagingDir);
        $zip->close();

        // GitHub zipballs have a top-level directory like "Owner-Repo-SHA/"
        // Find it and return the actual content directory
        $entries = scandir($stagingDir);
        $topDir = null;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($stagingDir . '/' . $entry)) {
                $topDir = $stagingDir . '/' . $entry;
                break;
            }
        }

        if (!$topDir) {
            throw new \RuntimeException('Unexpected archive structure - no top-level directory found');
        }

        $this->logger->info('Archive extracted', ['staging_dir' => $topDir]);
        return $topDir;
    }

    /**
     * Get the expected short commit SHA (7 chars) for the update we are applying.
     * Used to verify the downloaded zipball matches the expected ref.
     */
    private function getExpectedShortSha(string $type): ?string
    {
        if ($type === 'hotfix') {
            $fullSha = $this->fetchLatestCommitSha();
            return $fullSha ? substr($fullSha, 0, 7) : null;
        }

        if ($type === 'release') {
            $release = $this->fetchLatestRelease();
            if (empty($release['tag_name'])) {
                return null;
            }
            $tag = $release['tag_name']; // e.g. v1.1.3
            try {
                $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/commits/' . $tag;
                $response = $this->httpClient->get($url);
                $data = json_decode($response->getBody()->getContents(), true);
                $fullSha = $data['sha'] ?? null;
                return $fullSha ? substr($fullSha, 0, 7) : null;
            } catch (\Exception $e) {
                $this->logger->warning('Could not fetch commit SHA for release tag', [
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * Extract the short commit SHA from GitHub zipball top-level folder name.
     * Format is "Owner-Repo-<short_sha>" (e.g. Hosteroid-domain-monitor-abc1234).
     */
    private function getShortShaFromFolderName(string $stagingDirPath): ?string
    {
        $folderName = basename($stagingDirPath);
        $parts = explode('-', $folderName);
        $last = end($parts);
        // GitHub uses 7-char short SHA; could also be 40-char full SHA in some cases
        if (preg_match('/^[a-f0-9]{7,40}$/i', $last)) {
            return strlen($last) >= 7 ? substr($last, 0, 7) : $last;
        }
        return null;
    }

    /**
     * Verify that the extracted archive's folder name matches the expected commit SHA.
     * This ensures we applied the correct ref (tag or main) and detects corrupted/wrong downloads.
     */
    private function verifyExtractedCommitSha(string $stagingDir, string $type): void
    {
        $expectedShort = $this->getExpectedShortSha($type);
        if ($expectedShort === null) {
            $this->logger->warning('Skipping commit SHA verification (could not get expected SHA)');
            return;
        }

        $actualShort = $this->getShortShaFromFolderName($stagingDir);
        if ($actualShort === null) {
            throw new \RuntimeException(
                'Integrity check failed: could not read commit SHA from archive folder name. ' .
                'The download may be corrupted or from an unexpected source.'
            );
        }

        if (strcasecmp($actualShort, $expectedShort) !== 0) {
            throw new \RuntimeException(
                "Integrity check failed: archive commit SHA does not match. " .
                "Expected: {$expectedShort}, got: {$actualShort}. " .
                "The download may be corrupted or from a different ref."
            );
        }

        $this->logger->info('Commit SHA verified', [
            'expected' => $expectedShort,
            'actual' => $actualShort,
            'type' => $type,
        ]);
    }

    /**
     * Copy files from staging to the application root, respecting protected paths
     */
    private function applyFiles(string $stagingDir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($stagingDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath); // Normalize for Windows

            // Skip protected paths
            if ($this->isProtected($relativePath)) {
                continue;
            }

            $targetPath = $this->rootPath . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Ensure parent directory exists
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }

                copy($item->getPathname(), $targetPath);
                $count++;
            }
        }

        $this->logger->info('Files applied', ['count' => $count]);
        return $count;
    }

    /**
     * Check if a relative path is protected from overwriting
     */
    private function isProtected(string $relativePath): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($relativePath === $protected || strpos($relativePath, $protected . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if composer.json or composer.lock changed
     */
    private function checkComposerChanged(string $stagingDir): bool
    {
        $currentLock = $this->rootPath . '/composer.lock';
        $newLock = $stagingDir . '/composer.lock';

        if (!file_exists($currentLock) || !file_exists($newLock)) {
            // If lock file doesn't exist in either place, check composer.json
            $currentJson = $this->rootPath . '/composer.json';
            $newJson = $stagingDir . '/composer.json';

            if (file_exists($currentJson) && file_exists($newJson)) {
                return md5_file($currentJson) !== md5_file($newJson);
            }
            return false;
        }

        return md5_file($currentLock) !== md5_file($newLock);
    }

    /**
     * Check if PHP is allowed to run shell commands (exec, etc.).
     * On cPanel / shared hosting, disable_functions often includes exec.
     */
    private function canRunShellCommands(): bool
    {
        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array('exec', $list, true);
    }

    /**
     * Run composer install --no-dev.
     * Returns true on success, false if skipped or failed (caller may set composer_manual_required).
     */
    private function runComposerInstall(): bool
    {
        $composerPath = $this->findComposer();
        $command = "$composerPath install --no-dev --optimize-autoloader --no-interaction 2>&1";

        $this->logger->info('Running composer install', ['command' => $command, 'cwd' => $this->rootPath]);

        $output = [];
        $returnCode = 0;
        $oldCwd = getcwd();
        try {
            if (!@chdir($this->rootPath)) {
                $this->logger->warning('Could not change to project directory for composer', [
                    'path' => $this->rootPath,
                ]);
                return false;
            }
            exec($command, $output, $returnCode);
        } finally {
            @chdir($oldCwd);
        }

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            $this->logger->error('Composer install failed (run manually if needed)', [
                'return_code' => $returnCode,
                'output' => $outputStr,
            ]);
            return false;
        }

        $this->logger->info('Composer install completed', ['output' => $outputStr]);
        return true;
    }

    /**
     * Find the composer executable
     */
    private function findComposer(): string
    {
        // Check for local composer.phar first
        if (file_exists($this->rootPath . '/composer.phar')) {
            return 'php ' . $this->rootPath . '/composer.phar';
        }

        // Check if composer is in PATH
        $command = PHP_OS_FAMILY === 'Windows' ? 'where composer 2>NUL' : 'which composer 2>/dev/null';
        $output = [];
        exec($command, $output);

        if (!empty($output[0])) {
            return trim($output[0]);
        }

        // Fallback
        return 'composer';
    }

    /**
     * Find a system binary (e.g. mysqldump, mysql) in common paths
     */
    private function findBinary(string $name): ?string
    {
        // Check PATH first
        $command = PHP_OS_FAMILY === 'Windows' ? "where {$name} 2>NUL" : "which {$name} 2>/dev/null";
        $output = [];
        @exec($command, $output);
        if (!empty($output[0]) && is_executable(trim($output[0]))) {
            return trim($output[0]);
        }

        // Common locations on Linux/cPanel hosts
        $commonPaths = [
            "/usr/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/usr/local/mysql/bin/{$name}",
            "/opt/cpanel/composer/bin/{$name}",
            "/usr/sbin/{$name}",
        ];
        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    // ========================================================================
    // Private: Backup and rollback methods
    // ========================================================================

    /**
     * Create a full database backup (.sql file) before updating.
     * Tries mysqldump first; falls back to a pure-PDO dump of all tables.
     * Returns ['success' => bool, 'path' => string|null, 'method' => string, 'reason' => string]
     */
    private function createDatabaseBackup(): array
    {
        $backupDir = $this->rootPath . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        if (empty($database) || empty($username)) {
            return ['success' => false, 'path' => null, 'method' => 'none', 'reason' => 'Database credentials not available'];
        }

        $version = $this->settingModel->getAppVersion();
        $timestamp = date('Y-m-d_His');
        $sqlFile = $backupDir . "/db_backup_v{$version}_{$timestamp}.sql";

        // Try mysqldump first (fastest, most reliable)
        if ($this->canRunShellCommands()) {
            $mysqldumpPath = $this->findBinary('mysqldump');
            if ($mysqldumpPath) {
                $cmd = sprintf(
                    '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers --add-drop-table %s > %s 2>&1',
                    escapeshellarg($mysqldumpPath),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    escapeshellarg($sqlFile)
                );
                exec($cmd, $output, $exitCode);
                if ($exitCode === 0 && file_exists($sqlFile) && filesize($sqlFile) > 0) {
                    return ['success' => true, 'path' => $sqlFile, 'method' => 'mysqldump', 'reason' => ''];
                }
                // mysqldump failed, clean up and fall through to PDO
                if (file_exists($sqlFile)) {
                    @unlink($sqlFile);
                }
            }
        }

        // Fallback: pure PDO dump (works on cPanel/shared hosts without exec)
        try {
            $pdo = \Core\Database::getConnection();
            $handle = fopen($sqlFile, 'w');
            if (!$handle) {
                return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'Could not create SQL file'];
            }

            fwrite($handle, "-- Domain Monitor Database Backup\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Database: {$database}\n");
            fwrite($handle, "-- Method: PDO dump (fallback)\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // DROP + CREATE
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                fwrite($handle, $createStmt['Create Table'] . ";\n\n");

                // Dump rows in batches
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $batchSize = 500;
                for ($offset = 0; $offset < $count; $offset += $batchSize) {
                    $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $values = array_map(function ($val) use ($pdo) {
                            if ($val === null) return 'NULL';
                            return $pdo->quote($val);
                        }, $row);
                        $cols = '`' . implode('`, `', array_keys($row)) . '`';
                        fwrite($handle, "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $values) . ");\n");
                    }
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            if (filesize($sqlFile) > 0) {
                return ['success' => true, 'path' => $sqlFile, 'method' => 'pdo', 'reason' => ''];
            }
            @unlink($sqlFile);
            return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'PDO dump produced empty file'];
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($sqlFile)) {
                @unlink($sqlFile);
            }
            return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'PDO dump failed: ' . $e->getMessage()];
        }
    }

    /**
     * Restore a database backup from a .sql file (used during rollback)
     */
    private function restoreDatabaseBackup(string $sqlFile): bool
    {
        if (!file_exists($sqlFile)) {
            $this->logger->warning('Database backup file not found for restore', ['path' => $sqlFile]);
            return false;
        }

        // Try mysql CLI first
        if ($this->canRunShellCommands()) {
            $mysqlPath = $this->findBinary('mysql');
            if ($mysqlPath) {
                $host = $_ENV['DB_HOST'] ?? 'localhost';
                $port = $_ENV['DB_PORT'] ?? '3306';
                $database = $_ENV['DB_DATABASE'] ?? '';
                $username = $_ENV['DB_USERNAME'] ?? '';
                $password = $_ENV['DB_PASSWORD'] ?? '';

                $cmd = sprintf(
                    '%s --host=%s --port=%s --user=%s --password=%s %s < %s 2>&1',
                    escapeshellarg($mysqlPath),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    escapeshellarg($sqlFile)
                );
                exec($cmd, $output, $exitCode);
                if ($exitCode === 0) {
                    $this->logger->info('Database restored via mysql CLI', ['path' => $sqlFile]);
                    return true;
                }
            }
        }

        // Fallback: execute SQL via PDO (statement by statement)
        try {
            $pdo = \Core\Database::getConnection();
            $sql = file_get_contents($sqlFile);
            if (empty($sql)) {
                return false;
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            // Split on semicolons followed by newline (to avoid breaking on values containing semicolons)
            $statements = preg_split('/;\s*\n/', $sql);
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if (empty($stmt) || strpos($stmt, '--') === 0) continue;
                $pdo->exec($stmt);
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            $this->logger->info('Database restored via PDO', ['path' => $sqlFile]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Database restore via PDO failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create a zip backup of current application files
     */
    private function createBackup(): string
    {
        $backupDir = $this->rootPath . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        // Ensure the directory is writable
        if (!is_writable($backupDir)) {
            @chmod($backupDir, 0775);
        }

        // ZipArchive::close() writes a temp file; point TMPDIR to a writable location
        // so it works on hosts where the system /tmp is not writable by the web user
        $originalTmpDir = getenv('TMPDIR') ?: null;
        putenv('TMPDIR=' . $backupDir);

        $version = $this->settingModel->getAppVersion();
        $timestamp = date('Y-m-d_His');
        $backupFile = $backupDir . "/backup_v{$version}_{$timestamp}.zip";

        $zip = new \ZipArchive();
        if ($zip->open($backupFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            // Restore TMPDIR before throwing
            if ($originalTmpDir !== null) {
                putenv('TMPDIR=' . $originalTmpDir);
            } else {
                putenv('TMPDIR');
            }
            throw new \RuntimeException("Failed to create backup archive: $backupFile");
        }

        // Back up key application directories
        $dirsToBackup = ['app', 'core', 'public', 'database', 'routes', 'cron'];
        $filesToBackup = ['composer.json', 'composer.lock'];

        foreach ($dirsToBackup as $dir) {
            $fullDir = $this->rootPath . '/' . $dir;
            if (is_dir($fullDir)) {
                $this->addDirectoryToZip($zip, $fullDir, $dir);
            }
        }

        foreach ($filesToBackup as $file) {
            $fullFile = $this->rootPath . '/' . $file;
            if (file_exists($fullFile)) {
                $zip->addFile($fullFile, $file);
            }
        }

        $zip->close();

        // Restore original TMPDIR
        if ($originalTmpDir !== null) {
            putenv('TMPDIR=' . $originalTmpDir);
        } else {
            putenv('TMPDIR');
        }

        $this->logger->info('Backup created', [
            'path' => $backupFile,
            'size_bytes' => filesize($backupFile),
        ]);

        return $backupFile;
    }

    /**
     * Recursively add a directory to a zip archive
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $zipPath . '/' . str_replace(
                [$dir . DIRECTORY_SEPARATOR, $dir . '/'],
                '',
                $item->getPathname()
            );
            $relativePath = str_replace('\\', '/', $relativePath); // Zip entries use forward slashes

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
            }
        }
    }

    /**
     * Restore from a backup zip archive
     */
    private function restoreBackup(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new \RuntimeException("Backup file not found: $backupPath");
        }

        $zip = new \ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new \RuntimeException("Failed to open backup archive: $backupPath");
        }

        $zip->extractTo($this->rootPath);
        $zip->close();

        $this->logger->info('Backup restored', ['path' => $backupPath]);
    }

    // ========================================================================
    // Private: Utility methods
    // ========================================================================

    /**
     * Update the stored commit SHA to the latest
     */
    private function updateCommitSha(): void
    {
        $latestSha = $this->fetchLatestCommitSha();
        if ($latestSha) {
            $this->settingModel->setValue('installed_commit_sha', $latestSha);
            $this->logger->info('Updated installed commit SHA', [
                'sha' => substr($latestSha, 0, 7),
            ]);
        }
    }

    /**
     * Clean up temporary files
     */
    private function cleanup(string $archivePath, string $stagingDir): void
    {
        // Remove archive
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        // Remove staging directory
        if (is_dir($stagingDir)) {
            $this->removeDirectory($stagingDir);
        }

        // Also clean parent staging dir if it exists
        $parentStaging = dirname($stagingDir);
        if (strpos(basename($parentStaging), 'dm_staging_') === 0 && is_dir($parentStaging)) {
            $this->removeDirectory($parentStaging);
        }
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Get update channel label for display
     */
    public static function getChannelLabel(string $channel): string
    {
        return match ($channel) {
            'stable' => 'Stable (Releases only)',
            'latest' => 'Latest (Releases + hotfixes)',
            default => ucfirst($channel),
        };
    }

    /**
     * Check if there are pending database migrations
     */
    public function hasPendingMigrations(): bool
    {
        try {
            $pdo = \Core\Database::getConnection();

            // Check if migrations table exists
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM migrations");
            } catch (\Exception $e) {
                return false;
            }

            $executed = [];
            $stmt = $pdo->query("SELECT migration FROM migrations");
            $executed = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            // Scan migrations directory
            $migrationsDir = $this->rootPath . '/database/migrations';
            $files = glob($migrationsDir . '/*.sql');
            $allMigrations = array_map('basename', $files);

            // Filter out the consolidated schema
            $allMigrations = array_filter($allMigrations, function ($m) {
                return strpos($m, '000_') !== 0;
            });

            $pending = array_diff($allMigrations, $executed);
            return !empty($pending);
        } catch (\Exception $e) {
            return false;
        }
    }
}
