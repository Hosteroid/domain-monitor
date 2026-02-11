<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Services\UpdateService;
use App\Services\NotificationService;
use App\Models\Setting;
use App\Services\Logger;

class UpdateController extends Controller
{
    private UpdateService $updateService;
    private Setting $settingModel;
    private Logger $logger;

    public function __construct()
    {
        Auth::requireAdmin();
        $this->updateService = new UpdateService();
        $this->settingModel = new Setting();
        $this->logger = new Logger('updater');
    }

    /**
     * AJAX: Check for updates
     * POST /api/updates/check
     */
    public function check()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $forceCheck = isset($_POST['force']) && $_POST['force'] === '1';
        $result = $this->updateService->checkForUpdate($forceCheck);

        // When manual check finds an update, create in-app notification for admins (once per version/sha)
        if (!empty($result['available']) && empty($result['error'])) {
            $type = $result['type'] ?? 'release';
            $notifiedRelease = $this->settingModel->getValue('last_update_available_notified_release', '');
            $notifiedHotfixSha = $this->settingModel->getValue('last_update_available_notified_hotfix_sha', '');
            $shouldNotify = false;
            if ($type === 'release') {
                $latestVersion = $result['latest_version'] ?? '';
                if ($latestVersion !== '' && $latestVersion !== $notifiedRelease) {
                    $shouldNotify = true;
                    $this->settingModel->setValue('last_update_available_notified_release', $latestVersion);
                }
            } else {
                $remoteSha = $result['remote_sha'] ?? '';
                if ($remoteSha !== '' && $remoteSha !== $notifiedHotfixSha) {
                    $shouldNotify = true;
                    $this->settingModel->setValue('last_update_available_notified_hotfix_sha', $remoteSha);
                }
            }
            if ($shouldNotify) {
                try {
                    $notificationService = new NotificationService();
                    $currentVersion = $result['current_version'] ?? '';
                    $label = ($type === 'release') ? ($result['latest_version'] ?? 'latest') : 'hotfix';
                    $commitsBehind = $result['commits_behind'] ?? null;
                    $notificationService->notifyAdminsUpdateAvailable($currentVersion, $label, $type, $commitsBehind);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to send update-available notification', ['error' => $e->getMessage()]);
                }
            }
        }

