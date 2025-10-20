<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\Domain;
use App\Services\WhoisService;

class SearchController extends Controller
{
    private Domain $domainModel;
    private WhoisService $whoisService;

    public function __construct()
    {
        $this->domainModel = new Domain();
        $this->whoisService = new WhoisService();
    }

    public function index()
    {
        $query = \App\Helpers\InputValidator::sanitizeSearch($_GET['q'] ?? '', 100);

        if (empty($query)) {
            $_SESSION['error'] = 'Please enter a search term';
            $this->redirect('/domains');
            return;
        }

        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');

        // Pagination parameters
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));

        // Search existing domains in database
        $allResults = $this->searchDomains($query, $isolationMode === 'isolated' ? $userId : null);
        $totalResults = count($allResults);

        // Calculate pagination
        $totalPages = ceil($totalResults / $perPage);
        $page = min($page, max(1, $totalPages)); // Ensure page is within valid range
        $offset = ($page - 1) * $perPage;

        // Slice results for current page
        $existingDomains = array_slice($allResults, $offset, $perPage);

        // Check if query looks like a domain name
        $isDomainLike = $this->isDomainFormat($query);

        // If it looks like a domain and not found in database, offer WHOIS lookup
        $whoisData = null;
        $whoisError = null;
        
        if ($isDomainLike && empty($allResults)) {
            // Do WHOIS lookup
            $whoisData = $this->whoisService->getDomainInfo($query);
            if (!$whoisData) {
                $whoisError = "Could not retrieve WHOIS information for '$query'";
            }
        }

        // Format existing domains for display
        $formattedDomains = \App\Helpers\DomainHelper::formatMultiple($existingDomains);
        
        $this->view('search/results', [
            'query' => $query,
            'existingDomains' => $formattedDomains,
            'whoisData' => $whoisData,
            'whoisError' => $whoisError,
            'isDomainLike' => $isDomainLike,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalResults,
                'total_pages' => $totalPages,
                'showing_from' => $totalResults > 0 ? $offset + 1 : 0,
                'showing_to' => min($offset + $perPage, $totalResults)
            ],
            'title' => 'Search Results'
        ]);
    }

    /**
     * AJAX endpoint for live search suggestions
     */
    public function suggest()
    {
        header('Content-Type: application/json');
        
        $query = \App\Helpers\InputValidator::sanitizeSearch($_GET['q'] ?? '', 100);
        
        if (empty($query)) {
            echo json_encode(['domains' => [], 'isDomainLike' => false]);
            exit;
        }

        // Search existing domains (limit to 5 for quick results)
        $db = \Core\Database::getConnection();
        $sql = "SELECT d.id, d.domain_name, d.registrar, d.expiration_date, d.status, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE d.domain_name LIKE ? 
                   OR d.registrar LIKE ?
                ORDER BY d.domain_name ASC
                LIMIT 5";

        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm]);
        $results = $stmt->fetchAll();

        // Calculate days left for each domain
        foreach ($results as &$domain) {
            if (!empty($domain['expiration_date'])) {
                $daysLeft = floor((strtotime($domain['expiration_date']) - time()) / 86400);
                $domain['days_left'] = $daysLeft;
                
                // Color coding
                if ($daysLeft < 0) {
                    $domain['status_color'] = 'red';
                } elseif ($daysLeft <= 30) {
                    $domain['status_color'] = 'orange';
                } elseif ($daysLeft <= 90) {
                    $domain['status_color'] = 'yellow';
                } else {
                    $domain['status_color'] = 'green';
                }
            } else {
                $domain['days_left'] = null;
                $domain['status_color'] = 'gray';
            }
        }

        // Check if query looks like a domain
        $isDomainLike = $this->isDomainFormat($query);

        echo json_encode([
            'domains' => $results,
            'isDomainLike' => $isDomainLike,
            'query' => $query
        ]);
        exit;
    }

    /**
     * Search domains in database
     */
    private function searchDomains(string $query, ?int $userId = null): array
    {
        $db = \Core\Database::getConnection();
        $sql = "SELECT d.*, ng.name as group_name 
                FROM domains d 
                LEFT JOIN notification_groups ng ON d.notification_group_id = ng.id 
                WHERE (d.domain_name LIKE ? 
                   OR d.registrar LIKE ?
                   OR ng.name LIKE ?)";
        
        $params = ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%'];
        
        if ($userId && !$this->isAdmin($userId)) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.domain_name ASC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Check if user is admin
     */
    private function isAdmin(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        
        $db = \Core\Database::getConnection();
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && $user['role'] === 'admin';
    }

    /**
     * Check if string looks like a domain name
     */
    private function isDomainFormat(string $query): bool
    {
        // Basic domain validation
        return preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i', $query);
    }
}

