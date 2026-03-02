<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\TldRegistry;
use App\Models\TldImportLog;
use App\Services\TldRegistryService;
use App\Services\Logger;

class TldRegistryController extends Controller
{
    private TldRegistry $tldModel;
    private TldImportLog $importLogModel;
    private TldRegistryService $tldService;
    private Logger $logger;

    public function __construct()
    {
        $this->tldModel = new TldRegistry();
        $this->importLogModel = new TldImportLog();
        $this->tldService = new TldRegistryService();
        $this->logger = new Logger('tld_registry_controller');
    }
    /**
     * Display TLD registry dashboard
     */
    public function index()
    {
        $search = \App\Helpers\InputValidator::sanitizeSearch($_GET['search'] ?? '', 100);
        $status = $_GET['status'] ?? '';
        $dataType = $_GET['data_type'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 50)));
        $sort = $_GET['sort'] ?? 'tld';
        $order = $_GET['order'] ?? 'asc';

        $result = $this->tldModel->getPaginated($page, $perPage, $search, $sort, $order, $status, $dataType);
        $tldStats = $this->tldModel->getStatistics();

        $this->view('tld-registry/index', [
            'tlds' => $result['tlds'],
            'pagination' => $result['pagination'],
            'tldStats' => $tldStats,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'data_type' => $dataType,
                'sort' => $sort,
                'order' => $order
            ],
            'title' => 'TLD Registry'
        ]);
    }

    /**
     * Show TLD details
     */
    public function show($params = [])
    {
        $id = $params['id'] ?? 0;
        $tld = $this->tldModel->find($id);

        if (!$tld) {
            $_SESSION['error'] = 'TLD not found';
            $this->redirect('/tld-registry');
            return;
        }

        $this->view('tld-registry/view', [
            'tld' => $tld,
            'title' => 'TLD: ' . $tld['tld']
        ]);
    }

    /**
     * Import TLD list from IANA
     */
    public function importTldList()
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        try {
            $stats = $this->tldService->importTldList();
            
            $message = "TLD list import completed: ";
            $message .= "{$stats['total_tlds']} total, ";
            $message .= "{$stats['new_tlds']} new, ";
            $message .= "{$stats['updated_tlds']} updated";
            
            if ($stats['failed_tlds'] > 0) {
                $message .= ", {$stats['failed_tlds']} failed";
            }
            
            $message .= ". Next: Import RDAP servers for these TLDs.";
            
            $_SESSION['success'] = $message;
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'TLD list import failed: ' . $e->getMessage();
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Import RDAP data from IANA
     */
    public function importRdap()
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        try {
            $stats = $this->tldService->importRdapData();
            
            $message = "RDAP import completed: ";
            $message .= "{$stats['total_tlds']} total, ";
            $message .= "{$stats['new_tlds']} new, ";
            $message .= "{$stats['updated_tlds']} updated";
            
            if ($stats['failed_tlds'] > 0) {
                $message .= ", {$stats['failed_tlds']} failed";
            }
            
            $message .= ". Next: Import WHOIS servers for TLDs missing RDAP.";
            
            $_SESSION['success'] = $message;
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'RDAP import failed: ' . $e->getMessage();
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Import WHOIS data for missing TLDs
     */
    public function importWhois()
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        try {
            $stats = $this->tldService->importWhoisDataForMissingTlds();
            $remainingCount = $this->tldService->getTldsNeedingWhoisCount();
            
            $message = "WHOIS import completed: ";
            $message .= "{$stats['total_tlds']} total, ";
            $message .= "{$stats['updated_tlds']} updated";
            
            if ($stats['failed_tlds'] > 0) {
                $message .= ", {$stats['failed_tlds']} failed";
            }
            
            if ($remainingCount > 0) {
                $message .= ". {$remainingCount} TLDs still need WHOIS data. Run import again to continue.";
            } else {
                $message .= ". TLD registry setup complete! Use 'Check Updates' to monitor for changes.";
            }
            
            $_SESSION['success'] = $message;
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'WHOIS import failed: ' . $e->getMessage();
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Check for IANA updates
     */
    public function checkUpdates()
    {
        Auth::requireAdmin();
        
        try {
            $updateInfo = $this->tldService->checkForUpdates();
            
            if ($updateInfo['overall_needs_update']) {
                $messages = [];
                
                if ($updateInfo['tld_list']['needs_update']) {
                    $messages[] = "TLD list updated: Version " . 
                        ($updateInfo['tld_list']['current_version'] ?? 'Unknown') . 
                        " (was " . ($updateInfo['tld_list']['last_version'] ?? 'None') . ")";
                }
                
                if ($updateInfo['rdap']['needs_update']) {
                    $messages[] = "RDAP data updated: " . 
                        ($updateInfo['rdap']['current_publication'] ?? 'Unknown') . 
                        " (was " . ($updateInfo['rdap']['last_publication'] ?? 'None') . ")";
                }
                
                $_SESSION['info'] = "IANA data has been updated. " . implode(' | ', $messages);
            } else {
                $_SESSION['success'] = "TLD registry is up to date";
            }
            
            // Show any errors
            if (!empty($updateInfo['errors'])) {
                $_SESSION['warning'] = "Some checks failed: " . implode(', ', $updateInfo['errors']);
            }
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to check for updates: ' . $e->getMessage();
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Start progressive import (universal)
     */
    public function startProgressiveImport()
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        $importType = $_POST['import_type'] ?? '';
        
        $this->logger->separator('Start Progressive Import');
        $this->logger->info('Import requested', [
            'type' => $importType,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'username' => $_SESSION['username'] ?? 'unknown'
        ]);
        
        if (!in_array($importType, ['tld_list', 'rdap', 'whois', 'check_updates', 'complete_workflow'])) {
            $this->logger->warning('Invalid import type provided', ['type' => $importType]);
            $_SESSION['error'] = 'Invalid import type';
            $this->redirect('/tld-registry');
            return;
        }

        try {
            $result = $this->tldService->startProgressiveImport($importType);
            
            $this->logger->info('Import started', [
                'status' => $result['status'],
                'log_id' => $result['log_id'] ?? null,
                'message' => $result['message'] ?? ''
            ]);
            
            if ($result['status'] === 'complete') {
                $_SESSION['success'] = $result['message'];
                $this->redirect('/tld-registry');
            } else {
                // Redirect to progress page
                $this->logger->info('Redirecting to progress page', ['log_id' => $result['log_id']]);
                $this->redirect('/tld-registry/import-progress/' . $result['log_id']);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to start import', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $_SESSION['error'] = 'Failed to start import: ' . $e->getMessage();
            $this->redirect('/tld-registry');
        }
    }

    /**
     * Show import progress page (universal)
     */
    public function importProgress($params = [])
    {
        $logId = $params['log_id'] ?? 0;
        
        $this->logger->info('Import progress page requested', ['log_id' => $logId]);
        
        if (!$logId) {
            $this->logger->warning('Progress page requested with no log_id');
            $_SESSION['error'] = 'Invalid import session';
            $this->redirect('/tld-registry');
            return;
        }

        // Get import type from log
        $log = $this->importLogModel->find($logId);
        if (!$log) {
            $this->logger->error('Import log not found', ['log_id' => $logId]);
            $_SESSION['error'] = 'Import log not found';
            $this->redirect('/tld-registry');
            return;
        }

        $importType = $log['import_type'];
        $this->logger->info('Showing progress page', [
            'log_id' => $logId,
            'import_type' => $importType,
            'status' => $log['status']
        ]);
        
        $titles = [
            'tld_list' => 'TLD List Import Progress',
            'rdap' => 'RDAP Import Progress',
            'whois' => 'WHOIS Import Progress',
            'check_updates' => 'Update Check Progress',
            'complete_workflow' => 'Complete TLD Import Workflow'
        ];

        $this->view('tld-registry/import-progress', [
            'log_id' => $logId,
            'import_type' => $importType,
            'title' => $titles[$importType] ?? 'Import Progress'
        ]);
    }

    /**
     * API endpoint to get import progress
     */
    public function apiGetImportProgress()
    {
        // Start detailed logging
        $this->logger->separator('API Import Progress Request');
        $this->logger->info('API called', [
            'log_id' => $_GET['log_id'] ?? 'none',
            'user_id' => $_SESSION['user_id'] ?? 'not set',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        // Start output buffering to catch any accidental output
        ob_start();
        
        try {
            // Clear any previous output
            ob_clean();
            
            // Set JSON header immediately
            header('Content-Type: application/json');
            
            $logId = $_GET['log_id'] ?? 0;
            
            if (!$logId) {
                $this->logger->warning('API call with missing log_id');
                ob_end_clean();
                $this->json(['error' => 'Log ID required'], 400);
                return;
            }

            // Ensure user is authenticated
            if (!isset($_SESSION['user_id'])) {
                $this->logger->warning('Unauthenticated API call attempt', ['log_id' => $logId]);
                ob_end_clean();
                $this->json(['error' => 'Authentication required'], 401);
                return;
            }

            $this->logger->info('Processing batch', ['log_id' => $logId]);
            
            // Add detailed logging around the service call
            $this->logger->info('About to call TldRegistryService::processNextBatch', [
                'log_id' => $logId,
                'service_class' => get_class($this->tldService)
            ]);
            
            try {
                $result = $this->tldService->processNextBatch($logId);
                $this->logger->info('processNextBatch returned successfully');
            } catch (\Throwable $e) {
                $this->logger->critical('processNextBatch threw exception', [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                throw $e;
            }
            
            $this->logger->info('Batch processing result', [
                'status' => $result['status'] ?? 'unknown',
                'processed' => $result['processed'] ?? 0,
                'remaining' => $result['remaining'] ?? 0
            ]);
            
            // Clean output buffer before sending JSON
            ob_end_clean();
            $this->json($result);
            
        } catch (\Throwable $e) {
            // Catch ALL errors including fatal errors
            // Clean any buffered output
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Detailed error logging
            $this->logger->critical('API Import Progress Fatal Error', [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'log_id' => $_GET['log_id'] ?? 'none'
            ]);
            $this->logger->error('Stack trace', ['trace' => $e->getTraceAsString()]);
            $this->logger->separator('API Error End');
            
            // Ensure we always send JSON
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            
            echo json_encode([
                'error' => 'An error occurred while processing the import',
                'message' => $e->getMessage(),
                'status' => 'error',
                'log_id' => $_GET['log_id'] ?? null,
                'error_type' => get_class($e)
            ]);
            exit;
        }
    }

    /**
     * Bulk delete TLDs
     */
    public function bulkDelete()
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        $tldIds = $_POST['tld_ids'] ?? [];
        
        if (empty($tldIds)) {
            $_SESSION['error'] = 'No TLDs selected for deletion';
            $this->redirect('/tld-registry');
            return;
        }

        // Validate bulk operation size
        $sizeError = \App\Helpers\InputValidator::validateArraySize($tldIds, 500, 'TLD selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect('/tld-registry');
            return;
        }

        try {
            $deletedCount = 0;
            foreach ($tldIds as $id) {
                if ($this->tldModel->delete($id)) {
                    $deletedCount++;
                }
            }
            
            $_SESSION['success'] = "Successfully deleted {$deletedCount} TLD(s)";
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to delete TLDs: ' . $e->getMessage();
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Toggle TLD active status
     */
    public function toggleActive($params = [])
    {
        Auth::requireAdmin();
        
        $id = $params['id'] ?? 0;
        $tld = $this->tldModel->find($id);

        if (!$tld) {
            $_SESSION['error'] = 'TLD not found';
            $this->redirect('/tld-registry');
            return;
        }

        $this->tldModel->toggleActive($id);
        
        $status = $tld['is_active'] ? 'disabled' : 'enabled';
        $_SESSION['success'] = "TLD {$tld['tld']} has been {$status}";
        
        // Redirect back to the same page (either view page or index page)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, '/tld-registry/' . $id) !== false) {
            // Came from view page, go back to view page
            $this->redirect('/tld-registry/' . $id);
        } else {
            // Came from index page or elsewhere, go to index
            $this->redirect('/tld-registry');
        }
    }

    /**
     * Refresh TLD data from IANA
     */
    public function refresh($params = [])
    {
        Auth::requireAdmin();
        
        $id = $params['id'] ?? 0;
        $tld = $this->tldModel->find($id);

        if (!$tld) {
            $_SESSION['error'] = 'TLD not found';
            $this->redirect('/tld-registry');
            return;
        }

        try {
            // Remove dot from TLD for URL
            $tldForUrl = ltrim($tld['tld'], '.');
            $url = "https://www.iana.org/domains/root/db/{$tldForUrl}.html";

            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
            $html = $response->getBody()->getContents();

            // Extract data from HTML
            $whoisServer = $this->extractWhoisServer($html);
            $lastUpdated = $this->extractLastUpdated($html);
            $registryUrl = $this->extractRegistryUrl($html);
            $registrationDate = $this->extractRegistrationDate($html);

            $updateData = [
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($whoisServer) $updateData['whois_server'] = $whoisServer;
            if ($lastUpdated) $updateData['record_last_updated'] = $lastUpdated;
            if ($registryUrl) $updateData['registry_url'] = $registryUrl;
            if ($registrationDate) $updateData['registration_date'] = $registrationDate;

            $this->tldModel->update($id, $updateData);
            
            $_SESSION['success'] = "TLD {$tld['tld']} data refreshed successfully";
            
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to refresh TLD data: ' . $e->getMessage();
        }

        // Redirect back to the same page (either view page or index page)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, '/tld-registry/' . $id) !== false) {
            // Came from view page, go back to view page
            $this->redirect('/tld-registry/' . $id);
        } else {
            // Came from index page or elsewhere, go to index
            $this->redirect('/tld-registry');
        }
    }

    /**
     * Show import logs
     */
    public function importLogs()
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));

        $result = $this->importLogModel->getPaginated($page, $perPage);
        $importStats = $this->importLogModel->getImportStatistics();

        $this->view('tld-registry/import-logs', [
            'imports' => $result['logs'],
            'pagination' => $result['pagination'],
            'importStats' => $importStats,
            'title' => 'TLD Import Logs'
        ]);
    }

    /**
     * API endpoint to get TLD info for a domain
     */
    public function apiGetTldInfo()
    {
        $domain = $_GET['domain'] ?? '';
        
        if (empty($domain)) {
            $this->json(['error' => 'Domain parameter is required'], 400);
            return;
        }

        try {
            $tldInfo = $this->tldService->getTldInfo($domain);
            
            if ($tldInfo) {
                $this->json([
                    'success' => true,
                    'data' => $tldInfo
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'message' => 'TLD information not found'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export TLD registry as CSV or JSON
     */
    public function export()
    {
        Auth::requireAdmin();

        $logger = new \App\Services\Logger('export');

        try {
            $format = $_GET['format'] ?? 'csv';
            $logger->info('TLD registry export started', [
                'format' => $format,
                'user_id' => $_SESSION['user_id'] ?? 'unknown'
            ]);

            if (!in_array($format, ['csv', 'json'])) {
                $_SESSION['error'] = 'Invalid export format';
                $this->redirect('/tld-registry');
                return;
            }

            $allTlds = $this->tldModel->getAll();

            $tlds = array_map(fn($t) => [
                'tld' => $t['tld'],
                'whois_server' => $t['whois_server'] ?? '',
                'rdap_servers' => $t['rdap_servers'] ?? '',
                'registry_url' => $t['registry_url'] ?? '',
                'is_active' => $t['is_active'] ? 'yes' : 'no',
            ], $allTlds);

            $logger->info('TLD registry export data prepared', ['count' => count($tlds)]);

            $date = date('Y-m-d');
            $filename = "tld_registry_export_{$date}";

            while (ob_get_level()) {
                ob_end_clean();
            }

            if ($format === 'json') {
                header('Content-Type: application/json');
                header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo json_encode($tlds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $csvContent = $this->buildCsv($tlds, ['tld', 'whois_server', 'rdap_servers', 'registry_url', 'is_active']);
                $logger->info('CSV content built', ['bytes' => strlen($csvContent)]);

                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                header('Content-Length: ' . strlen($csvContent));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo $csvContent;
            }

            $logger->info('TLD registry export completed successfully');
            exit;

        } catch (\Throwable $e) {
            $logger->error('TLD registry export failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
            $this->redirect('/tld-registry');
        }
    }

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
     * Import TLDs from CSV or JSON file
     */
    public function import()
    {
        Auth::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        $this->verifyCsrf('/tld-registry');

        $logger = new \App\Services\Logger('import');
        $logger->info('TLD registry import started', [
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'username' => $_SESSION['username'] ?? 'unknown'
        ]);

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $logger->warning('No valid file uploaded for TLD import');
            $_SESSION['error'] = 'Please select a valid file to import';
            $this->redirect('/tld-registry');
            return;
        }

        $file = $_FILES['import_file'];
        $logger->info('Import file received', [
            'filename' => $file['name'],
            'size' => $file['size']
        ]);

        if ($file['size'] > 5242880) {
            $logger->warning('Import file too large', ['size' => $file['size']]);
            $_SESSION['error'] = 'File is too large. Maximum size is 5MB';
            $this->redirect('/tld-registry');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $logger->warning('Invalid file type for TLD import', ['extension' => $ext]);
            $_SESSION['error'] = 'Invalid file type. Please upload a CSV or JSON file';
            $this->redirect('/tld-registry');
            return;
        }

        $content = file_get_contents($file['tmp_name']);
        $tldsData = [];

        if ($ext === 'json') {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $logger->error('Invalid JSON file for TLD import');
                $_SESSION['error'] = 'Invalid JSON file';
                $this->redirect('/tld-registry');
                return;
            }
            $tldsData = $parsed;
        } else {
            $lines = array_filter(explode("\n", $content));
            $header = null;
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
                $tldsData[] = $item;
            }
        }

        if (empty($tldsData)) {
            $logger->warning('No TLD data found in import file');
            $_SESSION['error'] = 'No TLD data found in the file';
            $this->redirect('/tld-registry');
            return;
        }

        $logger->info('TLD data parsed from file', ['entries' => count($tldsData)]);

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($tldsData as $tldRow) {
            $tldName = trim($tldRow['tld'] ?? '');
            if (empty($tldName)) {
                $skipped++;
                continue;
            }

            if (!str_starts_with($tldName, '.')) {
                $tldName = '.' . $tldName;
            }

            $existing = $this->tldModel->findByTld($tldName);

            $data = [
                'tld' => $tldName,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (!empty($tldRow['whois_server'])) {
                $data['whois_server'] = trim($tldRow['whois_server']);
            }
            if (!empty($tldRow['rdap_servers'])) {
                $rdap = trim($tldRow['rdap_servers']);
                if (str_starts_with($rdap, '[')) {
                    $data['rdap_servers'] = $rdap;
                } else {
                    $servers = array_filter(array_map('trim', preg_split('/[,;]+/', $rdap)));
                    $data['rdap_servers'] = json_encode(array_values($servers));
                }
            }
            if (!empty($tldRow['registry_url'])) {
                $data['registry_url'] = trim($tldRow['registry_url']);
            }
            if (isset($tldRow['is_active'])) {
                $val = strtolower(trim($tldRow['is_active']));
                $data['is_active'] = in_array($val, ['yes', '1', 'true', 'active']) ? 1 : 0;
            }

            if ($existing) {
                unset($data['tld']);
                $this->tldModel->update($existing['id'], $data);
                $updated++;
            } else {
                if (!isset($data['is_active'])) {
                    $data['is_active'] = 1;
                }
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->tldModel->create($data);
                $imported++;
            }
        }

        $logger->info('TLD registry import completed', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped
        ]);

        $message = "Import completed: {$imported} new, {$updated} updated";
        if ($skipped > 0) {
            $message .= ", {$skipped} skipped";
        }
        $_SESSION['success'] = $message;
        $this->redirect('/tld-registry');
    }

    /**
     * Create a new TLD registry entry manually
     */
    public function createTld()
    {
        Auth::requireAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        $this->verifyCsrf('/tld-registry');

        $tldName = trim($_POST['tld'] ?? '');
        $whoisServer = trim($_POST['whois_server'] ?? '');
        $rdapServersInput = trim($_POST['rdap_servers'] ?? '');
        $registryUrl = trim($_POST['registry_url'] ?? '');

        $this->logger->info('Manual TLD creation requested', [
            'tld' => $tldName,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'username' => $_SESSION['username'] ?? 'unknown'
        ]);

        if (empty($tldName)) {
            $_SESSION['error'] = 'TLD name is required';
            $this->redirect('/tld-registry');
            return;
        }

        if (!str_starts_with($tldName, '.')) {
            $tldName = '.' . $tldName;
        }

        $tldName = strtolower($tldName);

        if (!preg_match('/^\.[a-z0-9\-]+(\.[a-z0-9\-]+)*$/', $tldName)) {
            $this->logger->warning('Invalid TLD format provided', ['tld' => $tldName]);
            $_SESSION['error'] = 'Invalid TLD format. Use only letters, numbers, hyphens, and dots for multi-level TLDs (e.g., .co.uk).';
            $this->redirect('/tld-registry');
            return;
        }

        $existing = $this->tldModel->findByTld($tldName);
        if ($existing) {
            $this->logger->warning('Attempted to create duplicate TLD', ['tld' => $tldName]);
            $_SESSION['error'] = "TLD {$tldName} already exists in the registry";
            $this->redirect('/tld-registry');
            return;
        }

        if (!empty($whoisServer) && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $whoisServer)) {
            $_SESSION['error'] = 'Invalid WHOIS server format';
            $this->redirect('/tld-registry');
            return;
        }

        $rdapServers = [];
        if (!empty($rdapServersInput)) {
            $servers = preg_split('/[,\n\r]+/', $rdapServersInput);
            foreach ($servers as $server) {
                $server = trim($server);
                if (!empty($server)) {
                    $server = rtrim($server, '/') . '/';
                    $rdapServers[] = $server;
                }
            }
        }

        try {
            $data = [
                'tld' => $tldName,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (!empty($whoisServer)) {
                $data['whois_server'] = $whoisServer;
            }
            if (!empty($rdapServers)) {
                $data['rdap_servers'] = json_encode($rdapServers);
            }
            if (!empty($registryUrl)) {
                $data['registry_url'] = $registryUrl;
            }

            $this->tldModel->create($data);

            $this->logger->info('TLD created successfully', [
                'tld' => $tldName,
                'whois_server' => $whoisServer ?: null,
                'rdap_servers_count' => count($rdapServers),
                'registry_url' => $registryUrl ?: null
            ]);

            $_SESSION['success'] = "TLD {$tldName} created successfully";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to create TLD: ' . $e->getMessage();
            $this->logger->error('Failed to create TLD', [
                'tld' => $tldName,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }

        $this->redirect('/tld-registry');
    }

    /**
     * Extract WHOIS server from HTML
     */
    private function extractWhoisServer(string $html): ?string
    {
        if (preg_match('/WHOIS Server:\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extract last updated date from HTML
     */
    private function extractLastUpdated(string $html): ?string
    {
        if (preg_match('/Record last updated\s+(\d{4}-\d{2}-\d{2})/i', $html, $matches)) {
            return $matches[1] . ' 00:00:00';
        }
        return null;
    }

    /**
     * Extract registry URL from HTML
     */
    private function extractRegistryUrl(string $html): ?string
    {
        if (preg_match('/URL for registration services:\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extract registration date from HTML
     */
    private function extractRegistrationDate(string $html): ?string
    {
        if (preg_match('/Registration date\s+(\d{4}-\d{2}-\d{2})/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Update WHOIS server for a TLD
     */
    public function updateWhoisServer($params = [])
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        $id = $params['id'] ?? 0;
        $tld = $this->tldModel->find($id);

        if (!$tld) {
            $_SESSION['error'] = 'TLD not found';
            $this->redirect('/tld-registry');
            return;
        }

        $whoisServer = trim($_POST['whois_server'] ?? '');

        // Validate WHOIS server format (basic validation)
        if (!empty($whoisServer) && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $whoisServer)) {
            $_SESSION['error'] = 'Invalid WHOIS server format';
            $this->redirect('/tld-registry/' . $id);
            return;
        }

        try {
            $this->tldModel->update($id, [
                'whois_server' => !empty($whoisServer) ? $whoisServer : null,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info('WHOIS server updated', [
                'tld_id' => $id,
                'tld' => $tld['tld'],
                'whois_server' => $whoisServer,
                'user_id' => $_SESSION['user_id'] ?? 'unknown'
            ]);

            $_SESSION['success'] = "WHOIS server updated successfully for {$tld['tld']}";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update WHOIS server: ' . $e->getMessage();
            $this->logger->error('Failed to update WHOIS server', [
                'tld_id' => $id,
                'error' => $e->getMessage()
            ]);
        }

        $this->redirect('/tld-registry/' . $id);
    }

    /**
     * Update RDAP servers for a TLD
     */
    public function updateRdapServers($params = [])
    {
        Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tld-registry');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/tld-registry');

        $id = $params['id'] ?? 0;
        $tld = $this->tldModel->find($id);

        if (!$tld) {
            $_SESSION['error'] = 'TLD not found';
            $this->redirect('/tld-registry');
            return;
        }

        $rdapServersInput = trim($_POST['rdap_servers'] ?? '');
        $rdapServers = [];

        // Parse RDAP servers (comma or newline separated)
        if (!empty($rdapServersInput)) {
            $servers = preg_split('/[,\n\r]+/', $rdapServersInput);
            foreach ($servers as $server) {
                $server = trim($server);
                if (!empty($server)) {
                    // Basic URL validation
                    if (filter_var($server, FILTER_VALIDATE_URL) || 
                        preg_match('/^https?:\/\/[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i', $server)) {
                        // Ensure URL ends with / if not already
                        $server = rtrim($server, '/') . '/';
                        $rdapServers[] = $server;
                    }
                }
            }
        }

        try {
            $this->tldModel->update($id, [
                'rdap_servers' => !empty($rdapServers) ? json_encode($rdapServers) : null,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info('RDAP servers updated', [
                'tld_id' => $id,
                'tld' => $tld['tld'],
                'rdap_servers_count' => count($rdapServers),
                'user_id' => $_SESSION['user_id'] ?? 'unknown'
            ]);

            $_SESSION['success'] = "RDAP servers updated successfully for {$tld['tld']} (" . count($rdapServers) . " server(s))";
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update RDAP servers: ' . $e->getMessage();
            $this->logger->error('Failed to update RDAP servers', [
                'tld_id' => $id,
                'error' => $e->getMessage()
            ]);
        }

        $this->redirect('/tld-registry/' . $id);
    }
}
