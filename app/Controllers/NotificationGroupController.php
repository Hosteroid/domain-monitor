<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\NotificationGroup;
use App\Models\NotificationChannel;

class NotificationGroupController extends Controller
{
    private NotificationGroup $groupModel;
    private NotificationChannel $channelModel;

    public function __construct()
    {
        $this->groupModel = new NotificationGroup();
        $this->channelModel = new NotificationChannel();
    }

    /**
     * Check group access based on isolation mode
     */
    private function checkGroupAccess(int $id): ?array
    {
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        if ($isolationMode === 'isolated') {
            return $this->groupModel->getWithDetails($id, $userId);
        } else {
            return $this->groupModel->getWithDetails($id);
        }
    }

    public function index()
    {
        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get groups based on isolation mode
        if ($isolationMode === 'isolated') {
            $groups = $this->groupModel->getAllWithChannelCount($userId);
        } else {
            $groups = $this->groupModel->getAllWithChannelCount();
        }

        // Get users for transfer functionality (admin only)
        $users = [];
        if (\Core\Auth::isAdmin()) {
            $userModel = new \App\Models\User();
            $users = $userModel->all();
        }

        $this->view('groups/index', [
            'groups' => $groups,
            'users' => $users,
            'title' => 'Notification Groups'
        ]);
    }

    /**
     * Export notification groups with channels as CSV or JSON (secrets masked)
     */
    public function export()
    {
        $logger = new \App\Services\Logger('export');

        try {
            $userId = \Core\Auth::id();
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            $format = $_GET['format'] ?? 'csv';
            $logger->info("Groups export started", ['format' => $format, 'user_id' => $userId]);

            if (!in_array($format, ['csv', 'json'])) {
                $_SESSION['error'] = 'Invalid export format';
                $this->redirect('/groups');
                return;
            }

            // Get groups
            if ($isolationMode === 'isolated') {
                $groups = $this->groupModel->getAllWithChannelCount($userId);
            } else {
                $groups = $this->groupModel->getAllWithChannelCount();
            }

            $exportData = [];
            foreach ($groups as $group) {
                $channels = $this->channelModel->getByGroupId($group['id']);
                $maskedChannels = [];
                foreach ($channels as $ch) {
                    $config = json_decode($ch['channel_config'], true) ?? [];
                    $maskedConfig = $this->maskChannelConfig($ch['channel_type'], $config);
                    $maskedChannels[] = [
                        'channel_type' => $ch['channel_type'],
                        'channel_config' => $maskedConfig,
                        'is_active' => (bool)$ch['is_active']
                    ];
                }

                $exportData[] = [
                    'group_name' => $group['name'],
                    'group_description' => $group['description'] ?? '',
                    'channels' => $maskedChannels
                ];
            }

            $date = date('Y-m-d');
            $filename = "notification_groups_export_{$date}";

            // Clean any prior output buffers to prevent header conflicts
            while (ob_get_level()) {
                ob_end_clean();
            }

            if ($format === 'json') {
                header('Content-Type: application/json');
                header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                // Build CSV in memory — flatten groups with channels into rows
                $csvRows = [];
                foreach ($exportData as $group) {
                    if (empty($group['channels'])) {
                        $csvRows[] = ['group_name' => $group['group_name'], 'group_description' => $group['group_description'], 'channel_type' => '', 'channel_config' => '', 'is_active' => ''];
                    } else {
                        foreach ($group['channels'] as $ch) {
                            $csvRows[] = [
                                'group_name' => $group['group_name'],
                                'group_description' => $group['group_description'],
                                'channel_type' => $ch['channel_type'],
                                'channel_config' => json_encode($ch['channel_config']),
                                'is_active' => $ch['is_active'] ? '1' : '0'
                            ];
                        }
                    }
                }

                $csvContent = $this->buildCsv($csvRows, ['group_name', 'group_description', 'channel_type', 'channel_config', 'is_active']);
                $logger->info("CSV content built", ['bytes' => strlen($csvContent)]);

                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                header('Content-Length: ' . strlen($csvContent));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo $csvContent;
            }

            $logger->info("Groups export completed successfully");
            exit;
        } catch (\Throwable $e) {
            $logger->error("Groups export failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
            $this->redirect('/groups');
        }
    }

    /**
     * Build CSV string in memory from array data
     */
    private function buildCsv(array $rows, array $headers): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Import notification groups from CSV or JSON file
     */
    public function import()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        $this->verifyCsrf('/groups');

        $validChannelTypes = ['email', 'telegram', 'discord', 'slack', 'mattermost', 'webhook', 'pushover'];

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Please select a valid file to import';
            $this->redirect('/groups');
            return;
        }

