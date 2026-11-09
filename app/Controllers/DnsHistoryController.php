<?php

namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use App\Helpers\InputValidator;
use App\Models\DnsHistory;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Setting;
use App\Services\DnsHistoryService;
use App\Services\DnsService;
use App\Services\Logger;

/**
 * DNS history (#75): the saved records of every domain, deleted ones included, and the zone file
 * downloads of a history entry and of a domain's current records.
 */
class DnsHistoryController extends Controller
{
    private const PER_PAGE_CHOICES = [10, 25, 50, 100];

    private DnsHistory $historyModel;
    private DnsHistoryService $historyService;
    private Setting $settingModel;

    public function __construct()
    {
        $this->historyModel = new DnsHistory();
        $this->historyService = new DnsHistoryService();
        $this->settingModel = new Setting();
    }

    /**
     * List the saved entries
     * GET /dns-history
     */
    public function index()
    {
        $source = $this->query('source');
        $state = $this->query('state');
        $filters = [
            'search' => $this->query('search'),
            'state'  => in_array($state, ['existing', 'deleted'], true) ? $state : '',
            'source' => isset(DnsHistoryService::SOURCE_LABELS[$source]) ? $source : '',
        ];

        $perPage = (int)$this->query('per_page');
        if (!in_array($perPage, self::PER_PAGE_CHOICES, true)) {
            $perPage = 25;
        }
        $page = max(1, (int)$this->query('page'));

        // Nothing to read until the database update that adds the table has run
        $available = $this->historyService->isAvailable();
        $userId = $this->isolatedUserId();

        $total = $available ? $this->historyModel->countSearch($filters, $userId) : 0;
        $totalPages = (int)ceil($total / $perPage);
        $page = min($page, max(1, $totalPages));
        $offset = ($page - 1) * $perPage;

        $this->view('dns-history/index', [
            'title'            => 'DNS History',
            'entries'          => $available ? $this->historyModel->search($filters, $userId, $perPage, $offset) : [],
            'filters'          => $filters,
            'sourceLabels'     => DnsHistoryService::SOURCE_LABELS,
            'historyAvailable' => $available,
            'historyOn'        => $available && $this->settingModel->isDnsHistoryOn(),
            'retentionDays'    => $this->settingModel->getDnsHistoryRetentionDays(),
            'pagination'       => [
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'per_page'     => $perPage,
                'total'        => $total,
                'showing_from' => $total > 0 ? $offset + 1 : 0,
                'showing_to'   => min($offset + $perPage, $total),
            ],
        ]);
    }

    /**
     * The records of one entry as a zone file
     * GET /dns-history/{id}/export
     */
    public function export($params = [])
    {
        // A deleted domain has no row to check access against: the entry itself says whose it is
        $entry = $this->historyService->isAvailable()
            ? $this->historyModel->findVisible((int)($params['id'] ?? 0), $this->isolatedUserId())
            : null;

        if (!$entry) {
            $_SESSION['error'] = 'DNS history entry not found';
            $this->redirect('/dns-history');
            return;
        }

        $savedAt = strtotime($entry['created_at']);
        $when = date('Y-m-d H:i', $savedAt);
        $note = $entry['source'] === 'deleted'
            ? "The records the domain had when it was deleted on {$when}"
            : "The records from before the change found on {$when} ("
                . (DnsHistoryService::SOURCE_LABELS[$entry['source']] ?? $entry['source']) . ')';

        $this->sendZone(
            $entry['domain_name'],
            (new DnsService())->buildBindZone($entry['domain_name'], $entry['records'], [$note]),
            date('Y-m-d_Hi', $savedAt)
        );
    }

    /**
     * A domain's current records as a zone file
     * GET /domains/{id}/dns-export
     */
    public function exportCurrent($params = [])
    {
        $id = (int)($params['id'] ?? 0);
        $userId = $this->isolatedUserId();
        $domainModel = new Domain();
        $domain = $userId !== null ? $domainModel->findWithIsolation($id, $userId) : $domainModel->find($id);

        if (!$domain) {
            $_SESSION['error'] = 'Domain not found';
            $this->redirect('/domains');
            return;
        }

        $records = (new DnsRecord())->getPreviousSnapshot($id);
        if (empty($records)) {
            $_SESSION['warning'] = 'This domain has no DNS records to export';
            $this->redirect('/domains/' . $id . '#dns');
            return;
        }

        $note = 'The current records' . (!empty($domain['dns_last_checked'])
            ? ', last checked on ' . date('Y-m-d H:i', strtotime($domain['dns_last_checked']))
            : '');

        $this->sendZone(
            $domain['domain_name'],
            (new DnsService())->buildBindZone($domain['domain_name'], $records, [$note]),
            date('Y-m-d')
        );
    }

    /**
     * Delete the selected entries
     * POST /dns-history/delete
     */
    public function delete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/dns-history');
            return;
        }

        $this->verifyCsrf('/dns-history');
        $returnTo = $this->returnPath();

        $ids = $_POST['history_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $_SESSION['error'] = 'No entries selected';
            $this->redirect($returnTo);
            return;
        }

        $sizeError = InputValidator::validateArraySize($ids, 1000, 'Entry selection');
        if ($sizeError) {
            $_SESSION['error'] = $sizeError;
            $this->redirect($returnTo);
            return;
        }

        $deleted = $this->historyService->isAvailable()
            ? $this->historyModel->deleteVisible($ids, $this->isolatedUserId())
            : 0;

        (new Logger('dns'))->info('DNS history entries deleted', [
            'count'   => $deleted,
            'user_id' => Auth::id(),
        ]);

        $_SESSION['success'] = "Deleted {$deleted} DNS history " . ($deleted === 1 ? 'entry' : 'entries');
        $this->redirect($returnTo);
    }

    /**
     * Whose entries the current user sees: only their own in isolated mode, everyone's (null) otherwise
     */
    private function isolatedUserId(): ?int
    {
        return $this->settingModel->getValue('user_isolation_mode', 'shared') === 'isolated' ? Auth::id() : null;
    }

    /**
     * A query string value as text ('' when it is missing or an array)
     */
    private function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Where to go back to after a delete: the list as it was filtered, nothing else
     */
    private function returnPath(): string
    {
        $returnTo = $_POST['return_to'] ?? '';

        return is_string($returnTo) && preg_match('#^/dns-history(\?[A-Za-z0-9_=&%.+~-]*)?$#', $returnTo)
            ? $returnTo
            : '/dns-history';
    }

    /**
     * Send a zone file: as a download, or with ?view=1 as plain text in the browser. The records
     * come from DNS, so the type is fixed and the browser is told not to guess another one.
     */
    private function sendZone(string $domainName, string $zone, string $stamp): void
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $domainName) . "-dns-{$stamp}.txt";

        // Clean any prior output buffers to prevent header conflicts
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . "; filename=\"{$filename}\"");
        header('Content-Length: ' . strlen($zone));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $zone;
        exit;
    }
}
