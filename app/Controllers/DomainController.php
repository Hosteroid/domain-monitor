<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Models\SslCertificate;
use App\Services\WhoisService;
use App\Services\SslService;

class DomainController extends Controller
{
    private Domain $domainModel;
    private NotificationGroup $groupModel;
    private WhoisService $whoisService;
    private SslCertificate $sslCertificateModel;
    private SslService $sslService;

    /** Optional columns of the domains list, in the order of its cells; each key is also the sort key */
    private const LIST_COLUMNS = [
        'registrar' => 'Registrar',
        'nameservers' => 'Nameservers',
        'privacy' => 'Privacy',
        'creation_date' => 'Creation Date',
        'expiration_date' => 'Expiration',
        'status' => 'Status',
        'group_name' => 'Group',
        'created_at' => 'Date Added',
        'last_checked' => 'Last Checked',
    ];

    /** The columns shown until someone picks their own */
    private const DEFAULT_LIST_COLUMNS = ['registrar', 'expiration_date', 'status', 'group_name', 'last_checked'];

    public function __construct()
    {
        $this->domainModel = new Domain();
        $this->groupModel = new NotificationGroup();
        $this->whoisService = new WhoisService();
        $this->sslCertificateModel = new SslCertificate();
        $this->sslService = new SslService();
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
        // Remember per_page preference via cookie
        if (isset($_GET['per_page'])) {
            $perPage = max(10, min(100, (int)$_GET['per_page']));
            setcookie('domains_per_page', (string)$perPage, time() + 365 * 24 * 60 * 60, '/');
        } else {
            $perPage = max(10, min(100, (int)($_COOKIE['domains_per_page'] ?? 25)));
        }

        // Remember the picked columns the same way, then drop the choice from the address
        if (isset($_GET['columns_set'])) {
            $picked = array_filter((array)($_GET['columns'] ?? []), 'is_string');
            $picked = array_intersect(array_keys(self::LIST_COLUMNS), $picked);
            if ($_GET['columns_set'] === 'default') {
                setcookie('domains_columns', '', time() - 3600, '/');
            } else {
                setcookie('domains_columns', $picked ? implode(',', $picked) : 'none', time() + 365 * 24 * 60 * 60, '/');
            }
            $query = array_filter(array_diff_key($_GET, ['columns' => 1, 'columns_set' => 1]), fn($value) => $value !== '');
            $this->redirect('/domains' . ($query ? '?' . http_build_query($query) : ''));
        }

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

        // Live SSL status for the lock icon next to each domain the SSL cron checks
        $sslDomainIds = array_column(
            array_filter(
                $formattedDomains,
                fn(array $domain): bool => $this->isCheckedByCron($domain, 'ssl')
            ),
            'id'
        );
        $sslSummaries = \App\Helpers\SslHelper::summarizeByDomain(
            $sslDomainIds,
            $this->sslCertificateModel->getStatusByDomainIds($sslDomainIds)
        );
        foreach ($formattedDomains as &$formattedDomain) {
            $formattedDomain['ssl_summary'] = $sslSummaries[(int)$formattedDomain['id']] ?? null;
            // Creation date, nameservers and privacy for the optional columns
            $formattedDomain = array_merge($formattedDomain, \App\Helpers\DomainHelper::lookupFields($formattedDomain));
        }
        unset($formattedDomain);

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
            'columns' => $this->listColumns(),
            'columnChoices' => self::LIST_COLUMNS,
            'title' => 'Domains'
        ]);
    }

    /**
     * The optional columns to show in the domains list: the saved choice, or the defaults.
     */
    private function listColumns(): array
    {
        $saved = $_COOKIE['domains_columns'] ?? '';
        if ($saved === 'none') {
            return [];
        }

        $picked = is_string($saved) ? array_intersect(array_keys(self::LIST_COLUMNS), explode(',', $saved)) : [];
        return $picked ? array_values($picked) : self::DEFAULT_LIST_COLUMNS;
    }

    /**
     * Export domains as CSV or JSON
     */
    public function export()
    {
        $logger = new \App\Services\Logger('export');

        try {
            $userId = \Core\Auth::id();
            $settingModel = new \App\Models\Setting();
            $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
            $format = $_GET['format'] ?? 'csv';
            $logger->info("Domains export started", ['format' => $format, 'user_id' => $userId]);

            if (!in_array($format, ['csv', 'json'])) {
                $_SESSION['error'] = 'Invalid export format';
                $this->redirect('/domains');
                return;
            }

            // Get all domains with groups and tags
            $domains = $this->domainModel->getAllWithGroups($isolationMode === 'isolated' ? $userId : null);

            $exportData = [];
            foreach ($domains as $domain) {
                $lookup = \App\Helpers\DomainHelper::lookupFields($domain);
                $exportData[] = [
                    'domain_name' => $domain['domain_name'],
                    'status' => $domain['status'] ?? '',
                    'registrar' => $domain['registrar'] ?? '',
                    'expiration_date' => $domain['expiration_date'] ?? '',
                    'tags' => $domain['tags'] ?? '',
                    'notification_group' => $domain['group_name'] ?? '',
                    'notes' => $domain['notes'] ?? '',
                    'creation_date' => $lookup['creation_date'] ?? '',
                    'date_added' => $domain['created_at'] ?? '',
                    'nameservers' => implode(',', $lookup['nameservers']),
                    'privacy' => $lookup['privacy'] ?? ''
                ];
            }

            $date = date('Y-m-d');
            $filename = "domains_export_{$date}";

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
                // Build CSV in memory to avoid fopen('php://output') issues
                $csvContent = \App\Helpers\CsvHelper::build($exportData, ['domain_name', 'status', 'registrar', 'expiration_date', 'tags', 'notification_group', 'notes', 'creation_date', 'date_added', 'nameservers', 'privacy']);
                $logger->info("CSV content built", ['bytes' => strlen($csvContent)]);

                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                header('Content-Length: ' . strlen($csvContent));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo $csvContent;
            }

            $logger->info("Domains export completed successfully");
            exit;
        } catch (\Throwable $e) {
            $logger->error("Domains export failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
            $this->redirect('/domains');
        }
    }

    /**
     * Import domains from CSV or JSON file
     */
    public function import()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains/bulk-add');
            return;
        }

        $this->verifyCsrf('/domains/bulk-add');

        $logger = new \App\Services\Logger('import');
        $userId = \Core\Auth::id();
        $logger->info('Domains import started', ['user_id' => $userId]);

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $logger->warning('No valid file uploaded for domains import');
            $_SESSION['error'] = 'Please select a valid file to import';
            $this->redirect('/domains/bulk-add');
            return;
        }

        $file = $_FILES['import_file'];
        $logger->info('Import file received', [
            'filename' => $file['name'],
            'size' => $file['size']
        ]);

        // Validate file size (5MB max for domains)
        if ($file['size'] > 5242880) {
            $logger->warning('Import file too large', ['size' => $file['size']]);
            $_SESSION['error'] = 'File is too large. Maximum size is 5MB';
            $this->redirect('/domains/bulk-add');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $logger->warning('Invalid file type for domains import', ['extension' => $ext]);
            $_SESSION['error'] = 'Invalid file type. Please upload a CSV or JSON file';
            $this->redirect('/domains/bulk-add');
            return;
        }

        $content = file_get_contents($file['tmp_name']);
        $domainsData = [];

        if ($ext === 'json') {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $logger->error('Invalid JSON file for domains import');
                $_SESSION['error'] = 'Invalid JSON file';
                $this->redirect('/domains/bulk-add');
                return;
            }
            $domainsData = $parsed;
        } else {
            $domainsData = \App\Helpers\CsvHelper::parse($content);
        }

        if (empty($domainsData)) {
            $logger->warning('No domains found in import file');
            $_SESSION['error'] = 'No domains found in file';
            $this->redirect('/domains/bulk-add');
            return;
        }

        $logger->info('Domains data parsed from file', ['entries' => count($domainsData)]);

        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        $sslMonitoringEnabled = $settingModel->isSslMonitoringOnForNewDomains() ? 1 : 0;
        $tagModel = new \App\Models\Tag();

        // Form-level notification group
        $formGroupId = (int)($_POST['notification_group_id'] ?? 0);

        $added = 0;
        $skipped = 0;
        $errors = [];

        $invalidImported = 0;

        foreach ($domainsData as $row) {
            $domainName = trim($row['domain_name'] ?? '');
            if (empty($domainName)) {
                continue;
            }

            $domainCheck = \App\Helpers\InputValidator::validateRootDomain($domainName);
            if (!$domainCheck['valid']) {
                $invalidImported++;
                continue;
            }
            $domainName = $domainCheck['domain'];

            if ($this->domainModel->existsByDomain($domainName)) {
                $skipped++;
                continue;
            }

            try {
                // Fetch WHOIS data
                $whoisData = $this->whoisService->getDomainInfo($domainName);

                if (!$whoisData) {
                    $errors[] = $domainName;
                    continue;
                }

                $status = $this->whoisService->getDomainStatus(
                    $whoisData['expiration_date'] ?? null,
                    $whoisData['status'] ?? [],
                    $whoisData
                );

                // Determine notification group: from file column or form fallback
                $groupId = null;
                $groupName = trim($row['notification_group'] ?? '');
                if (!empty($groupName)) {
                    $groupStmt = $this->groupModel->findByName($groupName, $isolationMode === 'isolated' ? $userId : null);
                    if ($groupStmt) {
                        $groupId = $groupStmt['id'];
                    }
                }
                if (!$groupId && $formGroupId > 0) {
                    $groupId = $formGroupId;
                }

                $domainId = $this->domainModel->create([
                    'domain_name' => $domainName,
                    'registrar' => $whoisData['registrar'] ?? null,
                    'registrar_url' => $whoisData['registrar_url'] ?? null,
                    'expiration_date' => $whoisData['expiration_date'] ?? null,
                    'updated_date' => $whoisData['updated_date'] ?? null,
                    'abuse_email' => $whoisData['abuse_email'] ?? null,
                    'status' => $status,
                    'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE),
                    'notes' => trim($row['notes'] ?? ''),
                    'last_checked' => date('Y-m-d H:i:s'),
                    'notification_group_id' => $groupId,
                    'ssl_monitoring_enabled' => $sslMonitoringEnabled,
                    'user_id' => $isolationMode === 'isolated' ? $userId : null
                ]);

                // Handle tags from file
                $fileTags = trim($row['tags'] ?? '');
                if (!empty($fileTags) && $domainId) {
                    $tagModel->updateDomainTags($domainId, $fileTags, $userId);
                }

                if ($domainId) {
                    $added++;
                }
            } catch (\Exception $e) {
                $errors[] = $domainName;
                $logger->error('Domain import failed', ['domain' => $domainName, 'error' => $e->getMessage()]);
            }
        }

        $logger->info('Domains import completed', [
            'added' => $added,
            'skipped' => $skipped,
            'failed' => count($errors)
        ]);

        $msg = "{$added} domain(s) imported successfully";
        if ($skipped > 0) $msg .= ", {$skipped} skipped (already exist)";
        if ($invalidImported > 0) $msg .= ", {$invalidImported} rejected (not root domains)";
        if (!empty($errors)) $msg .= ", " . count($errors) . " failed";
        $_SESSION['success'] = $msg;
        $this->redirect('/domains');
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

        $domainName = \App\Helpers\InputValidator::sanitizeDomainInput(trim($_POST['domain_name'] ?? ''));
        $groupId = !empty($_POST['notification_group_id']) ? (int)$_POST['notification_group_id'] : null;
        $tagsInput = trim($_POST['tags'] ?? '');
        $userId = \Core\Auth::id();

        // Validate root domain (not a subdomain, respects multi-level TLDs)
        $domainCheck = \App\Helpers\InputValidator::validateRootDomain($domainName);
        if (!$domainCheck['valid']) {
            $_SESSION['error'] = $domainCheck['error'];
            $this->redirect('/domains/create');
            return;
        }
        $domainName = $domainCheck['domain'];

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
            $_SESSION['error'] = WhoisService::getLastErrorMessage() ?? 'Could not retrieve WHOIS information for this domain';
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
            'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE),
            'is_active' => 1,
            'ssl_monitoring_enabled' => (new \App\Models\Setting())->isSslMonitoringOnForNewDomains() ? 1 : 0,
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

        // The list shows only "Error" for a domain nothing could be read about: say why now
        if (!empty($whoisData['lookup_note'])) {
            $_SESSION['warning'] = "Registration data for '$domainName' could not be read. {$whoisData['lookup_note']} "
                . 'The domain page says what can be done.';
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

        // Get referrer for cancel button (validated to prevent open redirect / XSS)
        $referrer = $_GET['from'] ?? '/domains/' . $domain['id'];
        if (!preg_match('#^/[a-zA-Z0-9]#', $referrer)) {
            $referrer = '/domains/' . $domain['id'];
        }
        
        $this->view('domains/edit', [
            'domain' => $domain,
            'groups' => $groups,
            'availableTags' => $availableTags,
            'referrer' => $referrer,
            'dnsHistoryAvailable' => (new \App\Services\DnsHistoryService())->isAvailable(),
            'dnsHistoryOn' => (new \App\Models\Setting())->isDnsHistoryOn(),
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
        $dnsMonitoringEnabled = isset($_POST['dns_monitoring_enabled']) ? 1 : 0;
        $sslMonitoringEnabled = isset($_POST['ssl_monitoring_enabled']) ? 1 : 0;
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

        $fields = [
            'notification_group_id' => $groupId,
            'is_active' => $isActive,
            'dns_monitoring_enabled' => $dnsMonitoringEnabled,
            'ssl_monitoring_enabled' => $sslMonitoringEnabled,
            'expiration_date' => $manualExpirationDate
        ];

        // Only when the form has the checkbox, and once the update that adds the column has run
        if (isset($_POST['dns_history_field']) && $this->domainModel->hasColumn('dns_history_enabled')) {
            $fields['dns_history_enabled'] = isset($_POST['dns_history_enabled']) ? 1 : 0;
        }

        $this->domainModel->update($id, $fields);

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

        // Send notification if SSL monitoring changed and has notification group
        $sslMonitoringChanged = (($domain['ssl_monitoring_enabled'] ?? 0) != $sslMonitoringEnabled);
        if ($sslMonitoringChanged && $groupId) {
            $notificationService = new \App\Services\NotificationService();

            if ($sslMonitoringEnabled) {
                $message = "🟢 SSL monitoring has been ENABLED for {$domain['domain_name']}\n\n" .
                          "The root certificate and monitored SSL endpoints will now be checked automatically.";
                $subject = "✅ SSL Monitoring Enabled: {$domain['domain_name']}";
            } else {
                $message = "🔴 SSL monitoring has been DISABLED for {$domain['domain_name']}\n\n" .
                          "SSL certificates will no longer be checked until monitoring is re-enabled.";
                $subject = "⏸️ SSL Monitoring Disabled: {$domain['domain_name']}";
            }

            $notificationService->sendToGroup($groupId, $subject, $message);
        }

        // Send notification if DNS monitoring changed and has notification group
        $dnsMonitoringChanged = (($domain['dns_monitoring_enabled'] ?? 1) != $dnsMonitoringEnabled);
        if ($dnsMonitoringChanged && $groupId) {
            $notificationService = new \App\Services\NotificationService();

            if ($dnsMonitoringEnabled) {
                $message = "🟢 DNS monitoring has been ENABLED for {$domain['domain_name']}\n\n" .
                          "DNS records will be checked for changes and you'll receive alerts when they change.";
                $subject = "✅ DNS Monitoring Enabled: {$domain['domain_name']}";
            } else {
                $message = "🔴 DNS monitoring has been DISABLED for {$domain['domain_name']}\n\n" .
                          "DNS records will no longer be checked. You will not receive DNS change alerts.";
                $subject = "⏸️ DNS Monitoring Disabled: {$domain['domain_name']}";
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

    /**
     * Perform WHOIS lookup and persist results.
     * @return string|null Status message on success, null on failure.
     */
    private function performWhoisRefresh(int $id, array $domain): ?string
    {
        $logger = new \App\Services\Logger();

        $whoisData = $this->whoisService->getDomainInfo($domain['domain_name']);

        if (!$whoisData) {
            $logger->error('WHOIS refresh failed', [
                'domain_id' => $id,
                'domain_name' => $domain['domain_name'],
                'user_id' => \Core\Auth::id()
            ]);
            return null;
        }

        $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
        $status = $this->whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);
        $renewal = $this->whoisService->detectRenewal($domain, $whoisData, $status);
        $whoisData = $this->whoisService->keepKnownPrivacy($domain, $whoisData);

        $this->domainModel->update($id, [
            'registrar' => $whoisData['registrar'],
            'registrar_url' => $whoisData['registrar_url'] ?? null,
            'expiration_date' => $expirationDate,
            'updated_date' => $whoisData['updated_date'] ?? null,
            'abuse_email' => $whoisData['abuse_email'] ?? null,
            'last_checked' => date('Y-m-d H:i:s'),
            'status' => $status,
            'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE)
        ]);

        $this->sendWhoisRefreshAlerts($id, $this->whoisService->getLastKnownStatus($domain), $status, $renewal);

        $logger->info('WHOIS refresh completed', [
            'domain_id' => $id,
            'domain_name' => $domain['domain_name'],
            'new_status' => $status,
            'registrar' => $whoisData['registrar'],
            'expiration_date' => $whoisData['expiration_date'],
            'user_id' => \Core\Auth::id()
        ]);

        return 'WHOIS updated';
    }

    /**
     * Whether the cron checks this domain's WHOIS, DNS or SSL, so a refresh started here sends the
     * alerts the cron would have sent: domain monitoring is on and, for DNS and SSL, their own
     * monitoring too, with a domain status those checks run for
     */
    private function isCheckedByCron(array $domain, string $check): bool
    {
        if (empty($domain['is_active'])) {
            return false;
        }

        return $check === 'whois'
            || (!empty($domain[$check . '_monitoring_enabled'])
                && in_array($domain['status'] ?? '', Domain::DNS_SSL_CHECK_STATUSES, true));
    }

    /**
     * Send the status change and renewal alerts for a WHOIS refresh. The cron compares against
     * the saved data, so a change found here would otherwise never be alerted.
     *
     * @param string $oldStatus WhoisService::getLastKnownStatus() of the domain before the refresh
     */
    private function sendWhoisRefreshAlerts(int $id, string $oldStatus, string $status, ?array $renewal): void
    {
        if ($oldStatus === $status && $renewal === null) {
            return;
        }

        $domain = $this->domainModel->find($id);
        if (!$domain || !$this->isCheckedByCron($domain, 'whois')) {
            return;
        }

        $notificationService = new \App\Services\NotificationService();

        if ($oldStatus !== $status) {
            $notificationService->alertDomainStatusChange($domain, $oldStatus, $status, $renewal !== null);
        }
        if ($renewal !== null) {
            $notificationService->alertDomainRenewal($domain, $renewal);
        }
    }

    /**
     * Perform DNS lookup and persist results.
     * @return string Status message (always returns, even on zero records).
     */
    private function performDnsRefresh(int $id, array $domain): string
    {
        $logger = new \App\Services\Logger('dns');

        $dnsService = new \App\Services\DnsService();
        $dnsModel = new \App\Models\DnsRecord();

        $previousRecords = $dnsModel->getPreviousSnapshot($id);
        $existingHosts = $dnsModel->getDistinctHosts($id);
        $records = $dnsService->refreshExisting($domain['domain_name'], $existingHosts);
        $totalRecords = array_sum(array_map('count', $records));

        if ($totalRecords === 0) {
            $logger->warning('DNS refresh returned no records', [
                'domain_name' => $domain['domain_name'],
            ]);
            return 'DNS: no records found';
        }

        $this->enrichIpDetails($records, $dnsService);

        $stats = $dnsModel->saveSnapshot($id, $records);
        $this->domainModel->update($id, ['dns_last_checked' => date('Y-m-d H:i:s')]);

        // A first check only saves a baseline, as in the cron
        if (!empty($previousRecords)) {
            $changes = $dnsService->diffRecords($previousRecords, $records);
            (new \App\Services\DnsHistoryService())->recordChange($domain, $previousRecords, $changes, 'refresh');
            $this->sendDnsRefreshAlerts($id, $changes, $dnsService);
        }

        $logger->info('DNS refresh completed', [
            'domain_name' => $domain['domain_name'],
            'total'       => $totalRecords,
            'added'       => $stats['added'],
            'updated'     => $stats['updated'],
            'removed'     => $stats['removed'],
        ]);

        return "DNS updated ({$totalRecords} records)";
    }

    /**
     * Send the alert for DNS changes found by a DNS refresh. The DNS cron compares against the
     * saved records, so a change found here would otherwise never be alerted. The domain is read
     * again, since Refresh All runs its WHOIS refresh first and that can change the status.
     */
    private function sendDnsRefreshAlerts(int $domainId, array $changes, \App\Services\DnsService $dnsService): void
    {
        if (empty($changes['added']) && empty($changes['removed']) && empty($changes['changed'])) {
            return;
        }

        $domain = $this->domainModel->find($domainId);
        if (!$domain || !$this->isCheckedByCron($domain, 'dns')) {
            return;
        }

        $notificationService = new \App\Services\NotificationService();
        $notificationService->alertDnsChanges(
            $domain,
            $dnsService->formatChangesSummary($changes, $domain['domain_name']),
            $dnsService->formatChangesDetail($changes, $domain['domain_name'])
        );
    }

    /**
     * Fetch and persist the latest SSL certificate snapshot for a host, with the SSL cron's
     * reminder bookkeeping and alerts.
     *
     * @return array{id:int,hostname:string,port:int,display_target:string,status:string,error:?string}
     */
    private function performSslRefreshForHost(int $domainId, string $hostname, int $port = 443): array
    {
        $settingModel = new \App\Models\Setting();
        $check = $this->sslService->checkEndpoint($domainId, $hostname, $port, $settingModel->getSslNotificationDays());
        $snapshot = $check['snapshot'];
        $this->domainModel->update($domainId, ['ssl_last_checked' => $snapshot['last_checked']]);

        $endpoint = $this->sslService->formatTargetLabel($hostname, $port);
        $this->sendSslRefreshAlerts($domainId, $hostname, $endpoint, $check);

        return [
            'id' => $check['id'],
            'hostname' => $hostname,
            'port' => $port,
            'display_target' => $endpoint,
            'status' => $snapshot['status'],
            'error' => $snapshot['last_error'],
        ];
    }

    /**
     * Send the alerts for an SSL check started here. The SSL cron compares against the saved
     * status, so a change found here would otherwise never be alerted; like the cron, nothing
     * is sent for a host that doesn't resolve.
     */
    private function sendSslRefreshAlerts(int $domainId, string $hostname, string $endpoint, array $check): void
    {
        if (!$check['status_alert'] && $check['reminder_days'] === null) {
            return;
        }

        $domain = $this->domainModel->find($domainId);
        if (!$domain || !$this->isCheckedByCron($domain, 'ssl') || !\App\Helpers\CronHelper::hostnameResolves($hostname)) {
            return;
        }

        $notificationService = new \App\Services\NotificationService();
        $notificationService->alertSslCheck($domain, $endpoint, $check);
    }

    /**
     * Get the SSL endpoints that should be checked for a domain.
     * Falls back to the root domain on 443 until a root target is explicitly tracked.
     *
     * @return array<int,array{hostname:string,port:int}>
     */
    private function getSslMonitorTargets(int $domainId, string $rootDomain): array
    {
        $rootDomain = strtolower($rootDomain);
        $targets = $this->sslCertificateModel->getDistinctTargets($domainId);
        $hasTrackedRootTarget = false;

        foreach ($targets as $target) {
            if ($target['hostname'] === $rootDomain) {
                $hasTrackedRootTarget = true;
                break;
            }
        }

        if (!$hasTrackedRootTarget) {
            $targets[] = [
                'hostname' => $rootDomain,
                'port' => 443,
            ];
        }

        usort($targets, static function (array $a, array $b): int {
            $hostnameCompare = strcasecmp($a['hostname'], $b['hostname']);
            if ($hostnameCompare !== 0) {
                return $hostnameCompare;
            }

            return $a['port'] <=> $b['port'];
        });

        return $targets;
    }

    /**
     * Count tracked root-domain SSL endpoints for delete safeguards.
     */
    private function countStoredRootSslTargets(int $domainId, string $rootDomain): int
    {
        $rootDomain = strtolower($rootDomain);
        $targets = $this->sslCertificateModel->getDistinctTargets($domainId);

        return count(array_filter($targets, static fn(array $target): bool => $target['hostname'] === $rootDomain));
    }

    /**
     * Determine whether the certificate row represents the default root SSL target.
     */
    private function isDefaultRootSslTarget(array $certificate, string $rootDomain): bool
    {
        return strtolower($certificate['hostname']) === strtolower($rootDomain)
            && (int)($certificate['port'] ?? 443) === 443;
    }

    /**
     * Get formatted SSL certificates for rendering.
     */
    private function getFormattedSslCertificates(int $domainId, string $rootDomain): array
    {
        $rawCertificates = $this->sslCertificateModel->getByDomain($domainId);
        $rootDomain = strtolower($rootDomain);
        $rootTargetCount = count(array_filter(
            $rawCertificates,
            static fn(array $certificate): bool => strtolower($certificate['hostname']) === $rootDomain
        ));

        $certificates = array_map(
            fn(array $certificate) => $this->formatSslCertificate($certificate, $rootDomain, $rootTargetCount),
            $rawCertificates
        );

        usort($certificates, function (array $a, array $b): int {
            if ($a['is_root'] !== $b['is_root']) {
                return $a['is_root'] ? -1 : 1;
            }

            $hostnameCompare = strcasecmp($a['hostname'], $b['hostname']);
            if ($hostnameCompare !== 0) {
                return $hostnameCompare;
            }

            return $a['port'] <=> $b['port'];
        });

        return $certificates;
    }

    /**
     * Prepare a single SSL certificate row for the view.
     */
    private function formatSslCertificate(array $certificate, string $rootDomain, int $rootTargetCount): array
    {
        // Same display fields as the dashboard: target label, live days left and status styling
        $certificate = \App\Helpers\SslHelper::formatForDisplay($certificate, $this->sslService);
        $certificate['is_root'] = $this->isDefaultRootSslTarget($certificate, $rootDomain);
        $certificate['can_delete'] = !$certificate['is_root'] || $rootTargetCount > 1;
        $certificate['san_list'] = !empty($certificate['san_list'])
            ? (json_decode($certificate['san_list'], true) ?: [])
            : [];
        $certificate['raw_data'] = !empty($certificate['raw_data'])
            ? (json_decode($certificate['raw_data'], true) ?: [])
            : [];
        $certificate['issuer_organization'] = $this->extractCertificateDnValue(
            is_array($certificate['raw_data']['issuer'] ?? null) ? $certificate['raw_data']['issuer'] : [],
            'O'
        );
        $certificate['subject_organization'] = $this->extractCertificateDnValue(
            is_array($certificate['raw_data']['subject'] ?? null) ? $certificate['raw_data']['subject'] : [],
            'O'
        );
        $certificate['is_trusted'] = !empty($certificate['is_trusted']);
        $certificate['is_self_signed'] = !empty($certificate['is_self_signed']);

        return $certificate;
    }

    /**
     * Extract a human-readable distinguished name field from parsed certificate data.
     */
    private function extractCertificateDnValue(array $parts, string $field): ?string
    {
        if (!array_key_exists($field, $parts)) {
            return null;
        }

        $value = $parts[$field];
        if (is_array($value)) {
            $values = array_values(array_filter(array_map(static function ($item): ?string {
                if (!is_scalar($item)) {
                    return null;
                }

                $item = trim((string)$item);
                return $item !== '' ? $item : null;
            }, $value)));

            return !empty($values) ? implode(', ', $values) : null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    /**
     * Ensure SSL monitoring is enabled before allowing SSL checks.
     */
    private function ensureSslMonitoringEnabled(array $domain, int $id): bool
    {
        if (!empty($domain['ssl_monitoring_enabled'])) {
            return true;
        }

        $_SESSION['warning'] = 'SSL monitoring is disabled for this domain';
        $this->redirectBackToDomain($id, '#ssl');
        return false;
    }

    /**
     * Parse certificate ids from a comma-separated POST value.
     */
    private function parseSslCertificateIds(?string $rawIds): array
    {
        if ($rawIds === null || trim($rawIds) === '') {
            return [];
        }

        $ids = array_map('intval', explode(',', $rawIds));
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    /**
     * Build a safe internal return path for the current domain page.
     */
    private function getSafeDomainReturnPath(int $id, string $fallbackHash = ''): string
    {
        $fallback = '/domains/' . $id . $fallbackHash;
        $returnTo = trim((string)($_POST['return_to'] ?? ''));

        if ($returnTo === '') {
            return $fallback;
        }

        $parts = parse_url($returnTo);
        if ($parts === false) {
            return $fallback;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '/domains/' . $id) {
            return $fallback;
        }

        $fragment = '';
        if (!empty($parts['fragment']) && preg_match('/^[a-z0-9_-]+$/i', $parts['fragment'])) {
            $fragment = '#' . $parts['fragment'];
        }

        return $path . $fragment;
    }

    /**
     * Redirect back to the originating page (domain view or list).
     */
    private function redirectBackToDomain(int $id, string $hash = ''): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, '/domains/' . $id) !== false) {
            $this->redirect($this->getSafeDomainReturnPath($id, $hash));
        } else {
            $this->redirect('/domains');
        }
    }

    public function refreshWhois($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $result = $this->performWhoisRefresh($id, $domain);

        if ($result === null) {
            $_SESSION['error'] = WhoisService::getLastErrorMessage() ?? 'Could not retrieve WHOIS information';
        } else {
            $_SESSION['success'] = 'WHOIS information refreshed';
        }

        $this->redirectBackToDomain($id);
    }

    public function refreshAll($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $messages = [];
        $messages[] = $this->performWhoisRefresh($id, $domain) ?? 'WHOIS failed';
        if (!empty($domain['dns_monitoring_enabled'])) {
            $messages[] = $this->performDnsRefresh($id, $domain);
        } else {
            $messages[] = 'DNS skipped (monitoring disabled)';
        }
        if (!empty($domain['ssl_monitoring_enabled'])) {
            $targets = $this->getSslMonitorTargets($id, $domain['domain_name']);
            $refreshed = 0;
            foreach ($targets as $target) {
                $this->performSslRefreshForHost($id, $target['hostname'], $target['port']);
                $refreshed++;
            }
            $messages[] = 'SSL updated (' . $refreshed . ' endpoint' . ($refreshed === 1 ? '' : 's') . ')';
        } else {
            $messages[] = 'SSL skipped (monitoring disabled)';
        }

        $_SESSION['success'] = 'Domain refreshed: ' . implode(', ', $messages);
        $this->redirectBackToDomain($id);
    }

    public function delete($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = $params['id'] ?? 0;
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $historyKept = (new \App\Services\DnsHistoryService())->recordDeletion($domain);
        $this->domainModel->delete($id);
        $_SESSION['success'] = 'Domain deleted successfully' . ($historyKept ? '. ' . $this->dnsHistoryKeptNote(1) : '');
        $this->redirect('/domains');
    }

    /**
     * What to tell the user after deleting domains whose DNS records went to DNS history
     */
    private function dnsHistoryKeptNote(int $domains): string
    {
        $days = (new \App\Models\Setting())->getDnsHistoryRetentionDays();
        $whose = $domains === 1 ? 'Its DNS records are' : "The DNS records of $domains domain(s) are";

        return "$whose kept in DNS History for $days days.";
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
            $_SESSION['error'] = 'You do not have permission to view this domain.';
            $this->redirect('/domains');
            return;
        }

        $logModel = new \App\Models\NotificationLog();
        $logs = $logModel->getByDomain($id, 20);
        
        // Format domain for display
        $formattedDomain = \App\Helpers\DomainHelper::formatForDisplay($domain);
        $formattedDomain['privacy'] = \App\Helpers\DomainHelper::lookupFields($domain)['privacy'];
        
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

        // Get DNS records for the DNS tab
        $dnsModel = new \App\Models\DnsRecord();
        $dnsRecords = $dnsModel->getByDomainGrouped($id);
        $dnsRecordCount = $dnsModel->countByDomain($id);
        $dnsHasCloudflare = $dnsModel->hasCloudflare($id);
        $dnsHasWildcard = $dnsModel->hasWildcard($id);
        $sslCertificates = $this->getFormattedSslCertificates($id, $domain['domain_name']);
        $sslStats = \App\Helpers\SslHelper::buildStats($sslCertificates);

        // Extract cached IP details (PTR, ASN, geo) from stored raw_data
        $dnsIpDetails = [];
        foreach (['A', 'AAAA'] as $type) {
            if (!empty($dnsRecords[$type])) {
                foreach ($dnsRecords[$type] as $r) {
                    if (!empty($r['raw_data']) && !empty($r['value'])) {
                        $raw = json_decode($r['raw_data'], true);
                        if (!empty($raw['_ip_info'])) {
                            $dnsIpDetails[$r['value']] = $raw['_ip_info'];
                        }
                    }
                }
            }
        }

        $viewTemplate = $settingModel->getValue('domain_view_template', 'detailed');
        $templateName = $viewTemplate === 'detailed' ? 'domains/view-detailed' : 'domains/view';

        $this->view($templateName, [
            'domain' => $formattedDomain,
            'whoisData' => $whoisData,
            'logs' => $logs,
            'availableTags' => $availableTags,
            'dnsRecords' => $dnsRecords,
            'dnsRecordCount' => $dnsRecordCount,
            'dnsHasCloudflare' => $dnsHasCloudflare,
            'dnsHasWildcard' => $dnsHasWildcard,
            'dnsIpDetails' => $dnsIpDetails,
            'dnsHistory' => $this->getDnsHistoryForView((int)$id),
            'sslCertificates' => $sslCertificates,
            'sslStats' => $sslStats,
            'title' => $domain['domain_name']
        ]);
    }

    /**
     * What the DNS tab shows of a domain's DNS history: its newest entries without their saved
     * records, and whether history is being saved. Empty while the update that adds the table is
     * pending, and whenever reading it fails: the domain page must not depend on it.
     *
     * @return array{available: bool, on: bool, days: int, total: int, entries: array, labels: array}
     */
    private function getDnsHistoryForView(int $domainId): array
    {
        $history = [
            'available' => false,
            'on'        => false,
            'days'      => 90,
            'total'     => 0,
            'entries'   => [],
            'labels'    => \App\Services\DnsHistoryService::SOURCE_LABELS,
        ];

        try {
            if (!(new \App\Services\DnsHistoryService())->isAvailable()) {
                return $history;
            }

            $historyModel = new \App\Models\DnsHistory();
            $settingModel = new \App\Models\Setting();

            return [
                'available' => true,
                'on'        => $settingModel->isDnsHistoryOn(),
                'days'      => $settingModel->getDnsHistoryRetentionDays(),
                'total'     => $historyModel->countForDomain($domainId),
                'entries'   => $historyModel->getForDomain($domainId, 20),
            ] + $history;
        } catch (\Throwable $e) {
            (new \App\Services\Logger('dns'))->warning('DNS history could not be read', ['error' => $e->getMessage()]);
            return $history;
        }
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

        // Split by new lines, sanitize each, and filter empties
        $rawLines = array_filter(array_map('trim', explode("\n", $domainsText)));
        $domainNames = [];
        $invalidDomains = [];
        foreach ($rawLines as $line) {
            $cleaned = \App\Helpers\InputValidator::sanitizeDomainInput($line);
            if (empty($cleaned)) continue;
            $check = \App\Helpers\InputValidator::validateRootDomain($cleaned);
            if (!$check['valid']) {
                $invalidDomains[] = $cleaned;
                continue;
            }
            $domainNames[] = $check['domain'];
        }
        
        $added = 0;
        $skipped = 0;
        $availableCount = 0;
        $errors = [];
        $failureReasons = [];
        $userId = \Core\Auth::id();
        $sslMonitoringEnabled = (new \App\Models\Setting())->isSslMonitoringOnForNewDomains() ? 1 : 0;
        
        // Log bulk add start
        $logger = new \App\Services\Logger();
        $logger->info('Bulk domain add started', [
            'user_id' => $userId,
            'domain_count' => count($domainNames),
            'invalid_count' => count($invalidDomains),
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
                $failureReasons[WhoisService::getLastErrorMessage() ?? 'WHOIS lookup failed.'][] = $domainName;
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
                    'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE),
                    'is_active' => 1,
                    'ssl_monitoring_enabled' => $sslMonitoringEnabled,
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
                    $failureReasons['Could not be saved (details in the application log).'][] = $domainName;
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

        // Name the domains that were not added, so they don't disappear inside the success message
        $problems = [];
        if (count($invalidDomains) > 0) {
            $problems[] = count($invalidDomains) . " rejected as not root domains: " . $this->formatDomainList($invalidDomains) . ".";
        }
        if (count($errors) > 0) {
            $problems[] = "Could not add " . count($errors) . " domain(s). " . $this->formatFailureReasons($failureReasons);
        }
        if (!empty($problems)) {
            $_SESSION['error'] = implode(' ', $problems);
        }

        if ($availableCount > 0) {
            $_SESSION['warning'] = "Note: $availableCount domain(s) appear to be AVAILABLE (not registered).";
        }

        $_SESSION['success'] = $message;
        $this->redirect('/domains');
    }

    /**
     * Comma-separated list of domain names for flash messages, shortened after $max names.
     */
    private function formatDomainList(array $domains, int $max = 10): string
    {
        $list = implode(', ', array_slice($domains, 0, $max));
        if (count($domains) > $max) {
            $list .= ' and ' . (count($domains) - $max) . ' more';
        }
        return $list;
    }

    /**
     * "a.hr, b.hr: reason. c.es: reason." for flash messages, so each reason is shown once for all its domains.
     *
     * @param array<string, string[]> $domainsByReason Reason (ending in a full stop) => domain names
     */
    private function formatFailureReasons(array $domainsByReason, int $maxReasons = 3): string
    {
        $parts = [];
        foreach (array_slice($domainsByReason, 0, $maxReasons, true) as $reason => $domains) {
            $parts[] = $this->formatDomainList($domains) . ': ' . $reason;
        }
        $hidden = array_sum(array_map('count', array_slice($domainsByReason, $maxReasons, null, true)));
        if ($hidden > 0) {
            $parts[] = "{$hidden} more failed for other reasons (see the application log).";
        }
        return implode(' ', $parts);
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
        $failureReasons = [];

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
                $failureReasons[WhoisService::getLastErrorMessage() ?? 'WHOIS lookup failed.'][] = $domain['domain_name'];
                continue;
            }

            // Use WHOIS expiration date if available, otherwise preserve manual expiration date
            $expirationDate = $whoisData['expiration_date'] ?? $domain['expiration_date'];
            
            $status = $this->whoisService->getDomainStatus($expirationDate, $whoisData['status'] ?? [], $whoisData);
            $renewal = $this->whoisService->detectRenewal($domain, $whoisData, $status);
            $whoisData = $this->whoisService->keepKnownPrivacy($domain, $whoisData);

            $this->domainModel->update($id, [
                'registrar' => $whoisData['registrar'],
                'registrar_url' => $whoisData['registrar_url'] ?? null,
                'expiration_date' => $expirationDate,
                'updated_date' => $whoisData['updated_date'] ?? null,
                'abuse_email' => $whoisData['abuse_email'] ?? null,
                'last_checked' => date('Y-m-d H:i:s'),
                'status' => $status,
                'whois_data' => json_encode($whoisData, JSON_INVALID_UTF8_SUBSTITUTE)
            ]);

            $this->sendWhoisRefreshAlerts((int)$id, $this->whoisService->getLastKnownStatus($domain), $status, $renewal);

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
        if ($failed > 0) {
            $_SESSION['error'] = "Could not refresh {$failed} domain(s). " . $this->formatFailureReasons($failureReasons);
        }
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

        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $historyService = new \App\Services\DnsHistoryService();
        $deleted = 0;
        $historyKept = 0;
        foreach ($domainIds as $id) {
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }

            if (!$domain) {
                continue;
            }

            $kept = $historyService->recordDeletion($domain);
            if ($this->domainModel->delete($id)) {
                $deleted++;
                $historyKept += $kept ? 1 : 0;
            }
        }

        $_SESSION['success'] = "Deleted $deleted domain(s)" . ($historyKept > 0 ? '. ' . $this->dnsHistoryKeptNote($historyKept) : '');
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

        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Validate notification group in isolation mode
        if ($groupId && $isolationMode === 'isolated') {
            $group = $this->groupModel->find($groupId);
            if (!$group || $group['user_id'] != $userId) {
                $_SESSION['error'] = 'You can only assign domains to your own notification groups';
                $this->redirect('/domains');
                return;
            }
        }

        $updated = 0;
        foreach ($domainIds as $id) {
            if ($isolationMode === 'isolated') {
                $domain = $this->domainModel->findWithIsolation($id, $userId);
            } else {
                $domain = $this->domainModel->find($id);
            }

            if ($domain && $this->domainModel->update($id, ['notification_group_id' => $groupId])) {
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

    /**
     * Turn SSL monitoring on or off for the selected domains
     */
    public function bulkToggleSsl()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $enabled = !empty($_POST['ssl_monitoring_enabled']) ? 1 : 0;

        if (empty($domainIds) || !is_array($domainIds)) {
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

        $changed = 0;
        $unchanged = 0;
        foreach ($domainIds as $id) {
            $domain = $this->checkDomainAccess((int)$id);
            if (!$domain) {
                continue;
            }

            if ((int)($domain['ssl_monitoring_enabled'] ?? 0) === $enabled) {
                $unchanged++;
            } elseif ($this->domainModel->update($domain['id'], ['ssl_monitoring_enabled' => $enabled])) {
                $changed++;
            }
        }

        $state = $enabled ? 'enabled' : 'disabled';
        $message = "SSL monitoring $state for $changed domain(s)";
        if ($unchanged > 0) {
            $message .= " ($unchanged already $state)";
        }
        if ($enabled && $changed > 0) {
            $message .= '. Certificates are checked on the next SSL cron run, or right away with Check All on a domain\'s SSL tab.';
        }

        $_SESSION['success'] = $message;
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

        $settingModel = new \App\Models\Setting();
        $viewTemplate = $settingModel->getValue('domain_view_template', 'detailed');
        $redirect = '/domains/' . $id . ($viewTemplate === 'detailed' ? '#overview' : '');

        if ($lengthError) {
            $_SESSION['error'] = $lengthError;
            $this->redirect($redirect);
            return;
        }

        $this->domainModel->update($id, [
            'notes' => $notes
        ]);

        $_SESSION['success'] = 'Notes updated successfully';
        $this->redirect($redirect);
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

        $logger = new \App\Services\Logger('transfer');

        try {
            $this->domainModel->update($domainId, ['user_id' => $targetUserId]);

            $groupUnlinked = false;
            if (!empty($domain['notification_group_id'])) {
                $groupModel = new \App\Models\NotificationGroup();
                $group = $groupModel->find($domain['notification_group_id']);
                if ($group && $group['user_id'] != $targetUserId) {
                    $this->domainModel->update($domainId, ['notification_group_id' => null]);
                    $groupUnlinked = true;
                }
            }

            $tagModel = new \App\Models\Tag();
            $tagsRemoved = $tagModel->removeOtherUserTagsFromDomain($domainId, $targetUserId);

            $logger->info('Domain transferred', [
                'domain_id' => $domainId,
                'domain_name' => $domain['domain_name'],
                'from_user_id' => $domain['user_id'],
                'to_user_id' => $targetUserId,
                'to_username' => $targetUser['username'],
                'group_unlinked' => $groupUnlinked,
                'tags_removed' => $tagsRemoved,
                'admin_user_id' => \Core\Auth::id(),
            ]);
            
            $_SESSION['success'] = "Domain '{$domain['domain_name']}' transferred to {$targetUser['username']}";
        } catch (\Exception $e) {
            $logger->error('Domain transfer failed', [
                'domain_id' => $domainId,
                'to_user_id' => $targetUserId,
                'error' => $e->getMessage(),
            ]);
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

        $groupModel = new \App\Models\NotificationGroup();
        $tagModel = new \App\Models\Tag();
        $logger = new \App\Services\Logger('transfer');

        $transferred = 0;
        foreach ($domainIds as $domainId) {
            $domainId = (int)$domainId;
            if ($domainId > 0) {
                try {
                    $domain = $this->domainModel->find($domainId);
                    $this->domainModel->update($domainId, ['user_id' => $targetUserId]);

                    $groupUnlinked = false;
                    if ($domain && !empty($domain['notification_group_id'])) {
                        $group = $groupModel->find($domain['notification_group_id']);
                        if ($group && $group['user_id'] != $targetUserId) {
                            $this->domainModel->update($domainId, ['notification_group_id' => null]);
                            $groupUnlinked = true;
                        }
                    }

                    $tagsRemoved = $tagModel->removeOtherUserTagsFromDomain($domainId, $targetUserId);

                    $logger->info('Domain transferred (bulk)', [
                        'domain_id' => $domainId,
                        'domain_name' => $domain['domain_name'] ?? 'unknown',
                        'from_user_id' => $domain['user_id'] ?? null,
                        'to_user_id' => $targetUserId,
                        'to_username' => $targetUser['username'],
                        'group_unlinked' => $groupUnlinked,
                        'tags_removed' => $tagsRemoved,
                        'admin_user_id' => \Core\Auth::id(),
                    ]);

                    $transferred++;
                } catch (\Exception $e) {
                    $logger->error('Domain transfer failed (bulk)', [
                        'domain_id' => $domainId,
                        'to_user_id' => $targetUserId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $logger->info('Bulk domain transfer completed', [
            'transferred' => $transferred,
            'total_requested' => count($domainIds),
            'to_user_id' => $targetUserId,
            'to_username' => $targetUser['username'],
            'admin_user_id' => \Core\Auth::id(),
        ]);

        $_SESSION['success'] = "$transferred domain(s) transferred to {$targetUser['username']}";
        $this->redirect('/domains');
    }

    // ========================================
    // DNS MONITORING
    // ========================================

    public function refreshDns($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $result = $this->performDnsRefresh($id, $domain);

        if (strpos($result, 'no records') !== false) {
            $_SESSION['warning'] = 'No DNS records found for this domain';
        } else {
            $_SESSION['success'] = $result;
        }

        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Discover DNS records via Quick Scan (synchronous) or Deep Scan (background).
     */
    public function discoverDns($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logger = new \App\Services\Logger('dns');
        $mode = $_POST['mode'] ?? 'quick';

        if ($mode === 'deep') {
            $domainName = escapeshellarg($domain['domain_name']);
            $scriptPath = realpath(__DIR__ . '/../../cron/discover_dns.php');

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = "start /b php " . escapeshellarg($scriptPath) . " --domain $domainName";
            } else {
                $cmd = "nohup php " . escapeshellarg($scriptPath) . " --domain $domainName > /dev/null 2>&1 &";
            }
            exec($cmd);

            $logger->info('Deep DNS scan started (background)', [
                'domain_name' => $domain['domain_name'],
            ]);
            $_SESSION['info'] = 'Deep scan started in background. New records will appear when discovery completes — refresh the page to see them.';
        } else {
            $dnsService = new \App\Services\DnsService();
            $dnsModel = new \App\Models\DnsRecord();

            $previousRecords = $dnsModel->getPreviousSnapshot($id);
            $records = $dnsService->quickScan($domain['domain_name'], $dnsModel->getDistinctHosts($id));
            $totalRecords = array_sum(array_map('count', $records));

            // Saving an empty lookup would delete every record the lookups found, as in performDnsRefresh()
            if ($totalRecords === 0) {
                $logger->warning('Quick DNS scan returned no records', [
                    'domain_name' => $domain['domain_name'],
                ]);
                $_SESSION['warning'] = 'No DNS records found for this domain';
                $this->redirectBackToDomain($id, '#dns');
                return;
            }

            $this->enrichIpDetails($records, $dnsService);

            $stats = $dnsModel->saveSnapshot($id, $records);
            $this->domainModel->update($id, ['dns_last_checked' => date('Y-m-d H:i:s')]);

            // A scan sends no alert, but what it changed in the list is kept like any other change
            if (!empty($previousRecords)) {
                (new \App\Services\DnsHistoryService())->recordChange(
                    $domain,
                    $previousRecords,
                    $dnsService->diffRecords($previousRecords, $records),
                    'quick_scan'
                );
            }

            $logger->info('Quick DNS scan completed', [
                'domain_name' => $domain['domain_name'],
                'total'       => $totalRecords,
                'added'       => $stats['added'],
            ]);
            $_SESSION['success'] = "Quick scan complete: {$totalRecords} records found";
        }

        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Add a single DNS record manually.
     */
    public function addDnsRecord($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logger = new \App\Services\Logger('dns');
        $dnsModel = new \App\Models\DnsRecord();

        $type     = strtoupper(trim($_POST['record_type'] ?? ''));
        $host     = trim($_POST['host'] ?? '@');
        $value    = trim($_POST['value'] ?? '');
        $ttl      = !empty($_POST['ttl']) ? (int)$_POST['ttl'] : 3600;
        $priority = isset($_POST['priority']) && $_POST['priority'] !== '' ? (int)$_POST['priority'] : null;

        $validTypes = ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA', 'SRV', 'CAA'];
        if (!in_array($type, $validTypes) || $value === '') {
            $_SESSION['error'] = 'Invalid record type or missing value';
            $this->redirectBackToDomain($id, '#dns');
            return;
        }

        $dnsModel->addManualRecord($id, $type, $host, $value, $ttl, $priority);

        $logger->info('Manual DNS record added', [
            'domain_name' => $domain['domain_name'],
            'type'        => $type,
            'host'        => $host,
            'value'       => $value,
        ]);

        $_SESSION['success'] = "DNS record added: {$type} {$host}";
        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Delete a single DNS record.
     */
    public function deleteDnsRecord($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $recordId = (int)($params['recordId'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logger = new \App\Services\Logger('dns');
        $dnsModel = new \App\Models\DnsRecord();

        if ($dnsModel->deleteRecord($recordId, $id)) {
            $logger->info('DNS record deleted', [
                'domain_name' => $domain['domain_name'],
                'record_id'   => $recordId,
            ]);
            $_SESSION['success'] = 'DNS record deleted';
        } else {
            $_SESSION['error'] = 'DNS record not found';
        }

        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Bulk delete DNS records.
     */
    public function bulkDeleteDnsRecords($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logger = new \App\Services\Logger('dns');
        $dnsModel = new \App\Models\DnsRecord();

        $ids = $_POST['record_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_map('intval', $ids);

        $count = $dnsModel->bulkDeleteRecords($ids, $id);

        $logger->info('Bulk DNS records deleted', [
            'domain_name' => $domain['domain_name'],
            'count'       => $count,
        ]);

        $_SESSION['success'] = "Deleted {$count} DNS record(s)";
        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Import DNS records from a BIND zone file.
     */
    public function importDnsZone($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $logger = new \App\Services\Logger('dns');
        $dnsService = new \App\Services\DnsService();
        $dnsModel = new \App\Models\DnsRecord();

        $content = '';
        if (!empty($_FILES['zone_file']['tmp_name'])) {
            $content = file_get_contents($_FILES['zone_file']['tmp_name']);
        } elseif (!empty($_POST['zone_content'])) {
            $content = $_POST['zone_content'];
        }

        if (trim($content) === '') {
            $_SESSION['error'] = 'No zone file content provided';
            $this->redirectBackToDomain($id, '#dns');
            return;
        }

        try {
            $parsed = $dnsService->parseBindZone($content, $domain['domain_name']);
            $totalParsed = array_sum(array_map('count', $parsed));

            if ($totalParsed === 0) {
                $_SESSION['error'] = 'No valid DNS records found in zone file';
                $this->redirectBackToDomain($id, '#dns');
                return;
            }

            $count = $dnsModel->addImportedRecords($id, $parsed);

            $logger->info('DNS zone file imported', [
                'domain_name' => $domain['domain_name'],
                'parsed'      => $totalParsed,
                'imported'    => $count,
            ]);

            $_SESSION['success'] = "Imported {$count} DNS records from zone file";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to parse zone file: ' . $e->getMessage();
            $logger->error('DNS zone import failed', [
                'domain_name' => $domain['domain_name'],
                'error'       => $e->getMessage(),
            ]);
        }

        $this->redirectBackToDomain($id, '#dns');
    }

    /**
     * Enrich A/AAAA records in-place with IP metadata (PTR, ASN, geo).
     */
    private function enrichIpDetails(array &$records, \App\Services\DnsService $dnsService): void
    {
        $ips = [];
        foreach (['A', 'AAAA'] as $type) {
            if (!empty($records[$type])) {
                foreach ($records[$type] as $r) {
                    if (!empty($r['value'])) {
                        $ips[] = $r['value'];
                    }
                }
            }
        }
        if (!empty($ips)) {
            $ipDetails = $dnsService->lookupIpDetails($ips);
            foreach (['A', 'AAAA'] as $type) {
                if (!empty($records[$type])) {
                    foreach ($records[$type] as &$rec) {
                        if (!empty($rec['value']) && isset($ipDetails[$rec['value']])) {
                            $rec['raw']['_ip_info'] = $ipDetails[$rec['value']];
                        }
                    }
                    unset($rec);
                }
            }
        }
    }

    /**
     * Add a monitored SSL hostname and fetch its certificate immediately.
     */
    public function addSslHost($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        if (!$this->ensureSslMonitoringEnabled($domain, $id)) {
            return;
        }

        $input = \App\Helpers\InputValidator::sanitizeText($_POST['hostname'] ?? '');
        $target = $this->sslService->parseMonitorTarget($input, $domain['domain_name']);

        if ($target === null) {
            $_SESSION['error'] = 'Enter a valid subdomain, full hostname, or host:port under this domain';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $alreadyTracked = $this->sslCertificateModel->findByDomainAndHost(
            $id,
            $target['hostname'],
            $target['port']
        ) !== null;
        $result = $this->performSslRefreshForHost($id, $target['hostname'], $target['port']);

        if (in_array($result['status'], ['invalid', 'expired'], true)) {
            $_SESSION['warning'] = ($alreadyTracked ? 'SSL certificate refreshed' : 'SSL certificate added')
                . ' for ' . $result['display_target'] . ', but an issue was detected'
                . ($result['error'] ? ': ' . $result['error'] : '.');
        } else {
            $_SESSION['success'] = ($alreadyTracked ? 'SSL certificate refreshed for ' : 'SSL certificate added for ')
                . $result['display_target'];
        }

        $this->redirectBackToDomain($id, '#ssl');
    }

    /**
     * Refresh all monitored SSL hosts for the domain.
     * Ensures the root hostname is always checked.
     */
    public function refreshAllSsl($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        if (!$this->ensureSslMonitoringEnabled($domain, $id)) {
            return;
        }

        $targets = $this->getSslMonitorTargets($id, $domain['domain_name']);

        $results = [];
        foreach ($targets as $target) {
            $results[] = $this->performSslRefreshForHost($id, $target['hostname'], $target['port']);
        }

        $issues = array_filter($results, static function (array $result): bool {
            return in_array($result['status'], ['invalid', 'expired'], true);
        });

        if (!empty($issues)) {
            $_SESSION['warning'] = 'SSL check completed for ' . count($results) . ' endpoint(s); ' . count($issues) . ' issue(s) detected.';
        } else {
            $_SESSION['success'] = 'SSL certificates refreshed for ' . count($results) . ' endpoint(s).';
        }

        $this->redirectBackToDomain($id, '#ssl');
    }

    /**
     * Refresh a single monitored SSL host.
     */
    public function refreshSsl($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $certificateId = (int)($params['certificateId'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        if (!$this->ensureSslMonitoringEnabled($domain, $id)) {
            return;
        }

        $certificate = $this->sslCertificateModel->findByDomainAndId($id, $certificateId);
        if (!$certificate) {
            $_SESSION['error'] = 'SSL certificate not found';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $result = $this->performSslRefreshForHost($id, $certificate['hostname'], (int)($certificate['port'] ?? 443));

        if (in_array($result['status'], ['invalid', 'expired'], true)) {
            $_SESSION['warning'] = 'SSL certificate checked for ' . $result['display_target']
                . ($result['error'] ? ': ' . $result['error'] : '.');
        } else {
            $_SESSION['success'] = 'SSL certificate refreshed for ' . $result['display_target'];
        }

        $this->redirectBackToDomain($id, '#ssl');
    }

    /**
     * Refresh selected monitored SSL hosts.
     */
    public function bulkRefreshSsl($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        if (!$this->ensureSslMonitoringEnabled($domain, $id)) {
            return;
        }

        $ids = $this->parseSslCertificateIds($_POST['certificate_ids'] ?? '');
        if (empty($ids)) {
            $_SESSION['warning'] = 'Select at least one SSL certificate to check';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $results = [];
        foreach ($ids as $certificateId) {
            $certificate = $this->sslCertificateModel->findByDomainAndId($id, $certificateId);
            if ($certificate) {
                $results[] = $this->performSslRefreshForHost(
                    $id,
                    $certificate['hostname'],
                    (int)($certificate['port'] ?? 443)
                );
            }
        }

        if (empty($results)) {
            $_SESSION['error'] = 'No valid SSL certificates were selected';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $issues = array_filter($results, static function (array $result): bool {
            return in_array($result['status'], ['invalid', 'expired'], true);
        });

        if (!empty($issues)) {
            $_SESSION['warning'] = 'Checked ' . count($results) . ' SSL certificate(s); ' . count($issues) . ' issue(s) detected.';
        } else {
            $_SESSION['success'] = 'Checked ' . count($results) . ' SSL certificate(s).';
        }

        $this->redirectBackToDomain($id, '#ssl');
    }

    /**
     * Delete a monitored SSL host.
     */
    public function deleteSsl($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $certificateId = (int)($params['certificateId'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $certificate = $this->sslCertificateModel->findByDomainAndId($id, $certificateId);
        if (!$certificate) {
            $_SESSION['error'] = 'SSL certificate not found';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        if ($this->isDefaultRootSslTarget($certificate, $domain['domain_name'])
            && $this->countStoredRootSslTargets($id, $domain['domain_name']) <= 1) {
            $_SESSION['error'] = 'Add another root SSL endpoint first if you want to replace the default port 443 check';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $this->sslCertificateModel->deleteByDomainAndId($id, $certificateId);
        $_SESSION['success'] = 'SSL certificate removed for '
            . $this->sslService->formatTargetLabel($certificate['hostname'], (int)($certificate['port'] ?? 443));
        $this->redirectBackToDomain($id, '#ssl');
    }

    /**
     * Delete selected monitored SSL hosts.
     */
    public function bulkDeleteSsl($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $id = (int)($params['id'] ?? 0);
        $domain = $this->checkDomainAccess($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $ids = $this->parseSslCertificateIds($_POST['certificate_ids'] ?? '');
        if (empty($ids)) {
            $_SESSION['warning'] = 'Select at least one SSL certificate to remove';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $storedRootTargetCount = $this->countStoredRootSslTargets($id, $domain['domain_name']);
        $selectedCertificates = [];
        foreach ($ids as $certificateId) {
            $certificate = $this->sslCertificateModel->findByDomainAndId($id, $certificateId);
            if ($certificate) {
                $selectedCertificates[] = $certificate;
            }
        }

        $selectedRootTargetCount = count(array_filter(
            $selectedCertificates,
            fn(array $certificate): bool => strtolower($certificate['hostname']) === strtolower($domain['domain_name'])
        ));

        $deletableIds = [];
        foreach ($selectedCertificates as $certificate) {
            if ($this->isDefaultRootSslTarget($certificate, $domain['domain_name'])
                && ($storedRootTargetCount - $selectedRootTargetCount) < 1) {
                continue;
            }

            $deletableIds[] = (int)$certificate['id'];
        }

        if (empty($deletableIds)) {
            $_SESSION['warning'] = 'No removable SSL certificates were selected. Add another root endpoint first if you want to replace port 443.';
            $this->redirectBackToDomain($id, '#ssl');
            return;
        }

        $deleted = $this->sslCertificateModel->deleteByDomainAndIds($id, $deletableIds);
        $_SESSION['success'] = 'Removed ' . $deleted . ' SSL certificate(s).';
        $this->redirectBackToDomain($id, '#ssl');
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

