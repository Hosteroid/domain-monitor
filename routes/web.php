<?php

use Core\Application;
use Core\Auth;
use App\Controllers\DashboardController;
use App\Controllers\DomainController;
use App\Controllers\NotificationGroupController;
use App\Controllers\AuthController;
use App\Controllers\DebugController;
use App\Controllers\SearchController;
use App\Controllers\TldRegistryController;
use App\Controllers\SettingsController;
use App\Controllers\ProfileController;
use App\Controllers\UserController;
use App\Controllers\InstallerController;
use App\Controllers\NotificationController;
use App\Controllers\ErrorLogController;
use App\Controllers\TwoFactorController;
use App\Controllers\TagController;

$router = Application::$router;

// Installer routes (public - before auth)
$router->get('/install', [InstallerController::class, 'index']);
$router->get('/install/check-database', [InstallerController::class, 'checkDatabase']);
$router->post('/install/run', [InstallerController::class, 'install']);
$router->get('/install/complete', [InstallerController::class, 'complete']);
$router->get('/install/update', [InstallerController::class, 'showUpdate']);
$router->post('/install/update', [InstallerController::class, 'runUpdate']);

// Authentication routes (public)
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/verify-email', [AuthController::class, 'showVerifyEmail']);
$router->get('/resend-verification', [AuthController::class, 'resendVerification']);
$router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password', [AuthController::class, 'showResetPassword']);
$router->post('/reset-password', [AuthController::class, 'resetPassword']);

// Two-Factor Authentication routes (public during verification)
$router->get('/2fa/verify', [TwoFactorController::class, 'showVerify']);
$router->post('/2fa/verify', [TwoFactorController::class, 'verify']);
$router->post('/2fa/send-email-code', [TwoFactorController::class, 'sendEmailCode']);

// Debug route (public - remove in production!)
$router->get('/debug/whois', [DebugController::class, 'whois']);

// Protected routes - require authentication
Auth::require();

