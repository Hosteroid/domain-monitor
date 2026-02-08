<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Services\WhoisService;

class DomainController extends Controller
{
    private Domain $domainModel;
    private NotificationGroup $groupModel;
    private WhoisService $whoisService;

    public function __construct()
    {
        $this->domainModel = new Domain();
        $this->groupModel = new NotificationGroup();
        $this->whoisService = new WhoisService();
    }

    /**
     * Check domain access based on isolation mode
     */
    private function checkDomainAccess(int $id): ?array
    {
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        if ($isolationMode === 'isolated') {
            return $this->domainModel->findWithIsolation($id, $userId);
        } else {
            return $this->domainModel->find($id);
        }
    }

    public function index()
    {
        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get filter parameters
        $search = \App\Helpers\InputValidator::sanitizeSearch($_GET['search'] ?? '', 100);
        $status = $_GET['status'] ?? '';
        $groupId = $_GET['group'] ?? '';
        $tag = $_GET['tag'] ?? '';
        $sortBy = $_GET['sort'] ?? 'domain_name';
        $sortOrder = $_GET['order'] ?? 'asc';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25))); // Between 10 and 100

        // Get expiring threshold from settings
        $notificationDays = $settingModel->getNotificationDays();
        $expiringThreshold = !empty($notificationDays) ? max($notificationDays) : 30;

        // Prepare filters array
        $filters = [
            'search' => $search,
            'status' => $status,
            'group' => $groupId,
            'tag' => $tag
        ];

        // Get filtered and paginated domains using model
        $result = $this->domainModel->getFilteredPaginated($filters, $sortBy, $sortOrder, $page, $perPage, $expiringThreshold, $isolationMode === 'isolated' ? $userId : null);

        // Get groups and tags based on isolation mode
        if ($isolationMode === 'isolated') {
            $groups = $this->groupModel->getAllWithChannelCount($userId);
            $allTags = $this->domainModel->getAllTags($userId);
        } else {
            $groups = $this->groupModel->getAllWithChannelCount();
            $allTags = $this->domainModel->getAllTags();
        }
        
        // Get available tags for bulk operations
        $tagModel = new \App\Models\Tag();
        if ($isolationMode === 'isolated') {
            $availableTags = $tagModel->getAllWithUsage($userId);
        } else {
            $availableTags = $tagModel->getAllWithUsage();
        }
        
        // Format domains for display
        $formattedDomains = \App\Helpers\DomainHelper::formatMultiple($result['domains']);

        // Get users for transfer functionality (admin only)
        $users = [];
        if (\Core\Auth::isAdmin()) {
            $userModel = new \App\Models\User();
            $users = $userModel->all();
        }

        $this->view('domains/index', [
            'domains' => $formattedDomains,
            'groups' => $groups,
            'allTags' => $allTags,
            'availableTags' => $availableTags,
            'users' => $users,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'group' => $groupId,
                'tag' => $tag,
                'sort' => $sortBy,
                'order' => $sortOrder
            ],
            'pagination' => $result['pagination'],
            'title' => 'Domains'
        ]);
    }

    public function create()
    {
        // Get groups based on isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        if ($isolationMode === 'isolated') {
            $groups = $this->groupModel->getAllWithChannelCount($userId);
        } else {
            $groups = $this->groupModel->getAllWithChannelCount();
        }
        
        // Get available tags for the new tag system
        $tagModel = new \App\Models\Tag();
        if ($isolationMode === 'isolated') {
            $availableTags = $tagModel->getAllWithUsage($userId);
        } else {
            $availableTags = $tagModel->getAllWithUsage();
        }

        $this->view('domains/create', [
            'groups' => $groups,
            'availableTags' => $availableTags,
            'title' => 'Add Domain'
        ]);
    }

    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains/create');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains/create');

        $domainName = trim($_POST['domain_name'] ?? '');
        $groupId = !empty($_POST['notification_group_id']) ? (int)$_POST['notification_group_id'] : null;
        $tagsInput = trim($_POST['tags'] ?? '');
        $userId = \Core\Auth::id();

        // Validate
        if (empty($domainName)) {
            $_SESSION['error'] = 'Domain name is required';
            $this->redirect('/domains/create');
            return;
        }

        // Validate domain format
        if (!\App\Helpers\InputValidator::validateDomain($domainName)) {
            $_SESSION['error'] = 'Invalid domain name format (e.g., example.com)';
            $this->redirect('/domains/create');
            return;
        }

        // Validate tags
        $tagValidation = \App\Helpers\InputValidator::validateTags($tagsInput);
        if (!$tagValidation['valid']) {
            $_SESSION['error'] = $tagValidation['error'];
            $this->redirect('/domains/create');
            return;
        }
        $tags = $tagValidation['tags'];

        // Validate notification group in isolation mode
        if ($groupId) {
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            if ($isolationMode === 'isolated') {
                $group = $this->groupModel->find($groupId);
                if (!$group || $group['user_id'] != $userId) {
                    $_SESSION['error'] = 'You can only assign domains to your own notification groups';
                    $this->redirect('/domains/create');
                    return;
                }
            }
        }

        // Check if domain already exists
        if ($this->domainModel->existsByDomain($domainName)) {
            $_SESSION['error'] = 'Domain already exists';
            $this->redirect('/domains/create');
            return;
        }

        // Get WHOIS information
        $whoisData = $this->whoisService->getDomainInfo($domainName);

        if (!$whoisData) {
            $_SESSION['error'] = 'Could not retrieve WHOIS information for this domain';
            $this->redirect('/domains/create');
            return;
        }

        // Create domain
        $status = $this->whoisService->getDomainStatus($whoisData['expiration_date'], $whoisData['status'] ?? [], $whoisData);

        // Warn if domain is available (not registered)
        if ($status === 'available') {
            $_SESSION['warning'] = "Note: '$domainName' appears to be AVAILABLE (not registered). You're monitoring an unregistered domain.";
        }

        $id = $this->domainModel->create([
            'domain_name' => $domainName,
            'notification_group_id' => $groupId,
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $whoisData['expiration_date'],
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData),
            'is_active' => 1,
            'user_id' => $userId
        ]);

        // Handle tags using the new tag system
        if (!empty($tags)) {
            $tagModel = new \App\Models\Tag();
            $tagModel->updateDomainTags($id, $tags, $userId);
        }

        // Log domain creation
        $logger = new \App\Services\Logger();
        $logger->info('Domain created', [
            'domain_id' => $id,
            'domain_name' => $domainName,
            'user_id' => $userId,
            'status' => $status,
            'expiration_date' => $whoisData['expiration_date'],
            'notification_group_id' => $groupId
        ]);

        if ($status !== 'available') {
            $_SESSION['success'] = "Domain '$domainName' added successfully";
        }
        $this->redirect('/domains');
    }

    public function edit($params = [])
    {
        $id = $params['id'] ?? 0;
        
        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get domain with tags and groups
        if ($isolationMode === 'isolated') {
            $domain = $this->domainModel->getWithTagsAndGroups($id, $userId);
        } else {
            $domain = $this->domainModel->getWithTagsAndGroups($id);
        }

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        // Get groups based on isolation mode
        if ($isolationMode === 'isolated') {
            $groups = $this->groupModel->getAllWithChannelCount($userId);
        } else {
            $groups = $this->groupModel->getAllWithChannelCount();
        }
        
        // Get available tags for the new tag system
        $tagModel = new \App\Models\Tag();
        if ($isolationMode === 'isolated') {
            $availableTags = $tagModel->getAllWithUsage($userId);
        } else {
            $availableTags = $tagModel->getAllWithUsage();
        }

        // Get referrer for cancel button
        $referrer = $_GET['from'] ?? '/domains/' . $domain['id'];
        
        $this->view('domains/edit', [
            'domain' => $domain,
            'groups' => $groups,
            'availableTags' => $availableTags,
            'referrer' => $referrer,
            'title' => 'Edit Domain'
        ]);
    }

    public function update($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);
        
        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $userId = \Core\Auth::id();

        $groupId = !empty($_POST['notification_group_id']) ? (int)$_POST['notification_group_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $tagsInput = trim($_POST['tags'] ?? '');
        $manualExpirationDate = !empty($_POST['manual_expiration_date']) ? $_POST['manual_expiration_date'] : null;
        
        // Validate tags
        $tagValidation = \App\Helpers\InputValidator::validateTags($tagsInput);
        if (!$tagValidation['valid']) {
            $_SESSION['error'] = $tagValidation['error'];
            $this->redirect('/domains/' . $id . '/edit');
            return;
        }
        $tags = $tagValidation['tags'];

        // Validate notification group in isolation mode
        if ($groupId) {
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            if ($isolationMode === 'isolated') {
                $group = $this->groupModel->find($groupId);
                if (!$group || $group['user_id'] != $userId) {
                    $_SESSION['error'] = 'You can only assign domains to your own notification groups';
                    $this->redirect('/domains/' . $id . '/edit');
                    return;
                }
            }
        }
        
        // Check if monitoring status changed
        $statusChanged = ($domain['is_active'] != $isActive);
        $oldGroupId = $domain['notification_group_id'];

        $this->domainModel->update($id, [
            'notification_group_id' => $groupId,
            'is_active' => $isActive,
            'expiration_date' => $manualExpirationDate
        ]);

        // Send notification if monitoring status changed and has notification group
        if ($statusChanged && $groupId) {
            $notificationService = new \App\Services\NotificationService();
            
            if ($isActive) {
                // Monitoring activated
                $message = "🟢 Domain monitoring has been ACTIVATED for {$domain['domain_name']}\n\n" .
                          "The domain will now be monitored regularly and you'll receive expiration alerts.";
                $subject = "✅ Monitoring Activated: {$domain['domain_name']}";
            } else {
                // Monitoring deactivated
                $message = "🔴 Domain monitoring has been DEACTIVATED for {$domain['domain_name']}\n\n" .
                          "You will no longer receive alerts for this domain until monitoring is re-enabled.";
                $subject = "⏸️ Monitoring Paused: {$domain['domain_name']}";
            }
            
            $notificationService->sendToGroup($groupId, $subject, $message);
        }
        
        // Also send notification if group changed and monitoring is active
        if (!$statusChanged && $isActive && $oldGroupId != $groupId) {
            $notificationService = new \App\Services\NotificationService();
            
            if ($groupId) {
                // Assigned to new group
                $groupModel = new NotificationGroup();
                $group = $groupModel->find($groupId);
                $groupName = $group ? $group['name'] : 'Unknown Group';
                
                $message = "🔔 Notification group updated for {$domain['domain_name']}\n\n" .
                          "This domain is now assigned to: {$groupName}\n" .
                          "You will receive expiration alerts through this notification group.";
                $subject = "📬 Group Changed: {$domain['domain_name']}";
                
                $notificationService->sendToGroup($groupId, $subject, $message);
            }
        }

        // Handle tags using the new tag system
        if (!empty($tags)) {
            $tagModel = new \App\Models\Tag();
            $tagModel->updateDomainTags($id, $tags, $userId);
        } else {
            // Remove all tags from domain
            $tagModel = new \App\Models\Tag();
            $tagModel->removeAllFromDomain($id);
        }

        $_SESSION['success'] = 'Domain updated successfully';
        $this->redirect('/domains/' . $id);
    }

    public function refresh($params = [])
    {
        $id = $params['id'] ?? 0;
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        // Log domain refresh start
        $logger = new \App\Services\Logger();
        $logger->info('Domain refresh started', [
            'domain_id' => $id,
            'domain_name' => $domain['domain_name'],
            'user_id' => \Core\Auth::id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        // Get fresh WHOIS information
        $whoisData = $this->whoisService->getDomainInfo($domain['domain_name']);

        if (!$whoisData) {
            $logger->error('Domain refresh failed - WHOIS data not retrieved', [
                'domain_id' => $id,
                'domain_name' => $domain['domain_name'],
                'user_id' => \Core\Auth::id()
            ]);
            
            $_SESSION['error'] = 'Could not retrieve WHOIS information';
            // Check if we came from view page
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            if (strpos($referer, '/domains/' . $id) !== false) {
                $this->redirect('/domains/' . $id);
            } else {
                $this->redirect('/domains');
            }
            return;
        }

        // Use WHOIS expiration date if available, otherwise preserve manual expiration date
        $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
        
        $status = $this->whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);

        $this->domainModel->update($id, [
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $expirationDate,
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData)
        ]);

        // Log successful domain refresh
        $logger->info('Domain refresh completed successfully', [
            'domain_id' => $id,
            'domain_name' => $domain['domain_name'],
            'new_status' => $status,
            'registrar' => $whoisData['registrar'],
            'expiration_date' => $whoisData['expiration_date'],
            'user_id' => \Core\Auth::id()
        ]);

        $_SESSION['success'] = 'Domain information refreshed';
        
        // Check if we came from view page or list page
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, '/domains/' . $id) !== false) {
            // Came from view page, go back to view page
            $this->redirect('/domains/' . $id);
        } else {
            // Came from list page, stay on list page
            $this->redirect('/domains');
        }
    }

    public function delete($params = [])
    {
        $id = $params['id'] ?? 0;
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $this->domainModel->delete($id);
        $_SESSION['success'] = 'Domain deleted successfully';
        $this->redirect('/domains');
    }

    public function show($params = [])
    {
        $id = $params['id'] ?? 0;
        
        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get domain with tags and groups
        if ($isolationMode === 'isolated') {
            $domain = $this->domainModel->getWithTagsAndGroups($id, $userId);
        } else {
            $domain = $this->domainModel->getWithTagsAndGroups($id);
        }

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logModel = new \App\Models\NotificationLog();
        $logs = $logModel->getByDomain($id, 20);
        
        // Format domain for display
        $formattedDomain = \App\Helpers\DomainHelper::formatForDisplay($domain);
        
        // Parse WHOIS data for display
        $whoisData = json_decode($domain['whois_data'] ?? '{}', true);
        if (!empty($whoisData['status']) && is_array($whoisData['status'])) {
            $formattedDomain['parsedStatuses'] = \App\Helpers\DomainHelper::parseWhoisStatuses($whoisData['status']);
        } else {
            $formattedDomain['parsedStatuses'] = [];
        }
        
        // Calculate active channel count
        if (!empty($domain['channels'])) {
            $formattedDomain['activeChannelCount'] = \App\Helpers\DomainHelper::getActiveChannelCount($domain['channels']);
        }
        
        // Get available tags for the new tag system
        $tagModel = new \App\Models\Tag();
        if ($isolationMode === 'isolated') {
            $availableTags = $tagModel->getAllWithUsage($userId);
        } else {
            $availableTags = $tagModel->getAllWithUsage();
        }

        $this->view('domains/view', [
            'domain' => $formattedDomain,
            'logs' => $logs,
            'availableTags' => $availableTags,
            'title' => $domain['domain_name']
        ]);
    }

    public function bulkAdd()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Get groups based on isolation mode
            $userId = \Core\Auth::id();
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            if ($isolationMode === 'isolated') {
                $groups = $this->groupModel->getAllWithChannelCount($userId);
            } else {
                $groups = $this->groupModel->getAllWithChannelCount();
            }
            
            // Get available tags for the new tag system
            $tagModel = new \App\Models\Tag();
            if ($isolationMode === 'isolated') {
                $availableTags = $tagModel->getAllWithUsage($userId);
            } else {
                $availableTags = $tagModel->getAllWithUsage();
            }
            
            $this->view('domains/bulk-add', [
                'groups' => $groups,
                'availableTags' => $availableTags,
                'title' => 'Bulk Add Domains'
            ]);
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains/bulk-add');

        // POST - Process bulk add
        $domainsText = trim($_POST['domains'] ?? '');
        $groupId = !empty($_POST['notification_group_id']) ? (int)$_POST['notification_group_id'] : null;
        $tagsInput = trim($_POST['tags'] ?? '');
        $userId = \Core\Auth::id();

        if (empty($domainsText)) {
            $_SESSION['error'] = 'Please enter at least one domain';
            $this->redirect('/domains/bulk-add');
            return;
        }

        // Validate tags
        $tagValidation = \App\Helpers\InputValidator::validateTags($tagsInput);
        if (!$tagValidation['valid']) {
            $_SESSION['error'] = $tagValidation['error'];
            $this->redirect('/domains/bulk-add');
            return;
        }
        $tags = $tagValidation['tags'];

        // Validate notification group in isolation mode
        if ($groupId) {
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            if ($isolationMode === 'isolated') {
                $group = $this->groupModel->find($groupId);
                if (!$group || $group['user_id'] != $userId) {
                    $_SESSION['error'] = 'You can only assign domains to your own notification groups';
                    $this->redirect('/domains/bulk-add');
                    return;
                }
            }
        }

        // Split by new lines and clean
        $domainNames = array_filter(array_map('trim', explode("\n", $domainsText)));
        
        $added = 0;
        $skipped = 0;
        $availableCount = 0;
        $errors = [];
        $userId = \Core\Auth::id();
        
        // Log bulk add start
        $logger = new \App\Services\Logger();
        $logger->info('Bulk domain add started', [
            'user_id' => $userId,
            'domain_count' => count($domainNames),
            'notification_group_id' => $groupId,
            'tags' => $tags
        ]);

        foreach ($domainNames as $domainName) {
            // Skip if already exists
            if ($this->domainModel->existsByDomain($domainName)) {
                $skipped++;
                continue;
            }

            // Get WHOIS information
            $whoisData = $this->whoisService->getDomainInfo($domainName);

            if (!$whoisData) {
                $errors[] = $domainName;
                continue;
            }

            $status = $this->whoisService->getDomainStatus($whoisData['expiration_date'], $whoisData['status'] ?? [], $whoisData);

            // Track available domains
            if ($status === 'available') {
                $availableCount++;
            }

            try {
                $domainId = $this->domainModel->create([
                    'domain_name' => $domainName,
                    'notification_group_id' => $groupId,
                    'registrar' => $whoisData['registrar'],
                    'registrar_url' => $whoisData['registrar_url'] ?? null,
                    'expiration_date' => $whoisData['expiration_date'],
                    'updated_date' => $whoisData['updated_date'] ?? null,
                    'abuse_email' => $whoisData['abuse_email'] ?? null,
                    'last_checked' => date('Y-m-d H:i:s'),
                    'status' => $status,
                    'whois_data' => json_encode($whoisData),
                    'is_active' => 1,
                    'user_id' => \Core\Auth::id()
                ]);

                // Handle tags using the new tag system
                if (!empty($tags) && $domainId) {
                    $tagModel = new \App\Models\Tag();
                    $tagModel->updateDomainTags($domainId, $tags, $userId);
                }

                $added++;
            } catch (\PDOException $e) {
                // Handle duplicate key (race condition between existsByDomain check and insert)
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $skipped++;
                } else {
                    $logger->error('Failed to add domain in bulk', [
                        'domain' => $domainName,
                        'error' => $e->getMessage()
                    ]);
                    $errors[] = $domainName;
                }
            }
        }

        // Log bulk add completion
        $logger->info('Bulk domain add completed', [
            'user_id' => $userId,
            'added' => $added,
            'skipped' => $skipped,
            'errors' => count($errors),
            'available_count' => $availableCount
        ]);

        $message = "Added $added domain(s)";
        if ($skipped > 0) $message .= ", skipped $skipped duplicate(s)";
        if (count($errors) > 0) $message .= ", failed to add " . count($errors) . " domain(s)";

        if ($availableCount > 0) {
            $_SESSION['warning'] = "Note: $availableCount domain(s) appear to be AVAILABLE (not registered).";
        }
        
        $_SESSION['success'] = $message;
        $this->redirect('/domains');
    }

    public function bulkRefresh()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        
        if (empty($domainIds)) {
            $_SESSION['error'] = 'No domains selected';
            $this->redirect('/domains');
            return;
        }

        // Validate bulk operation size
        $sizeError = \App\Helpers\InputValidator::validateArraySize($domainIds, 1000, 'Domain selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Log bulk refresh start
        $logger = new \App\Services\Logger();
        $logger->info('Bulk domain refresh started', [
            'user_id' => $userId,
            'domain_count' => count($domainIds),
            'isolation_mode' => $isolationMode,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $refreshed = 0;
        $failed = 0;

        foreach ($domainIds as $id) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }
            if (!$domain) continue;

            $whoisData = $this->whoisService->getDomainInfo($domain['domain_name']);

            if (!$whoisData) {
                $logger->warning('Bulk refresh failed for domain - WHOIS data not retrieved', [
                    'domain_id' => $id,
                    'domain_name' => $domain['domain_name'] ?? 'unknown',
                    'user_id' => $userId
                ]);
                $failed++;
                continue;
            }

            // Use WHOIS expiration date if available, otherwise preserve manual expiration date
            $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
            
            $status = $this->whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);

            $this->domainModel->update($id, [
                'registrar' => $whoisData['registrar'],
                'registrar_url' => $whoisData['registrar_url'] ?? null,
                'expiration_date' => $expirationDate,
                'updated_date' => $whoisData['updated_date'] ?? null,
                'abuse_email' => $whoisData['abuse_email'] ?? null,
                'last_checked' => date('Y-m-d H:i:s'),
                'status' => $status,
                'whois_data' => json_encode($whoisData)
            ]);

            $refreshed++;
        }

        // Log bulk refresh completion
        $logger->info('Bulk domain refresh completed', [
            'user_id' => $userId,
            'total_domains' => count($domainIds),
            'refreshed' => $refreshed,
            'failed' => $failed,
            'success_rate' => count($domainIds) > 0 ? round(($refreshed / count($domainIds)) * 100, 2) . '%' : '0%'
        ]);

        $_SESSION['success'] = "Refreshed $refreshed domain(s)" . ($failed > 0 ? ", $failed failed" : '');
        $this->redirect('/domains');
    }

    public function bulkDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        
        if (empty($domainIds)) {
            $_SESSION['error'] = 'No domains selected';
            $this->redirect('/domains');
            return;
        }

        // Validate bulk operation size
        $sizeError = \App\Helpers\InputValidator::validateArraySize($domainIds, 1000, 'Domain selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect('/domains');
            return;
        }

        $deleted = 0;
        foreach ($domainIds as $id) {
            if ($this->domainModel->delete($id)) {
                $deleted++;
            }
        }

        $_SESSION['success'] = "Deleted $deleted domain(s)";
        $this->redirect('/domains');
    }

    public function bulkAssignGroup()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $userId = \Core\Auth::id();
        
        if (empty($domainIds)) {
            $_SESSION['error'] = 'No domains selected';
            $this->redirect('/domains');
            return;
        }

        // Validate bulk operation size
        $sizeError = \App\Helpers\InputValidator::validateArraySize($domainIds, 1000, 'Domain selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect('/domains');
            return;
        }

        // Validate notification group in isolation mode
        if ($groupId) {
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            
            if ($isolationMode === 'isolated') {
                $group = $this->groupModel->find($groupId);
                if (!$group || $group['user_id'] != $userId) {
                    $_SESSION['error'] = 'You can only assign domains to your own notification groups';
                    $this->redirect('/domains');
                    return;
                }
            }
        }

        $updated = 0;
        foreach ($domainIds as $id) {
            if ($this->domainModel->update($id, ['notification_group_id' => $groupId])) {
                $updated++;
            }
        }

        $_SESSION['success'] = "Updated $updated domain(s)";
        $this->redirect('/domains');
    }

    public function bulkToggleStatus()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (empty($domainIds)) {
            $_SESSION['error'] = 'No domains selected';
            $this->redirect('/domains');
            return;
        }

        // Validate bulk operation size
        $sizeError = \App\Helpers\InputValidator::validateArraySize($domainIds, 1000, 'Domain selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $updated = 0;
        foreach ($domainIds as $id) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }
            
            if ($domain && $this->domainModel->update($id, ['is_active' => $isActive])) {
                $updated++;
            }
        }

        $status = $isActive ? 'enabled' : 'disabled';
        $_SESSION['success'] = "Monitoring $status for $updated domain(s)";
        $this->redirect('/domains');
    }

    public function updateNotes($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);
        
        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $notes = $_POST['notes'] ?? '';

        // Validate notes length
        $lengthError = \App\Helpers\InputValidator::validateLength($notes, 5000, 'Notes');
        if ($lengthError) {
            $_SESSION['error'] = $lengthError;
            $this->redirect('/domains/' . $id);
            return;
        }

        $this->domainModel->update($id, [
            'notes' => $notes
        ]);

        $_SESSION['success'] = 'Notes updated successfully';
        $this->redirect('/domains/' . $id);
    }

    public function bulkAddTags()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $tagToAdd = trim($_POST['tag'] ?? '');

        if (empty($domainIds) || empty($tagToAdd)) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/domains');
            return;
        }

        // Validate tag format
        if (!preg_match('/^[a-z0-9-]+$/', $tagToAdd)) {
            $_SESSION['error'] = 'Invalid tag format (use only letters, numbers, and hyphens)';
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Initialize Tag model
        $tagModel = new \App\Models\Tag();
        
        // Find or create the tag
        $tag = $tagModel->findByName($tagToAdd, $userId);
        if (!$tag) {
            // Create new tag
            $tagId = $tagModel->create([
                'name' => $tagToAdd,
                'color' => 'bg-gray-100 text-gray-700 border-gray-300',
                'description' => '',
                'user_id' => $userId
            ]);
            if (!$tagId) {
                $_SESSION['error'] = 'Failed to create tag';
                $this->redirect('/domains');
                return;
            }
        } else {
            $tagId = $tag['id'];
        }

        $updated = 0;
        foreach ($domainIds as $id) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }
            if (!$domain) continue;

            // Add tag to domain using Tag model
            if ($tagModel->addToDomain($id, $tagId)) {
                $updated++;
            }
        }

        $_SESSION['success'] = "Tag '$tagToAdd' added to $updated domain(s)";
        $this->redirect('/domains');
    }

    public function bulkRemoveTags()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];

        if (empty($domainIds)) {
            $_SESSION['error'] = 'No domains selected';
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $tagModel = new \App\Models\Tag();
        $updated = 0;
        foreach ($domainIds as $id) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }
            
            if ($domain && $tagModel->removeAllFromDomain($id)) {
                $updated++;
            }
        }

        $_SESSION['success'] = "Tags removed from $updated domain(s)";
        $this->redirect('/domains');
    }

    /**
     * Bulk remove specific tag from domains
     */
    public function bulkRemoveSpecificTag()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $tagId = (int)($_POST['tag_id'] ?? 0);

        if (empty($domainIds) || !$tagId) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/domains');
            return;
        }

        $tagModel = new \App\Models\Tag();
        $tag = $tagModel->find($tagId);
        if (!$tag) {
            $_SESSION['error'] = 'Tag not found';
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $removed = 0;
        foreach ($domainIds as $domainId) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($domainId, $userId);
            } else {
                $domain = $this->domainModel->find($domainId);
            }
            
            if ($domain && $tagModel->removeFromDomain($domainId, $tagId)) {
                $removed++;
            }
        }

        $_SESSION['success'] = "Tag '{$tag['name']}' removed from $removed domain(s)";
        $this->redirect('/domains');
    }

    /**
     * Bulk assign existing tag to domains
     */
    public function bulkAssignExistingTag()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $tagId = (int)($_POST['tag_id'] ?? 0);

        if (empty($domainIds) || !$tagId) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/domains');
            return;
        }

        $tagModel = new \App\Models\Tag();
        $tag = $tagModel->find($tagId);
        if (!$tag) {
            $_SESSION['error'] = 'Tag not found';
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $added = 0;
        foreach ($domainIds as $domainId) {
            // Check domain access based on isolation mode
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($domainId, $userId);
            } else {
                $domain = $this->domainModel->find($domainId);
            }
            
            if ($domain && $tagModel->addToDomain($domainId, $tagId)) {
                $added++;
            }
        }

        $_SESSION['success'] = "Tag '{$tag['name']}' added to $added domain(s)";
        $this->redirect('/domains');
    }

    /**
     * Transfer domain to another user (Admin only)
     */
    public function transfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $domainId = (int)($_POST['domain_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (!$domainId || !$targetUserId) {
            $_SESSION['error'] = 'Invalid domain or user selected';
            $this->redirect('/domains');
            return;
        }

        // Validate domain exists
        $domain = $this->domainModel->find($domainId);
        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        // Validate target user exists
        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/domains');
            return;
        }

        try {
            // Transfer domain
            $this->domainModel->update($domainId, ['user_id' => $targetUserId]);
            
            $_SESSION['success'] = "Domain '{$domain['domain_name']}' transferred to {$targetUser['username']}";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to transfer domain. Please try again.';
        }

        $this->redirect('/domains');
    }

    /**
     * Bulk transfer domains to another user (Admin only)
     */
    public function bulkTransfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (empty($domainIds) || !$targetUserId) {
            $_SESSION['error'] = 'No domains selected or invalid user';
            $this->redirect('/domains');
            return;
        }

        // Validate target user exists
        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/domains');
            return;
        }

        $transferred = 0;
        foreach ($domainIds as $domainId) {
            $domainId = (int)$domainId;
            if ($domainId > 0) {
                try {
                    $this->domainModel->update($domainId, ['user_id' => $targetUserId]);
                    $transferred++;
                } catch (\Exception $e) {
                    // Continue with other domains
                }
            }
        }

        $_SESSION['success'] = "$transferred domain(s) transferred to {$targetUser['username']}";
        $this->redirect('/domains');
    }

    /**
     * Get tags for specific domains (API endpoint)
     */
    public function getTagsForDomains()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['domain_ids']) || !is_array($input['domain_ids'])) {
            $this->json(['error' => 'Invalid domain IDs'], 400);
            return;
        }

        $domainIds = array_map('intval', $input['domain_ids']);
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Get tags that are assigned to the specified domains
        $tags = $this->domainModel->getTagsForDomains($domainIds, $isolationMode === 'isolated' ? $userId : null);
        
        $this->json(['tags' => $tags]);
    }
}