        $file = $_FILES['import_file'];

        if ($file['size'] > 2097152) {
            $_SESSION['error'] = 'File is too large. Maximum size is 2MB';
            $this->redirect('/groups');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $_SESSION['error'] = 'Invalid file type. Please upload a CSV or JSON file';
            $this->redirect('/groups');
            return;
        }

        $content = file_get_contents($file['tmp_name']);
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $groupsCreated = 0;
        $channelsCreated = 0;
        $groupsSkipped = 0;

        if ($ext === 'json') {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $_SESSION['error'] = 'Invalid JSON file';
                $this->redirect('/groups');
                return;
            }

            foreach ($parsed as $groupData) {
                $groupName = trim($groupData['group_name'] ?? '');
                if (empty($groupName)) continue;

                // Check if group already exists
                $existing = $this->groupModel->findByName($groupName, $isolationMode === 'isolated' ? $userId : null);
                if ($existing) {
                    $groupsSkipped++;
                    continue;
                }

                $groupId = $this->groupModel->create([
                    'name' => $groupName,
                    'description' => trim($groupData['group_description'] ?? ''),
                    'user_id' => $isolationMode === 'isolated' ? $userId : null
                ]);

                if ($groupId && !empty($groupData['channels'])) {
                    foreach ($groupData['channels'] as $ch) {
                        $channelType = $ch['channel_type'] ?? '';
                        $config = $ch['channel_config'] ?? [];
                        if (empty($channelType) || !in_array($channelType, $validChannelTypes)) continue;

                        // Channels with masked secrets are created as inactive
                        $hasMasked = $this->configHasMaskedValues($config);

                        $this->channelModel->create([
                            'notification_group_id' => $groupId,
                            'channel_type' => $channelType,
                            'channel_config' => json_encode($config),
                            'is_active' => $hasMasked ? 0 : ((int)($ch['is_active'] ?? 1))
                        ]);
                        $channelsCreated++;
                    }
                }
                $groupsCreated++;
            }
        } else {
            // CSV: group rows by group_name
            $lines = array_filter(explode("\n", $content));
            $header = null;
            $csvGroups = [];

            foreach ($lines as $line) {
                $row = str_getcsv(trim($line), ',', '"', '\\');
                if (!$header) {
                    $header = array_map('strtolower', array_map('trim', $row));
                    continue;
                }
                $item = [];
                foreach ($header as $i => $col) {
                    $item[$col] = $row[$i] ?? '';
                }
                $gName = trim($item['group_name'] ?? '');
                if (empty($gName)) continue;

                if (!isset($csvGroups[$gName])) {
                    $csvGroups[$gName] = [
                        'description' => trim($item['group_description'] ?? ''),
                        'channels' => []
                    ];
                }
                $chType = trim($item['channel_type'] ?? '');
                if (!empty($chType) && in_array($chType, $validChannelTypes)) {
                    $config = json_decode($item['channel_config'] ?? '{}', true) ?: [];
                    $csvGroups[$gName]['channels'][] = [
                        'channel_type' => $chType,
                        'channel_config' => $config,
                        'is_active' => $item['is_active'] ?? '1'
                    ];
                }
            }

            foreach ($csvGroups as $gName => $gData) {
                $existing = $this->groupModel->findByName($gName, $isolationMode === 'isolated' ? $userId : null);
                if ($existing) {
                    $groupsSkipped++;
                    continue;
                }

                $groupId = $this->groupModel->create([
                    'name' => $gName,
                    'description' => $gData['description'],
                    'user_id' => $isolationMode === 'isolated' ? $userId : null
                ]);

                if ($groupId) {
                    foreach ($gData['channels'] as $ch) {
                        $config = $ch['channel_config'] ?? [];
                        $hasMasked = $this->configHasMaskedValues($config);

                        $this->channelModel->create([
                            'notification_group_id' => $groupId,
                            'channel_type' => $ch['channel_type'],
                            'channel_config' => json_encode($config),
                            'is_active' => $hasMasked ? 0 : ((int)($ch['is_active'] ?? 1))
                        ]);
                        $channelsCreated++;
                    }
                    $groupsCreated++;
                }
            }
        }

        $msg = "{$groupsCreated} group(s) imported ({$channelsCreated} channels)";
        if ($groupsSkipped > 0) $msg .= ", {$groupsSkipped} skipped (already exist)";
        $_SESSION['success'] = $msg;
        $this->redirect('/groups');
    }

    /**
     * Mask sensitive values in channel config for export
     */
    private function maskChannelConfig(string $type, array $config): array
    {
        $masked = $config;
        $sensitiveKeys = ['bot_token', 'api_token', 'user_key', 'pushover_api_token', 'pushover_user_key'];
        $urlKeys = ['webhook_url', 'discord_webhook_url', 'slack_webhook_url', 'mattermost_webhook_url'];

        foreach ($sensitiveKeys as $key) {
            if (!empty($masked[$key])) {
                $val = $masked[$key];
                $masked[$key] = '****' . substr($val, -4);
            }
        }

        foreach ($urlKeys as $key) {
            if (!empty($masked[$key])) {
                $parsed = parse_url($masked[$key]);
                if ($parsed && isset($parsed['host'])) {
                    $scheme = $parsed['scheme'] ?? 'https';
                    $masked[$key] = "{$scheme}://{$parsed['host']}/****";
                }
            }
        }

        // Email is not masked
        return $masked;
    }

    /**
     * Check if config contains masked placeholder values
     */
    private function configHasMaskedValues(array $config): bool
    {
        foreach ($config as $value) {
            if (is_string($value) && (str_contains($value, '****'))) {
                return true;
            }
        }
        return false;
    }

    public function create()
    {
        $this->view('groups/create', [
            'title' => 'Create Notification Group'
        ]);
    }

    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups/create');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/groups/create');

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $_SESSION['error'] = 'Group name is required';
            $this->redirect('/groups/create');
            return;
        }

        // Validate length
        $nameError = \App\Helpers\InputValidator::validateLength($name, 255, 'Group name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect('/groups/create');
            return;
        }

        $descError = \App\Helpers\InputValidator::validateLength($description, 1000, 'Description');
        if ($descError) {
            $_SESSION['error'] = $descError;
            $this->redirect('/groups/create');
            return;
        }

        try {
            // Get current user and isolation mode
            $userId = \Core\Auth::id();
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            $groupData = [
                'name' => $name,
                'description' => $description
            ];
            
            // Assign to current user if in isolated mode
            if ($isolationMode === 'isolated') {
                $groupData['user_id'] = $userId;
            }
            
            $id = $this->groupModel->create($groupData);

            // Log group creation
            $logger = new \App\Services\Logger();
            $logger->info('Notification group created', [
                'group_id' => $id,
                'group_name' => $name,
                'user_id' => $userId,
                'isolation_mode' => $isolationMode
            ]);

            $_SESSION['success'] = "Group '$name' created successfully";
            $this->redirect("/groups/$id/edit");
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to create notification group. Please try again.';
            $this->redirect('/groups/create');
        }
    }

    public function edit($params = [])
    {
        $id = $params['id'] ?? 0;
        $group = $this->checkGroupAccess($id);

        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }

        $this->view('groups/edit', [
            'group' => $group,
            'title' => 'Edit Group: ' . $group['name']
        ]);
    }

    public function update($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/groups');

        $id = $params['id'] ?? 0;
        $group = $this->checkGroupAccess($id);
        
        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }
        
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $_SESSION['error'] = 'Group name is required';
            $this->redirect("/groups/$id/edit");
            return;
        }

        // Validate length
        $nameError = \App\Helpers\InputValidator::validateLength($name, 255, 'Group name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect("/groups/$id/edit");
            return;
        }

        $descError = \App\Helpers\InputValidator::validateLength($description, 1000, 'Description');
        if ($descError) {
            $_SESSION['error'] = $descError;
            $this->redirect("/groups/$id/edit");
            return;
        }

        try {
            $this->groupModel->update($id, [
                'name' => $name,
                'description' => $description
            ]);

            // Log group update
            $logger = new \App\Services\Logger();
            $logger->info('Notification group updated', [
                'group_id' => $id,
                'group_name' => $name,
                'user_id' => \Core\Auth::id()
            ]);

            $_SESSION['success'] = 'Group updated successfully';
            $this->redirect("/groups/$id/edit");
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to update notification group. Please try again.';
            $this->redirect("/groups/$id/edit");
        }
    }

    public function delete($params = [])
    {
        $id = $params['id'] ?? 0;
        $group = $this->checkGroupAccess($id);

        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }

        try {
            // Log group deletion
            $logger = new \App\Services\Logger();
            $logger->info('Notification group deleted', [
                'group_id' => $id,
                'group_name' => $group['name'],
                'user_id' => \Core\Auth::id()
            ]);

            $this->groupModel->deleteWithRelations($id);
            $_SESSION['success'] = 'Group deleted successfully';
            $this->redirect('/groups');
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to delete notification group. Please try again.';
            $this->redirect('/groups');
        }
    }

    public function addChannel($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/groups');

        $groupId = $params['group_id'] ?? 0;
        
        // Check group access
        $group = $this->checkGroupAccess($groupId);
        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }
        
        $channelType = $_POST['channel_type'] ?? '';

        // Validate channel type
        if (empty($channelType)) {
            $_SESSION['error'] = 'Please select a channel type';
            $this->redirect("/groups/$groupId/edit");
            return;
        }

        $config = $this->buildChannelConfig($channelType, $_POST);

        if (!$config) {
            $missingField = '';
            switch ($channelType) {
                case 'email':
                    $missingField = 'email address';
                    break;
                case 'telegram':
                    $missingField = empty($_POST['bot_token']) ? 'bot token' : 'chat ID';
                    break;
                case 'discord':
                case 'slack':
                case 'webhook':
                    $missingField = 'webhook URL';
                    break;
                case 'pushover':
                    $missingField = empty($_POST['pushover_api_token']) ? 'API token' : 'user key';
                    break;
            }
            
            $_SESSION['error'] = "Invalid channel configuration: Missing {$missingField}";
            $this->redirect("/groups/$groupId/edit");
            return;
        }

        try {
            $this->channelModel->createChannel($groupId, $channelType, $config);
            $_SESSION['success'] = 'Channel added successfully';
            $this->redirect("/groups/$groupId/edit");
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to add notification channel. Please try again.';
            $this->redirect("/groups/$groupId/edit");
        }
    }

    public function deleteChannel($params = [])
    {
        $id = $params['id'] ?? 0;
        $groupId = $params['group_id'] ?? 0;

        // Check group access
        $group = $this->checkGroupAccess($groupId);
        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }

        try {
            $this->channelModel->delete($id);
            $_SESSION['success'] = 'Channel deleted successfully';
            $this->redirect("/groups/$groupId/edit");
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to delete notification channel. Please try again.';
            $this->redirect("/groups/$groupId/edit");
        }
    }

    public function toggleChannel($params = [])
    {
        $id = $params['id'] ?? 0;
        $groupId = $params['group_id'] ?? 0;

        // Check group access
        $group = $this->checkGroupAccess($groupId);
        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }

        try {
            $this->channelModel->toggleActive($id);
            $_SESSION['success'] = 'Channel status updated';
            $this->redirect("/groups/$groupId/edit");
        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $_SESSION['error'] = 'Failed to update channel status. Please try again.';
            $this->redirect("/groups/$groupId/edit");
        }
    }

    public function testChannel()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/groups');

        $channelType = $_POST['channel_type'] ?? '';
        $config = $this->buildChannelConfig($channelType, $_POST);

        // Check if this is an AJAX request
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$config) {
            if ($isAjax) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid channel configuration for testing']);
                return;
            } else {
                $_SESSION['error'] = 'Invalid channel configuration for testing';
                $groupId = $_POST['group_id'] ?? 0;
                $this->redirect($groupId ? "/groups/$groupId/edit" : '/groups');
                return;
            }
        }

        try {
            $notificationService = new \App\Services\NotificationService();
            $testMessage = $this->getTestMessage($channelType);
            $testData = $this->getTestData();

            $success = $notificationService->send($channelType, $config, $testMessage, $testData);

            if ($success) {
                $message = "Test message sent successfully to {$channelType} channel! Check your {$channelType} for the test notification.";
                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => $message]);
                    return;
                } else {
                    $_SESSION['success'] = $message;
                }
            } else {
                $message = "Failed to send test message to {$channelType} channel. Please check your configuration and try again.";
                if ($isAjax) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => $message]);
                    return;
                } else {
                    $_SESSION['error'] = $message;
                }
            }

        } catch (\Exception $e) {
            // Log the error using the ErrorHandler service
            $errorHandler = new \App\Services\ErrorHandler();
            $errorHandler->handleException($e);
            
            $message = "Test failed: " . $e->getMessage() . " Please check your configuration and try again.";
            if ($isAjax) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $message]);
                return;
            } else {
                $_SESSION['error'] = $message;
            }
        }

        // Only redirect if not AJAX
        if (!$isAjax) {
            $groupId = $_POST['group_id'] ?? 0;
            $this->redirect("/groups/$groupId/edit");
        }
    }

    private function getTestMessage(string $channelType): string
    {
        $channelNames = [
            'email' => 'Email',
            'telegram' => 'Telegram',
            'discord' => 'Discord',
            'slack' => 'Slack',
            'mattermost' => 'Mattermost',
            'pushover' => 'Pushover',
            'webhook' => 'Webhook'
        ];

        $channelName = $channelNames[$channelType] ?? ucfirst($channelType);
        
        return "🧪 **Test Message from Domain Monitor**\n\n" .
               "This is a test notification to verify your {$channelName} channel configuration.\n\n" .
               "✅ If you're seeing this message, your {$channelName} integration is working correctly!\n\n" .
               "Test sent at: " . date('Y-m-d H:i:s T');
    }

    private function getTestData(): array
    {
        return [
            'domain' => 'example.com',
            'days_left' => 30,
            'expiration_date' => date('Y-m-d', strtotime('+30 days')),
            'registrar' => 'Example Registrar',
            'domain_id' => 1
        ];
    }

    private function buildChannelConfig(string $type, array $data): ?array
    {
        switch ($type) {
            case 'email':
                $email = trim($data['email'] ?? '');
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }
                return ['email' => $email];

            case 'telegram':
                $botToken = trim($data['bot_token'] ?? '');
                $chatId = trim($data['chat_id'] ?? '');
                if (empty($botToken) || empty($chatId)) {
                    return null;
                }
                // Basic validation for bot token format
                if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $botToken)) {
                    return null;
                }
                return [
                    'bot_token' => $botToken,
                    'chat_id' => $chatId
                ];

            case 'discord':
                $webhookUrl = trim($data['discord_webhook_url'] ?? '');
                if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                    return null;
                }
                // Validate Discord webhook URL format
                if (!str_contains($webhookUrl, 'discord.com/api/webhooks/')) {
                    return null;
                }
                return ['webhook_url' => $webhookUrl];

            case 'slack':
                $webhookUrl = trim($data['slack_webhook_url'] ?? '');
                if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                    return null;
                }
                // Validate Slack webhook URL format
                if (!str_contains($webhookUrl, 'hooks.slack.com/services/')) {
                    return null;
                }
                return ['webhook_url' => $webhookUrl];

            case 'mattermost':
                $webhookUrl = trim($data['mattermost_webhook_url'] ?? '');
                if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                    return null;
                }
                // Validate Mattermost webhook URL format
                if (!str_contains($webhookUrl, '/hooks/')) {
                    return null;
                }
                return ['webhook_url' => $webhookUrl];

            case 'pushover':
                $apiToken = trim($data['pushover_api_token'] ?? '');
                $userKey = trim($data['pushover_user_key'] ?? '');
                
                // Both API token and user key are required
                if (empty($apiToken) || empty($userKey)) {
                    return null;
                }
                
                // Basic validation for Pushover token format (30 characters, alphanumeric)
                if (!preg_match('/^[a-zA-Z0-9]{30}$/', $apiToken) || !preg_match('/^[a-zA-Z0-9]{30}$/', $userKey)) {
                    return null;
                }
                
                $config = [
                    'api_token' => $apiToken,
                    'user_key' => $userKey
                ];
                
                // Optional: Device name
                $device = trim($data['pushover_device'] ?? '');
                if (!empty($device)) {
                    $config['device'] = $device;
                }
                
                // Optional: Sound
                $sound = trim($data['pushover_sound'] ?? '');
                if (!empty($sound)) {
                    $config['sound'] = $sound;
                }
                
                return $config;

            case 'webhook':
                $webhookUrl = trim($data['webhook_url'] ?? '');
                if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                    return null;
                }
                // Optional: Allow any HTTPS URL; prefer HTTPS for security
                if (!str_starts_with($webhookUrl, 'https://') && !str_starts_with($webhookUrl, 'http://')) {
                    return null;
                }
                
                $config = ['webhook_url' => $webhookUrl];
                
                // Add format option (generic, google_chat, simple_text)
                $format = trim($data['webhook_format'] ?? 'generic');
                $validFormats = ['generic', 'google_chat', 'simple_text'];
                if (in_array($format, $validFormats)) {
                    $config['format'] = $format;
                }
                
                // Validate Google Chat webhook URL format if that format is selected
                if ($format === 'google_chat' && !str_contains($webhookUrl, 'chat.googleapis.com')) {
                    // Allow it but log a warning - user might have a proxy
                    $logger = new \App\Services\Logger();
                    $logger->warning('Google Chat format selected but URL does not appear to be a Google Chat webhook', [
                        'url' => $webhookUrl
                    ]);
                }
                
                return $config;

            default:
                return null;
        }
    }

    /**
     * Bulk delete notification groups
     */
    public function bulkDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        $this->verifyCsrf('/groups');

        $groupIdsJson = $_POST['group_ids'] ?? '[]';
        $groupIds = json_decode($groupIdsJson, true);

        if (empty($groupIds) || !is_array($groupIds)) {
            $_SESSION['error'] = 'No groups selected for deletion';
            $this->redirect('/groups');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $deletedCount = 0;
        $errors = [];

        foreach ($groupIds as $groupId) {
            try {
                // Check group access based on isolation mode
                if ($isolationMode === 'isolated') {
                    $group = $this->groupModel->getWithDetails((int)$groupId, $userId);
                } else {
                    $group = $this->groupModel->getWithDetails((int)$groupId);
                }
                
                if ($group) {
                    $this->groupModel->deleteWithRelations((int)$groupId);
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                // Log individual errors but continue processing
                $errorHandler = new \App\Services\ErrorHandler();
                $errorHandler->handleException($e);
                $errors[] = "Failed to delete group ID: $groupId";
            }
        }

        if ($deletedCount > 0) {
            if (empty($errors)) {
                $_SESSION['success'] = "Successfully deleted $deletedCount notification group(s)";
            } else {
                $_SESSION['warning'] = "Deleted $deletedCount group(s), but " . count($errors) . " failed. Check error logs for details.";
            }
        } else {
            $_SESSION['error'] = 'Failed to delete any groups. Please check error logs for details.';
        }

        $this->redirect('/groups');
    }

    /**
     * Transfer group to another user (Admin only)
     */
    public function transfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        $this->verifyCsrf('/groups');

        $groupId = (int)($_POST['group_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (!$groupId || !$targetUserId) {
            $_SESSION['error'] = 'Invalid group or user selected';
            $this->redirect('/groups');
            return;
        }

        // Validate group exists
        $group = $this->groupModel->find($groupId);
        if (!$group) {
            $_SESSION['error'] = 'Group not found';
            $this->redirect('/groups');
            return;
        }

        // Validate target user exists
        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/groups');
            return;
        }

        try {
            // Transfer group
            $this->groupModel->update($groupId, ['user_id' => $targetUserId]);
            
            // Also transfer all domains in this group
            $domainModel = new \App\Models\Domain();
            $domainModel->updateWhere(['notification_group_id' => $groupId], ['user_id' => $targetUserId]);
            
            $_SESSION['success'] = "Group '{$group['name']}' and its domains transferred to {$targetUser['username']}";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to transfer group. Please try again.';
        }

        $this->redirect('/groups');
    }

    /**
     * Bulk transfer groups to another user (Admin only)
     */
    public function bulkTransfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/groups');
            return;
        }

        $this->verifyCsrf('/groups');

        $groupIds = $_POST['group_ids'] ?? [];
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (empty($groupIds) || !$targetUserId) {
            $_SESSION['error'] = 'No groups selected or invalid user';
            $this->redirect('/groups');
            return;
        }

        // Validate target user exists
        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/groups');
            return;
        }

        $transferred = 0;
        foreach ($groupIds as $groupId) {
            $groupId = (int)$groupId;
            if ($groupId > 0) {
                try {
                    // Transfer group
                    $this->groupModel->update($groupId, ['user_id' => $targetUserId]);
                    
                    // Also transfer all domains in this group
                    $domainModel = new \App\Models\Domain();
                    $domainModel->updateWhere(['notification_group_id' => $groupId], ['user_id' => $targetUserId]);
                    
                    $transferred++;
                } catch (\Exception $e) {
                    // Continue with other groups
                }
            }
        }

        $_SESSION['success'] = "$transferred group(s) and their domains transferred to {$targetUser['username']}";
        $this->redirect('/groups');
    }
}