        $this->json($result);
    }

    /**
     * Apply an update (download, extract, replace files)
     * POST /settings/updates/apply
     */
    public function apply()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings#updates');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#updates');

        $type = $_POST['update_type'] ?? 'release';

        if (!in_array($type, ['release', 'hotfix'])) {
            $_SESSION['error'] = 'Invalid update type';
            $this->redirect('/settings#updates');
            return;
        }

        $this->logger->info('Update requested by admin', [
            'type' => $type,
            'user_id' => Auth::id(),
        ]);

        $result = $this->updateService->performUpdate($type);

        if ($result['success']) {
            $fromVersion = $result['from_version'];
            $toVersion = $result['to_version'] ?? 'latest';
            $filesUpdated = $result['files_updated'];

            // Check for pending migrations after file update
            $hasMigrations = $this->updateService->hasPendingMigrations();

            // Notify admins
            try {
                $notificationService = new NotificationService();
                $notificationService->notifyAdminsUpgrade(
                    $fromVersion,
                    $toVersion,
                    0,
                    !empty($result['composer_manual_required'])
                );
            } catch (\Exception $e) {
                // Non-critical
                $this->logger->warning('Failed to send upgrade notification', [
                    'error' => $e->getMessage(),
                ]);
            }

            $message = "Update applied successfully! {$filesUpdated} file(s) updated.";
            if (!empty($result['db_backup_warning'])) {
                $message .= ' Note: Database backup was skipped (' . $result['db_backup_warning'] . '). Consider backing up your database manually.';
            }
            if ($hasMigrations) {
                $message .= ' Database migrations are pending - please run them now.';
            }
            if (!empty($result['composer_manual_required'])) {
                $message .= ' Composer could not be run here (e.g. exec disabled on cPanel). If dependencies changed, run "composer install --no-dev" manually via SSH or Terminal.';
            }
            $_SESSION['success'] = $message;
            if ($hasMigrations) {
                $this->redirect('/install/update');
                return;
            }
            $this->redirect('/settings#updates');

        } else {
            $errors = implode('; ', $result['errors']);
            $_SESSION['error'] = "Update failed: {$errors}";
            $this->redirect('/settings#updates');
        }
    }

    /**
     * Rollback to last backup
     * POST /settings/updates/rollback
     */
    public function rollback()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings#updates');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#updates');

        $this->logger->info('Rollback requested by admin', [
            'user_id' => Auth::id(),
        ]);

        $result = $this->updateService->rollback();

        if ($result['success']) {
            $msg = 'Rollback completed successfully. Files have been restored to the previous version.';
            if (isset($result['db_restored'])) {
                $msg .= $result['db_restored']
                    ? ' Database has also been restored from the backup.'
                    : ' Database could not be restored automatically. You can import the SQL backup manually from the backups/ directory.';
            }
            $_SESSION['success'] = $msg;
        } else {
            $_SESSION['error'] = $result['error'] ?? 'Rollback failed';
        }

        $this->redirect('/settings#updates');
    }

    /**
     * Save update preferences (channel + badge) from single form
     * POST /settings/updates/preferences
     */
    public function savePreferences()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings#updates');
            return;
        }

        $this->verifyCsrf('/settings#updates');

        $channel = $_POST['update_channel'] ?? 'stable';
        if (!in_array($channel, ['stable', 'latest'])) {
            $_SESSION['error'] = 'Invalid update channel';
            $this->redirect('/settings#updates');
            return;
        }

        $badgeEnabled = isset($_POST['update_badge_enabled']) && $_POST['update_badge_enabled'] === '1' ? '1' : '0';

        $this->settingModel->setValue('update_channel', $channel);
        $this->settingModel->setValue('update_badge_enabled', $badgeEnabled);

        if ($channel === 'latest') {
            $currentSha = $this->settingModel->getValue('installed_commit_sha', null);
            if (!$currentSha) {
                $_SESSION['info'] = 'Update preferences saved. Note: Commit tracking will begin after the first update is applied.';
            } else {
                $_SESSION['success'] = 'Update preferences saved.';
            }
        } else {
            $_SESSION['success'] = 'Update preferences saved.';
        }

        $this->settingModel->setValue('last_update_check', null);
        $this->redirect('/settings#updates');
    }

    /**
     * Update the update channel preference
     * POST /settings/updates/channel
     */
    public function updateChannel()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings#updates');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/settings#updates');

        $channel = $_POST['update_channel'] ?? 'stable';

        if (!in_array($channel, ['stable', 'latest'])) {
            $_SESSION['error'] = 'Invalid update channel';
            $this->redirect('/settings#updates');
            return;
        }

        $this->settingModel->setValue('update_channel', $channel);

        // If switching to "latest" and no commit SHA is tracked, try to fetch it
        if ($channel === 'latest') {
            $currentSha = $this->settingModel->getValue('installed_commit_sha', null);
            if (!$currentSha) {
                $_SESSION['info'] = 'Update channel set to Latest. Note: Commit tracking will begin after the first update is applied. Until then, only release updates will be detected.';
            } else {
                $_SESSION['success'] = 'Update channel set to Latest. You will now receive both releases and hotfix updates.';
            }
        } else {
            $_SESSION['success'] = 'Update channel set to Stable. You will only receive tagged release updates.';
        }

        // Clear cached check results so next check uses new channel
        $this->settingModel->setValue('last_update_check', null);

        $this->redirect('/settings#updates');
    }

    /**
     * Update the "show update badge in menu" preference
     * POST /settings/updates/badge
     */
    public function updateBadgePreference()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/settings#updates');
            return;
        }

        $this->verifyCsrf('/settings#updates');

        $enabled = isset($_POST['update_badge_enabled']) && $_POST['update_badge_enabled'] === '1' ? '1' : '0';
        $this->settingModel->setValue('update_badge_enabled', $enabled);

        $_SESSION['success'] = $enabled === '1'
            ? 'Update badge will be shown in the top menu when an update is available.'
            : 'Update badge in the top menu is now disabled.';
        $this->redirect('/settings#updates');
    }
}