// Dashboard
$router->get('/', [DashboardController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index']);

// Search
$router->get('/search', [SearchController::class, 'index']);
$router->get('/api/search/suggest', [SearchController::class, 'suggest']);

// Domains
$router->get('/domains', [DomainController::class, 'index']);
$router->get('/domains/create', [DomainController::class, 'create']);
$router->get('/domains/bulk-add', [DomainController::class, 'bulkAdd']);
$router->post('/domains/bulk-add', [DomainController::class, 'bulkAdd']);
$router->post('/domains/bulk-refresh', [DomainController::class, 'bulkRefresh']);
$router->post('/domains/bulk-delete', [DomainController::class, 'bulkDelete']);
$router->post('/domains/bulk-assign-group', [DomainController::class, 'bulkAssignGroup']);
$router->post('/domains/bulk-toggle-status', [DomainController::class, 'bulkToggleStatus']);
$router->post('/domains/bulk-add-tags', [DomainController::class, 'bulkAddTags']);
$router->post('/domains/bulk-remove-tags', [DomainController::class, 'bulkRemoveTags']);
$router->post('/domains/bulk-remove-specific-tag', [DomainController::class, 'bulkRemoveSpecificTag']);
$router->post('/domains/bulk-assign-existing-tag', [DomainController::class, 'bulkAssignExistingTag']);
$router->post('/domains/get-tags-for-domains', [DomainController::class, 'getTagsForDomains']);
$router->post('/domains/transfer', [DomainController::class, 'transfer']);
$router->post('/domains/bulk-transfer', [DomainController::class, 'bulkTransfer']);
$router->post('/domains/store', [DomainController::class, 'store']);
$router->get('/domains/{id}', [DomainController::class, 'show']);
$router->get('/domains/{id}/edit', [DomainController::class, 'edit']);
$router->post('/domains/{id}/update', [DomainController::class, 'update']);
$router->post('/domains/{id}/update-notes', [DomainController::class, 'updateNotes']);
$router->post('/domains/{id}/refresh', [DomainController::class, 'refresh']);
$router->post('/domains/{id}/delete', [DomainController::class, 'delete']);

// Notification Groups
$router->get('/groups', [NotificationGroupController::class, 'index']);
$router->get('/groups/create', [NotificationGroupController::class, 'create']);
$router->post('/groups/store', [NotificationGroupController::class, 'store']);
$router->get('/groups/{id}/edit', [NotificationGroupController::class, 'edit']);
$router->post('/groups/{id}/update', [NotificationGroupController::class, 'update']);
$router->post('/groups/{id}/delete', [NotificationGroupController::class, 'delete']);
$router->post('/groups/bulk-delete', [NotificationGroupController::class, 'bulkDelete']);
$router->post('/groups/transfer', [NotificationGroupController::class, 'transfer']);
$router->post('/groups/bulk-transfer', [NotificationGroupController::class, 'bulkTransfer']);

// Notification Channels
$router->post('/groups/{group_id}/channels', [NotificationGroupController::class, 'addChannel']);
$router->post('/groups/{group_id}/channels/{id}/delete', [NotificationGroupController::class, 'deleteChannel']);
$router->post('/groups/{group_id}/channels/{id}/toggle', [NotificationGroupController::class, 'toggleChannel']);
$router->post('/channels/test', [NotificationGroupController::class, 'testChannel']);

// TLD Registry
$router->get('/tld-registry', [TldRegistryController::class, 'index']);
$router->get('/tld-registry/{id}', [TldRegistryController::class, 'show']);
$router->post('/tld-registry/import-tld-list', [TldRegistryController::class, 'importTldList']);
$router->post('/tld-registry/import-rdap', [TldRegistryController::class, 'importRdap']);
$router->post('/tld-registry/import-whois', [TldRegistryController::class, 'importWhois']);
$router->post('/tld-registry/start-progressive-import', [TldRegistryController::class, 'startProgressiveImport']);
$router->get('/tld-registry/import-progress/{log_id}', [TldRegistryController::class, 'importProgress']);
$router->get('/tld-registry/api/import-progress', [TldRegistryController::class, 'apiGetImportProgress']);
$router->post('/tld-registry/bulk-delete', [TldRegistryController::class, 'bulkDelete']);
$router->get('/tld-registry/check-updates', [TldRegistryController::class, 'checkUpdates']);
$router->get('/tld-registry/{id}/toggle-active', [TldRegistryController::class, 'toggleActive']);
$router->get('/tld-registry/{id}/refresh', [TldRegistryController::class, 'refresh']);
$router->get('/tld-registry/import-logs', [TldRegistryController::class, 'importLogs']);
$router->get('/api/tld-info', [TldRegistryController::class, 'apiGetTldInfo']);

// Settings
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings/update', [SettingsController::class, 'update']);
$router->post('/settings/update-app', [SettingsController::class, 'updateApp']);
$router->post('/settings/update-email', [SettingsController::class, 'updateEmail']);
$router->post('/settings/update-captcha', [SettingsController::class, 'updateCaptcha']);
$router->post('/settings/update-two-factor', [SettingsController::class, 'updateTwoFactor']);
$router->post('/settings/test-email', [SettingsController::class, 'testEmail']);
$router->post('/settings/test-cron', [SettingsController::class, 'testCron']);
$router->post('/settings/clear-logs', [SettingsController::class, 'clearLogs']);
$router->post('/settings/toggle-isolation', [SettingsController::class, 'toggleIsolationMode']);

// Profile
$router->get('/profile', [ProfileController::class, 'index']);
$router->post('/profile/update', [ProfileController::class, 'update']);
$router->post('/profile/change-password', [ProfileController::class, 'changePassword']);
$router->get('/profile/delete', [ProfileController::class, 'delete']);
$router->get('/profile/resend-verification', [ProfileController::class, 'resendVerification']);
$router->post('/profile/logout-other-sessions', [ProfileController::class, 'logoutOtherSessions']);
$router->post('/profile/logout-session/{sessionId}', [ProfileController::class, 'logoutSession']);
$router->post('/profile/upload-avatar', [ProfileController::class, 'uploadAvatar']);
$router->post('/profile/delete-avatar', [ProfileController::class, 'deleteAvatar']);

// Two-Factor Authentication management (protected)
$router->get('/2fa/setup', [TwoFactorController::class, 'setup']);
$router->post('/2fa/verify-setup', [TwoFactorController::class, 'verifySetup']);
$router->get('/2fa/cancel-setup', [TwoFactorController::class, 'cancelSetup']);
$router->get('/2fa/backup-codes', [TwoFactorController::class, 'backupCodes']);
$router->post('/2fa/disable', [TwoFactorController::class, 'disable']);
$router->post('/2fa/regenerate-backup-codes', [TwoFactorController::class, 'regenerateBackupCodes']);

// Notifications
$router->get('/notifications', [NotificationController::class, 'index']);
$router->get('/notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
$router->get('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
$router->get('/notifications/{id}/delete', [NotificationController::class, 'delete']);
$router->get('/notifications/clear-all', [NotificationController::class, 'clearAll']);
$router->get('/api/notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
$router->get('/api/notifications/recent', [NotificationController::class, 'getRecent']);

// User Management (Admin Only)
$router->get('/users', [UserController::class, 'index']);
$router->get('/users/create', [UserController::class, 'create']);
$router->post('/users/store', [UserController::class, 'store']);
$router->get('/users/{id}/edit', [UserController::class, 'edit']);
$router->post('/users/{id}/update', [UserController::class, 'update']);
$router->post('/users/{id}/delete', [UserController::class, 'delete']);
$router->post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
$router->post('/users/bulk-toggle-status', [UserController::class, 'bulkToggleStatus']);
$router->post('/users/bulk-delete', [UserController::class, 'bulkDelete']);

// Error Logs (Admin Only)
$router->get('/errors', [ErrorLogController::class, 'index']);
$router->get('/errors/{id}', [ErrorLogController::class, 'show']);
$router->post('/errors/{id}/resolve', [ErrorLogController::class, 'markResolved']);
$router->post('/errors/{id}/unresolve', [ErrorLogController::class, 'markUnresolved']);
$router->post('/errors/{id}/delete', [ErrorLogController::class, 'delete']);
$router->post('/errors/bulk-delete', [ErrorLogController::class, 'bulkDelete']);
$router->post('/errors/clear-resolved', [ErrorLogController::class, 'clearResolved']);

// Tag Management
$router->get('/tags', [TagController::class, 'index']);
$router->post('/tags/create', [TagController::class, 'create']);
$router->post('/tags/update', [TagController::class, 'update']);
$router->post('/tags/delete', [TagController::class, 'delete']);
$router->post('/tags/bulk-delete', [TagController::class, 'bulkDelete']);
$router->get('/tags/{id}', [TagController::class, 'show']);
$router->post('/tags/bulk-add-to-domains', [TagController::class, 'bulkAddToDomains']);
$router->post('/tags/bulk-remove-from-domains', [TagController::class, 'bulkRemoveFromDomains']);


