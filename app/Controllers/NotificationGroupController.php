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

