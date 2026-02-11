<?php

namespace App\Controllers;

use App\Models\Tag;
use App\Models\Domain;
use Core\Controller;

class TagController extends Controller
{
    private $tagModel;
    private $domainModel;

    public function __construct()
    {
        $this->tagModel = new Tag();
        $this->domainModel = new Domain();
    }

    /**
     * Show tag management page
     */
    public function index()
    {
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get filter parameters
        $search = $_GET['search'] ?? '';
        $color = $_GET['color'] ?? '';
        $type = $_GET['type'] ?? '';
        $sortBy = $_GET['sort'] ?? 'name';
        $sortOrder = $_GET['order'] ?? 'asc';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25))); // Between 10 and 100
        
        // Prepare filters array
        $filters = [
            'search' => $search,
            'color' => $color,
            'type' => $type,
            'sort' => $sortBy,
            'order' => $sortOrder
        ];
        
        // Get filtered and paginated tags
        $result = $this->tagModel->getFilteredPaginated($filters, $sortBy, $sortOrder, $page, $perPage, $isolationMode === 'isolated' ? $userId : null);
        
        $availableColors = $this->tagModel->getAvailableColors();
        
        // Get users for transfer functionality (admin only)
        $users = [];
        if (\Core\Auth::isAdmin()) {
            $userModel = new \App\Models\User();
            $users = $userModel->all();
        }
        
        $this->view('tags/index', [
            'tags' => $result['tags'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'availableColors' => $availableColors,
            'isolationMode' => $isolationMode,
            'users' => $users
        ]);
    }

    /**
     * Export user's private tags as CSV or JSON
     */
    public function export()
    {
        $logger = new \App\Services\Logger('export');

        try {
            $userId = \Core\Auth::id();
            $format = $_GET['format'] ?? 'csv';
            $logger->info("Tags export started", ['format' => $format, 'user_id' => $userId]);

            if (!in_array($format, ['csv', 'json'])) {
                $_SESSION['error'] = 'Invalid export format';
                $this->redirect('/tags');
                return;
            }

            // Get only the user's private tags (not global)
            $allUserTags = $this->tagModel->where('user_id', $userId);
            usort($allUserTags, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            // Map CSS class to readable color name for export
            $colorNames = $this->tagModel->getAvailableColors(); // cssClass => 'Name'
            $tags = array_map(fn($t) => [
                'name' => $t['name'],
                'color' => $colorNames[$t['color']] ?? 'Gray',
                'description' => $t['description'] ?? ''
            ], $allUserTags);

            $logger->info("Tags data prepared", ['count' => count($tags)]);

            $date = date('Y-m-d');
            $filename = "tags_export_{$date}";

            // Clean any prior output buffers to prevent header conflicts
            $obLevel = ob_get_level();
            $logger->debug("Output buffer level before clean", ['ob_level' => $obLevel]);
            while (ob_get_level()) {
                ob_end_clean();
            }

            $headersSent = headers_sent($sentFile, $sentLine);
            $logger->debug("Headers status before sending", [
                'headers_already_sent' => $headersSent,
                'sent_file' => $sentFile ?? null,
                'sent_line' => $sentLine ?? null
            ]);

            if ($format === 'json') {
                header('Content-Type: application/json');
                header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo json_encode($tags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                // Build CSV in memory to avoid fopen('php://output') issues
                $csvContent = $this->buildCsv($tags, ['name', 'color', 'description']);
                $logger->info("CSV content built", ['bytes' => strlen($csvContent)]);

                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                header('Content-Length: ' . strlen($csvContent));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
                echo $csvContent;
            }

            $logger->info("Tags export completed successfully");
            exit;
        } catch (\Throwable $e) {
            $logger->error("Tags export failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
            $this->redirect('/tags');
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
     * Import tags from CSV or JSON file
     */
    public function import()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Please select a valid file to import';
            $this->redirect('/tags');
            return;
        }

        $file = $_FILES['import_file'];

        // Validate file size (1MB max)
        if ($file['size'] > 1048576) {
            $_SESSION['error'] = 'File is too large. Maximum size is 1MB';
            $this->redirect('/tags');
            return;
        }

        // Detect format from extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $_SESSION['error'] = 'Invalid file type. Please upload a CSV or JSON file';
            $this->redirect('/tags');
            return;
        }

        $content = file_get_contents($file['tmp_name']);
        $tagsData = [];

        if ($ext === 'json') {
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $_SESSION['error'] = 'Invalid JSON file';
                $this->redirect('/tags');
                return;
            }
            $tagsData = $parsed;
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
                $tagsData[] = $item;
            }
        }

        if (empty($tagsData)) {
            $_SESSION['error'] = 'No tags found in file';
            $this->redirect('/tags');
            return;
        }

        $userId = \Core\Auth::id();
        $colorMap = $this->tagModel->getAvailableColors(); // cssClass => 'Name'
        $availableColorClasses = array_keys($colorMap);
        // Build reverse map: lowercase name => cssClass (e.g. 'blue' => 'bg-blue-100 ...')
        $nameToClass = [];
        foreach ($colorMap as $cssClass => $colorName) {
            $nameToClass[strtolower($colorName)] = $cssClass;
        }
        $defaultColor = $availableColorClasses[0] ?? 'bg-gray-100 text-gray-700 border-gray-300';
        $created = 0;
        $skipped = 0;

        foreach ($tagsData as $tagRow) {
            $name = trim($tagRow['name'] ?? '');
            if (empty($name) || !preg_match('/^[a-z0-9-]+$/', $name)) {
                $skipped++;
                continue;
            }

            // Check if already exists for this user
            $existing = $this->tagModel->findByName($name, $userId);
            if ($existing) {
                $skipped++;
                continue;
            }

            // Accept both color names ("Blue") and raw CSS classes
            $colorInput = trim($tagRow['color'] ?? '');
            if (!empty($colorInput)) {
                if (isset($nameToClass[strtolower($colorInput)])) {
                    // Human-readable name (e.g. "Blue")
                    $color = $nameToClass[strtolower($colorInput)];
                } elseif (in_array($colorInput, $availableColorClasses)) {
                    // Raw CSS class (backward compatible)
                    $color = $colorInput;
                } else {
                    $color = $defaultColor;
                }
            } else {
                $color = $defaultColor;
            }

            $this->tagModel->create([
                'name' => $name,
                'color' => $color,
                'description' => trim($tagRow['description'] ?? ''),
                'user_id' => $userId
            ]);
            $created++;
        }

        $_SESSION['success'] = "{$created} tag(s) imported successfully" . ($skipped > 0 ? ", {$skipped} skipped (already exist or invalid)" : '');
        $this->redirect('/tags');
    }

    /**
     * Create new tag
     */
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? 'bg-gray-100 text-gray-700 border-gray-300';
        $description = trim($_POST['description'] ?? '');
        $userId = \Core\Auth::id();

        if (empty($name)) {
            $_SESSION['error'] = 'Tag name is required';
            $this->redirect('/tags');
            return;
        }

        // Validate tag name format
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            $_SESSION['error'] = 'Invalid tag name format (use only letters, numbers, and hyphens)';
            $this->redirect('/tags');
            return;
        }

        // Check isolation mode
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $data = [
            'name' => $name,
            'color' => $color,
            'description' => $description,
            'user_id' => $isolationMode === 'isolated' ? $userId : null
        ];

        if ($this->tagModel->create($data)) {
            $_SESSION['success'] = "Tag '$name' created successfully";
        } else {
            $_SESSION['error'] = 'Failed to create tag (name may already exist)';
        }

        $this->redirect('/tags');
    }

    /**
     * Update tag
     */
    public function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? 'bg-gray-100 text-gray-700 border-gray-300';
        $description = trim($_POST['description'] ?? '');
        $userId = \Core\Auth::id();

        if (!$id || empty($name)) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/tags');
            return;
        }

        // Check if user can access this tag in isolation mode
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        if ($isolationMode === 'isolated' && !$this->tagModel->canUserAccessTag($id, $userId, true)) {
            $_SESSION['error'] = 'You do not have permission to edit this tag';
            $this->redirect('/tags');
            return;
        }

        // Check if this is a global tag (user_id = NULL) - only admins can edit global tags
        $tag = $this->tagModel->find($id);
        if ($tag && $tag['user_id'] === null && !\Core\Auth::isAdmin()) {
            $_SESSION['error'] = 'Only administrators can edit global tags';
            $this->redirect('/tags');
            return;
        }

        // Validate tag name format
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            $_SESSION['error'] = 'Invalid tag name format (use only letters, numbers, and hyphens)';
            $this->redirect('/tags');
            return;
        }

        $data = [
            'name' => $name,
            'color' => $color,
            'description' => $description
        ];

        if ($this->tagModel->update($id, $data)) {
            $_SESSION['success'] = "Tag updated successfully";
        } else {
            $_SESSION['error'] = 'Failed to update tag';
        }

        $this->redirect('/tags');
    }

    /**
     * Delete tag
     */
    public function delete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $id = (int)($_POST['id'] ?? 0);
        $userId = \Core\Auth::id();

        if (!$id) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/tags');
            return;
        }

        // Check if user can access this tag in isolation mode
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        if ($isolationMode === 'isolated' && !$this->tagModel->canUserAccessTag($id, $userId, true)) {
            $_SESSION['error'] = 'You do not have permission to delete this tag';
            $this->redirect('/tags');
            return;
        }

        // Check if this is a global tag (user_id = NULL) - only admins can delete global tags
        $tag = $this->tagModel->find($id);
        if ($tag && $tag['user_id'] === null && !\Core\Auth::isAdmin()) {
            $_SESSION['error'] = 'Only administrators can delete global tags';
            $this->redirect('/tags');
            return;
        }

        $tag = $this->tagModel->find($id);
        if (!$tag) {
            $_SESSION['error'] = 'Tag not found';
            $this->redirect('/tags');
            return;
        }

        if ($this->tagModel->deleteWithRelationships($id)) {
            $_SESSION['success'] = "Tag '{$tag['name']}' deleted successfully";
        } else {
            $_SESSION['error'] = 'Failed to delete tag';
        }

        $this->redirect('/tags');
    }

    /**
     * Show domains for a specific tag
     */
    public function show($params = [])
    {
        $id = (int)($params['id'] ?? 0);
        
        if (!$id) {
            $_SESSION['error'] = 'Invalid tag ID';
            $this->redirect('/tags');
            return;
        }

        $tag = $this->tagModel->find($id);
        if (!$tag) {
            $_SESSION['error'] = 'Tag not found';
            $this->redirect('/tags');
            return;
        }

        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Check if user can access this tag in isolation mode
        if ($isolationMode === 'isolated' && !$this->tagModel->canUserAccessTag($id, $userId, true)) {
            $_SESSION['error'] = 'You do not have permission to view this tag.';
            $this->redirect('/tags');
            return;
        }
        
        // Get domains for this tag with proper formatting
        $domainModel = new \App\Models\Domain();
        $rawDomains = $this->tagModel->getDomainsForTag($id, $isolationMode === 'isolated' ? $userId : null);
        
        // Format domains using DomainHelper (same as other pages)
        $domains = [];
        foreach ($rawDomains as $domain) {
            $domains[] = \App\Helpers\DomainHelper::formatForDisplay($domain);
        }
        
        // Get current filters from request
        $filters = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'registrar' => $_GET['registrar'] ?? '',
            'sort' => $_GET['sort'] ?? 'domain_name',
            'order' => $_GET['order'] ?? 'asc'
        ];
        
        // Apply filters
        if (!empty($filters['search'])) {
            $domains = array_filter($domains, function($domain) use ($filters) {
                return stripos($domain['domain_name'], $filters['search']) !== false;
            });
        }
        
        if (!empty($filters['status'])) {
            $domains = array_filter($domains, function($domain) use ($filters) {
                return $domain['status'] === $filters['status'];
            });
        }
        
        if (!empty($filters['registrar'])) {
            $domains = array_filter($domains, function($domain) use ($filters) {
                return stripos($domain['registrar'] ?? '', $filters['registrar']) !== false;
            });
        }
        
        // Apply sorting
        usort($domains, function($a, $b) use ($filters) {
            $aVal = $a[$filters['sort']] ?? '';
            $bVal = $b[$filters['sort']] ?? '';
            
            $comparison = strcasecmp($aVal, $bVal);
            return $filters['order'] === 'desc' ? -$comparison : $comparison;
        });
        
        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
        $total = count($domains);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedDomains = array_slice($domains, $offset, $perPage);
        
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'showing_from' => $total > 0 ? $offset + 1 : 0,
            'showing_to' => min($offset + $perPage, $total)
        ];
        
        $this->view('tags/view', [
            'tag' => $tag,
            'domains' => $paginatedDomains,
            'filters' => $filters,
            'pagination' => $pagination
        ]);
    }

    /**
     * Bulk add tag to domains
     */
    public function bulkAddToDomains()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $tagId = (int)($_POST['tag_id'] ?? 0);

        if (empty($domainIds) || !$tagId) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/domains');
            return;
        }

        $tag = $this->tagModel->find($tagId);
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
            
            if ($domain && $this->tagModel->addToDomain($domainId, $tagId)) {
                $added++;
            }
        }

        $_SESSION['success'] = "Tag '{$tag['name']}' added to $added domain(s)";
        $this->redirect('/domains');
    }

    /**
     * Bulk remove tag from domains
     */
    public function bulkRemoveFromDomains()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/domains');
            return;
        }

        $this->verifyCsrf('/domains');

        $domainIds = $_POST['domain_ids'] ?? [];
        $tagId = (int)($_POST['tag_id'] ?? 0);

        if (empty($domainIds) || !$tagId) {
            $_SESSION['error'] = 'Invalid request';
            $this->redirect('/domains');
            return;
        }

        $tag = $this->tagModel->find($tagId);
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
            
            if ($domain && $this->tagModel->removeFromDomain($domainId, $tagId)) {
                $removed++;
            }
        }

        $_SESSION['success'] = "Tag '{$tag['name']}' removed from $removed domain(s)";
        $this->redirect('/domains');
    }
    
    /**
     * Bulk delete tags
     */
    public function bulkDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $tagIds = $_POST['tag_ids'] ?? [];
        if (empty($tagIds)) {
            $_SESSION['error'] = 'No tags selected';
            $this->redirect('/tags');
            return;
        }

        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        $deleted = 0;
        $errors = [];

        foreach ($tagIds as $tagId) {
            $tagId = (int)$tagId;
            
            // Check if user can access this tag
            if (!$this->tagModel->canUserAccessTag($tagId, $userId, $isolationMode === 'isolated')) {
                $errors[] = "You don't have permission to delete tag ID $tagId";
                continue;
            }

            // Check if it's a global tag and user is not admin
            $tag = $this->tagModel->find($tagId);
            if ($tag && $tag['user_id'] === null && !\Core\Auth::isAdmin()) {
                $errors[] = "Only administrators can delete global tags";
                continue;
            }

            if ($this->tagModel->delete($tagId)) {
                $deleted++;
            } else {
                $errors[] = "Failed to delete tag ID $tagId";
            }
        }

        if ($deleted > 0) {
            $_SESSION['success'] = "$deleted tag(s) deleted successfully";
        }
        
        if (!empty($errors)) {
            $_SESSION['error'] = implode(', ', $errors);
        }

        $this->redirect('/tags');
    }

    /**
     * Transfer tag to another user (Admin only)
     */
    public function transfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $tagId = (int)($_POST['tag_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (!$tagId || !$targetUserId) {
            $_SESSION['error'] = 'Invalid tag or user selected';
            $this->redirect('/tags');
            return;
        }

        $tag = $this->tagModel->find($tagId);
        if (!$tag) {
            $_SESSION['error'] = 'Tag not found';
            $this->redirect('/tags');
            return;
        }

        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/tags');
            return;
        }

        if ($this->tagModel->update($tagId, ['user_id' => $targetUserId])) {
            $_SESSION['success'] = "Tag '{$tag['name']}' transferred to {$targetUser['username']}";
        } else {
            $_SESSION['error'] = 'Failed to transfer tag. Please try again.';
        }

        $this->redirect('/tags');
    }

    /**
     * Bulk transfer tags to another user (Admin only)
     */
    public function bulkTransfer()
    {
        \Core\Auth::requireAdmin();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/tags');
            return;
        }

        $this->verifyCsrf('/tags');

        $tagIds = $_POST['tag_ids'] ?? [];
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if (empty($tagIds) || !$targetUserId) {
            $_SESSION['error'] = 'No tags selected or invalid user';
            $this->redirect('/tags');
            return;
        }

        $userModel = new \App\Models\User();
        $targetUser = $userModel->find($targetUserId);
        if (!$targetUser) {
            $_SESSION['error'] = 'Target user not found';
            $this->redirect('/tags');
            return;
        }

        $transferred = 0;
        foreach ($tagIds as $tagId) {
            $tagId = (int)$tagId;
            if ($tagId > 0) {
                $tag = $this->tagModel->find($tagId);
                if ($tag && $this->tagModel->update($tagId, ['user_id' => $targetUserId])) {
                    $transferred++;
                }
            }
        }

        $_SESSION['success'] = $transferred . ' tag(s) transferred to ' . $targetUser['username'];
        $this->redirect('/tags');
    }
}
