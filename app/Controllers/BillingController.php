<?php

namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use App\Helpers\CsvHelper;
use App\Helpers\InputValidator;
use App\Helpers\MoneyHelper;
use App\Models\BillingTransaction;
use App\Models\Domain;
use App\Services\BillingService;
use App\Services\Logger;

/**
 * Billing (#69): the payments of the domains. Everyone who can see a domain can see and
 * change its payments. In isolated mode that is the owner of the payment only.
 */
class BillingController extends Controller
{
    /** Columns of the payment export, which the import reads back */
    public const EXPORT_COLUMNS = [
        'date', 'domain', 'type', 'amount', 'currency', 'base_amount', 'base_currency', 'vendor', 'invoice_number',
        'invoice_url', 'payment_method', 'status', 'years', 'expires_on', 'notes', 'to_confirm',
    ];

    private const PER_PAGE_CHOICES = [10, 25, 50, 100];

    protected BillingService $billing;
    protected BillingTransaction $transactions;
    protected Domain $domainModel;

    public function __construct()
    {
        $this->billing = new BillingService();
        $this->transactions = new BillingTransaction();
        $this->domainModel = new Domain();
    }

    /**
     * The Billing page: what the portfolio costs, what is coming up, and every payment
     */
    public function index()
    {
        $this->requireBilling();
        $scope = $this->scope();
        $filters = $this->readFilters();

        $perPage = (int)($_GET['per_page'] ?? 25);
        if (!in_array($perPage, self::PER_PAGE_CHOICES, true)) {
            $perPage = 25;
        }
        $total = $this->transactions->countSearch($filters, $scope);
        $totalPages = (int)ceil($total / $perPage);
        $page = min(max(1, (int)($_GET['page'] ?? 1)), max(1, $totalPages));
        $offset = ($page - 1) * $perPage;

        $domains = $this->domainModel->getAllWithGroups($scope);
        $portfolio = $this->billing->portfolio($domains, $scope);
        $base = $this->billing->baseCurrency();
        $currencies = $this->billing->currencies();
        $number = fn(string $amount): float => (float)$amount;

        $this->view('billing/index', [
            'title' => 'Billing',
            'portfolio' => $portfolio,
            'payments' => $this->transactions->search($filters, $scope, $perPage, $offset),
            'filters' => $filters,
            'typeLabels' => BillingTransaction::TYPE_LABELS,
            'base' => $base,
            'currencies' => array_values($currencies),
            'domainNames' => array_column($domains, 'domain_name'),
            // For the two charts. Hex-escaped, so it is safe inside a script tag.
            'chartJson' => json_encode([
                'currency' => ['code' => $base, 'symbol' => (string)($currencies[$base]['symbol'] ?? '')],
                'months' => [
                    'labels' => array_column($portfolio['months'], 'label'),
                    'totals' => array_map($number, array_column($portfolio['months'], 'total')),
                    'counts' => array_column($portfolio['months'], 'count'),
                ],
                'years' => [
                    'labels' => array_column($portfolio['years'], 'year'),
                    'purchase' => array_map($number, array_column($portfolio['years'], 'purchase')),
                    'renewal' => array_map($number, array_column($portfolio['years'], 'renewal')),
                    'transfer' => array_map($number, array_column($portfolio['years'], 'transfer')),
                    'other' => array_map($number, array_column($portfolio['years'], 'other')),
                    'income' => array_map($number, array_column($portfolio['years'], 'income')),
                ],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total' => $total,
                'showing_from' => $total > 0 ? $offset + 1 : 0,
                'showing_to' => min($offset + $perPage, $total),
            ],
        ]);
    }

    /**
     * Read payments from a CSV file, as the export writes it. A payment that is saved already is skipped,
     * so the same file can be imported twice.
     */
    public function import()
    {
        $back = $this->startChange();
        $scope = $this->scope();
        $base = $this->billing->baseCurrency();
        $added = $known = 0;
        $invalid = [];
        $saved = [];
        $seen = [];
        $field = fn(array $row, string $key): string => is_string($row[$key] ?? null) ? $row[$key] : '';

        foreach ($this->readCsvUpload($back, 5 * 1048576) as $number => $row) {
            // A value in another base currency means nothing here: the rate decides then
            $sameBase = in_array(strtoupper(trim($field($row, 'base_currency'))), ['', $base], true);
            [$data, $rate, $error] = $this->checkPayment([
                'type' => $field($row, 'type'), 'amount' => $field($row, 'amount'), 'currency' => $field($row, 'currency'),
                'transaction_date' => $field($row, 'date'), 'status' => $field($row, 'status'), 'term_years' => $field($row, 'years'),
                'amount_base' => $sameBase ? $field($row, 'base_amount') : '',
                'vendor' => $field($row, 'vendor'), 'invoice_number' => $field($row, 'invoice_number'), 'invoice_url' => $field($row, 'invoice_url'),
                'payment_method' => $field($row, 'payment_method'), 'notes' => $field($row, 'notes'),
            ]);
            $name = strtolower(InputValidator::sanitizeText($field($row, 'domain')));
            if ($error !== null || strlen($name) > 255 || preg_match('/\s/', $name)) {
                $invalid[] = 'row ' . ($number + 2);
                continue;
            }

            $expires = trim($field($row, 'expires_on'));
            $domain = $name !== '' ? $this->visibleDomainByName($name) : null;
            $data += [
                'expires_on' => self::validDate($expires) ? $expires : null,
                // An automatic payment that was not confirmed yet keeps waiting
                'needs_review' => strtolower(trim($field($row, 'to_confirm'))) === 'yes' ? 1 : 0,
                'domain_id' => $domain['id'] ?? null,
                'domain_name' => $name,
                'user_id' => $domain['user_id'] ?? Auth::id(),
                'source' => 'import',
            ];
            try {
                // A payment that is saved already is skipped. Two identical rows in the file are two
                // payments: the second is only skipped when two were saved before.
                $key = json_encode([$data['domain_name'], $data['transaction_date'], $data['type'], $data['amount'], $data['currency'], $data['invoice_number'], $data['vendor']]);
                $saved[$key] ??= $this->transactions->countSame($data, $scope);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                if ($seen[$key] <= $saved[$key]) {
                    $known++;
                    continue;
                }
                $this->transactions->add($data, $rate);
                $added++;
            } catch (\Throwable $e) {
                $this->logError('Payment row not imported', $e);
                $invalid[] = 'row ' . ($number + 2);
            }
        }

        (new Logger('import'))->info('Billing import', ['added' => $added, 'known' => $known, 'invalid' => count($invalid), 'user_id' => Auth::id()]);
        $_SESSION['success'] = "{$added} " . ($added === 1 ? 'payment' : 'payments') . ' imported' . ($known ? ", {$known} already there" : '');
        if ($invalid) {
            $_SESSION['warning'] = count($invalid) . ' not imported, because a type, amount, currency, date or link was not valid: '
                . implode(', ', array_slice($invalid, 0, 10)) . (count($invalid) > 10 ? ' and more' : '');
        }
        $this->redirect($back);
    }

    /**
     * Save a new payment, from a domain's Billing tab or from the Billing page
     */
    public function store()
    {
        $back = $this->startChange();

        $domain = null;
        $domainName = '';
        if (!empty($_POST['domain_id'])) {
            $domain = $this->visibleDomain((int)$_POST['domain_id']);
            if (!$domain) {
                $this->fail('Domain not found', $back);
            }
            $domainName = $domain['domain_name'];
        } else {
            // From the Billing page: any name, or none for a cost that belongs to no domain
            $domainName = strtolower(InputValidator::sanitizeText(is_string($_POST['domain_name'] ?? null) ? $_POST['domain_name'] : ''));
            if (strlen($domainName) > 255 || preg_match('/\s/', $domainName)) {
                $this->fail('The domain name is not valid', $back);
            }
            $domain = $domainName !== '' ? $this->visibleDomainByName($domainName) : null;
        }

        [$data, $rate] = $this->readPayment($back);
        $data += [
            'domain_id' => $domain['id'] ?? null,
            'domain_name' => $domainName,
            // The payment belongs to the owner of its domain
            'user_id' => $domain['user_id'] ?? Auth::id(),
            'source' => 'manual',
        ];

        try {
            $this->transactions->add($data, $rate);
            $_SESSION['success'] = 'Payment saved';
        } catch (\Throwable $e) {
            $this->logError('Payment not saved', $e);
            $_SESSION['error'] = 'The payment could not be saved';
        }
        $this->redirect($back);
    }

    /**
     * One payment for each domain selected in the Domains list, for an invoice that covers several
     * domains. A renewal without an amount takes the expected price of each domain.
     */
    public function bulkStore()
    {
        $back = $this->startChange('/domains');
        $ids = $_POST['domain_ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            $this->fail('No domains selected', $back);
        }
        $sizeError = InputValidator::validateArraySize($ids, 1000, 'Domain selection');
        if ($sizeError) {
            $this->fail($sizeError, $back);
        }

        $useExpected = is_string($_POST['amount'] ?? '') && trim($_POST['amount'] ?? '') === '';
        if ($useExpected && ($_POST['type'] ?? '') !== 'renewal') {
            $this->fail('Enter the amount each domain cost', $back);
        }
        // With expected prices, amount and currency come from each domain: the rest is checked with stand-ins
        $base = $this->billing->baseCurrency();
        [$data, $rate, $error] = $this->checkPayment(($useExpected ? ['amount' => '0', 'currency' => $base] : []) + $_POST);
        if ($error !== null) {
            $this->fail($error, $back);
        }

        $domains = [];
        foreach ($ids as $id) {
            $domain = $this->visibleDomain((int)$id);
            if ($domain) {
                $domains[(int)$domain['id']] = $domain;
            }
        }
        if (!$domains) {
            $this->fail('No domains selected', $back);
        }
        $prices = [];
        $amounts = [];
        if ($useExpected) {
            // Yearly price times years, worked out by the database
            $prices = $this->billing->expectedRenewals(array_values($domains), $this->scope());
            $years = (string)(($data['term_years'] ?? 1) ?: 1);
            $amounts = (new \App\Models\BillingCurrency())->convertMany(array_map(fn(array $price): array => [$price['price'], $years], $prices));
        }

        $added = 0;
        $skipped = [];
        foreach ($domains as $id => $domain) {
            $row = $data;
            $rowRate = $rate;
            if ($useExpected) {
                $price = $prices[$id] ?? null;
                $rowRate = $price ? $this->billing->rateFor($price['currency']) : null;
                if (!$price || $rowRate === null || !isset($amounts[$id])) {
                    $skipped[] = $domain['domain_name'];
                    continue;
                }
                $row['amount'] = (string)$amounts[$id];
                $row['currency'] = $price['currency'];
                $row['amount_base'] = $row['amount'];
                if ($price['currency'] === $base) {
                    $rowRate = null;
                }
            }
            try {
                $this->transactions->add($row + [
                    'domain_id' => $id,
                    'domain_name' => $domain['domain_name'],
                    'user_id' => $domain['user_id'] ?? Auth::id(),
                    'source' => 'manual',
                ], $rowRate);
                $added++;
            } catch (\Throwable $e) {
                $this->logError('Bulk payment not saved', $e);
                $skipped[] = $domain['domain_name'];
            }
        }

        $_SESSION['success'] = "Payment saved for {$added} " . ($added === 1 ? 'domain' : 'domains');
        if ($skipped) {
            $_SESSION['warning'] = count($skipped) . ' skipped, because no price is known: '
                . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ' and more' : '');
        }
        $this->redirect($back);
    }

    /**
     * Change a payment. A changed automatic payment counts as confirmed.
     */
    public function update($params = [])
    {
        $back = $this->startChange();
        $id = (int)($params['id'] ?? 0);
        $scope = $this->scope();
        if (!$this->transactions->findVisible($id, $scope)) {
            $this->fail('Payment not found', $back);
        }

        [$data, $rate] = $this->readPayment($back);
        $data['needs_review'] = 0;

        try {
            $this->transactions->updateVisible($id, $data, $scope, $rate);
            $_SESSION['success'] = 'Payment updated';
        } catch (\Throwable $e) {
            $this->logError('Payment not updated', $e);
            $_SESSION['error'] = 'The payment could not be saved';
        }
        $this->redirect($back);
    }

    /**
     * Delete the selected payments
     */
    public function delete()
    {
        $back = $this->startChange();
        $deleted = $this->transactions->deleteVisible($this->selectedIds($back), $this->scope());
        (new Logger('billing'))->info('Payments deleted', ['count' => $deleted, 'user_id' => Auth::id()]);
        $_SESSION['success'] = "Deleted {$deleted} " . ($deleted === 1 ? 'payment' : 'payments');
        $this->redirect($back);
    }

    /**
     * Confirm the selected automatic payments
     */
    public function confirm()
    {
        $back = $this->startChange();
        $ids = $this->selectedIds($back);
        $confirmed = $this->transactions->confirmVisible($ids, $this->scope());
        $_SESSION['success'] = "Confirmed {$confirmed} " . ($confirmed === 1 ? 'payment' : 'payments');
        if ($confirmed < count($ids)) {
            $_SESSION['warning'] = 'Only automatic payments that have an amount can be confirmed. Edit one without an amount to enter it.';
        }
        $this->redirect($back);
    }

    /**
     * Download payments as CSV: all of them, the filtered list, or those of one domain
     */
    public function export()
    {
        $this->requireBilling();
        $logger = new Logger('export');

        try {
            $filters = $this->readFilters();
            $name = 'billing_export_' . date('Y-m-d');
            if (!empty($_GET['domain_id'])) {
                $domain = $this->visibleDomain((int)$_GET['domain_id']);
                if (!$domain) {
                    $this->fail('Domain not found', '/billing');
                }
                $filters['domain_id'] = (int)$domain['id'];
                $name = 'billing_' . preg_replace('/[^a-z0-9.-]+/i', '_', $domain['domain_name']) . '_' . date('Y-m-d');
            }

            $base = $this->billing->baseCurrency();
            $rows = [];
            foreach ($this->transactions->search($filters, $this->scope(), 100000, 0) as $row) {
                $rows[] = [
                    $row['transaction_date'], $row['domain_name'], $row['type'], $row['amount'], $row['currency'],
                    $row['amount_base'], $base, $row['vendor'], $row['invoice_number'], $row['invoice_url'],
                    $row['payment_method'], $row['status'], $row['term_years'], $row['expires_on'], $row['notes'],
                    $row['needs_review'] ? 'yes' : '',
                ];
            }
            $csv = CsvHelper::build($rows, self::EXPORT_COLUMNS);
            $logger->info('Billing export', ['rows' => count($rows), 'user_id' => Auth::id()]);

            $this->sendCsv($csv, $name);
        } catch (\Throwable $e) {
            $logger->error('Billing export failed', ['error' => $e->getMessage()]);
            $_SESSION['error'] = 'Export failed';
            $this->redirect('/billing');
        }
    }

    // ---------------------------------------------------------------------
    // Shared by the actions
    // ---------------------------------------------------------------------

    /**
     * Start of every action that changes something: a POST with a valid token while billing is on.
     * Returns where to go back to.
     */
    protected function startChange(string $default = '/billing'): string
    {
        $back = $this->returnPath($default);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect($back);
        }
        $this->verifyCsrf($back);
        $this->requireBilling();

        return $back;
    }

    protected function requireBilling(): void
    {
        if (!$this->billing->isOn()) {
            $_SESSION['error'] = 'Billing is turned off';
            $this->redirect('/');
        }
    }

    /**
     * Set the error message and go back. Does not return.
     */
    protected function fail(string $message, string $back): void
    {
        $_SESSION['error'] = $message;
        $this->redirect($back);
    }

    protected function logError(string $what, \Throwable $e): void
    {
        (new Logger('billing'))->error($what, ['error' => $e->getMessage(), 'user_id' => Auth::id()]);
    }

    /**
     * Whose rows the signed-in user works with: null when everyone shares everything
     */
    protected function scope(): ?int
    {
        return $this->billing->scopeFor(Auth::id());
    }

    protected function visibleDomain(int $id): ?array
    {
        $scope = $this->scope();

        return $scope !== null ? $this->domainModel->findWithIsolation($id, $scope) : $this->domainModel->find($id);
    }

    protected function visibleDomainByName(string $name): ?array
    {
        $domain = $this->domainModel->where('domain_name', $name)[0] ?? null;
        $scope = $this->scope();
        if ($domain && $scope !== null && (int)($domain['user_id'] ?? 0) !== $scope) {
            return null;
        }

        return $domain;
    }

    /**
     * Where to go back to: the Billing page as it was filtered, the price list, or a domain's Billing tab
     */
    protected function returnPath(string $default = '/billing'): string
    {
        $returnTo = $_POST['return_to'] ?? '';
        if (!is_string($returnTo) || !preg_match('#^/(billing(/prices)?(\?[A-Za-z0-9_=&%.+~-]*)?|domains/\d+)$#', $returnTo)) {
            return $default;
        }

        return str_starts_with($returnTo, '/domains/') ? $returnTo . '#billing' : $returnTo;
    }

    /**
     * Send a CSV file as a download. Does not return.
     */
    protected function sendCsv(string $csv, string $name): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$name}.csv\"");
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $csv;
        exit;
    }

    /**
     * The rows of an uploaded CSV file. Goes back with a message when there is no usable file.
     */
    protected function readCsvUpload(string $back, int $maxBytes = 1048576): array
    {
        $file = $_FILES['import_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('Please select a CSV file to import', $back);
        }
        if ((int)$file['size'] > $maxBytes) {
            $this->fail('File is too large. Maximum size is ' . (int)($maxBytes / 1048576) . 'MB', $back);
        }
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $this->fail('Invalid file type. Please upload a CSV file', $back);
        }
        $rows = CsvHelper::parse((string)file_get_contents($file['tmp_name']));
        if (!$rows) {
            $this->fail('The file has no rows', $back);
        }

        return $rows;
    }

    /**
     * The ids ticked in a list
     */
    private function selectedIds(string $back): array
    {
        $ids = $_POST['payment_ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            $this->fail('No payments selected', $back);
        }
        $sizeError = InputValidator::validateArraySize($ids, 1000, 'Payment selection');
        if ($sizeError) {
            $this->fail($sizeError, $back);
        }

        return $ids;
    }

    /**
     * The list filters from the query string, with anything unknown dropped
     */
    protected function readFilters(): array
    {
        $text = fn(string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
        $date = fn(string $key): string => self::validDate($text($key)) ? $text($key) : '';

        return [
            'search' => mb_substr($text('search'), 0, 255),
            'type' => isset(BillingTransaction::TYPE_LABELS[$text('type')]) ? $text('type') : '',
            'status' => in_array($text('status'), ['paid', 'pending', 'review'], true) ? $text('status') : '',
            'state' => in_array($text('state'), ['existing', 'deleted', 'none'], true) ? $text('state') : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    private static function validDate(string $value): bool
    {
        return InputValidator::validateDate($value) && $value >= '1985-01-01' && $value <= '2100-12-31';
    }

    /**
     * Read and check the payment fields of the form. Goes back with a message when one is wrong.
     *
     * @return array [column values, rate or null]. With a rate, the database converts the amount.
     */
    private function readPayment(string $back): array
    {
        [$data, $rate, $error] = $this->checkPayment($_POST);
        if ($error !== null) {
            $this->fail($error, $back);
        }

        return [$data, $rate];
    }

    /**
     * Check the fields of one payment, from the form or from a row of an import file
     *
     * @return array [column values or null, rate or null, error message or null]
     */
    protected function checkPayment(array $in): array
    {
        $text = fn(string $key): string => InputValidator::sanitizeText(is_string($in[$key] ?? null) ? $in[$key] : '');
        $bad = fn(string $message): array => [null, null, $message];

        $type = $text('type');
        if (!isset(BillingTransaction::TYPE_LABELS[$type])) {
            return $bad('Choose what kind of payment it is');
        }
        $amount = MoneyHelper::parse($in['amount'] ?? '');
        if ($amount === null) {
            return $bad('Enter the amount as a plain number, such as 1200.50');
        }
        $currency = MoneyHelper::currency($in['currency'] ?? '');
        if ($currency === null) {
            return $bad('The currency must be a three-letter code, such as USD');
        }
        $date = $text('transaction_date');
        if (!self::validDate($date)) {
            return $bad('Enter the date of the payment');
        }
        $status = $text('status') ?: 'paid';
        if (!isset(BillingTransaction::STATUS_LABELS[$status])) {
            return $bad('Unknown payment status');
        }

        $limits = ['vendor' => 255, 'invoice_number' => 100, 'invoice_url' => 500, 'payment_method' => 100, 'notes' => 2000];
        $labels = ['vendor' => 'Company', 'invoice_number' => 'Invoice number', 'invoice_url' => 'Invoice link', 'payment_method' => 'Payment method', 'notes' => 'Notes'];
        $data = ['type' => $type, 'amount' => $amount, 'currency' => $currency, 'transaction_date' => $date, 'status' => $status];
        foreach ($limits as $field => $max) {
            $value = $text($field);
            if (mb_strlen($value) > $max) {
                return $bad("{$labels[$field]} is too long (maximum {$max} characters)");
            }
            $data[$field] = $value !== '' ? $value : null;
        }
        if ($data['invoice_url'] !== null && (!preg_match('#^https?://#i', $data['invoice_url']) || !InputValidator::validateUrl($data['invoice_url']))) {
            return $bad('The invoice link must start with http:// or https://');
        }

        // Years only mean something for a renewal or a transfer
        $years = $text('term_years');
        $data['term_years'] = null;
        if ($years !== '' && in_array($type, ['renewal', 'transfer'], true)) {
            if (!ctype_digit($years) || (int)$years < 1 || (int)$years > 100) {
                return $bad('Years must be a number from 1 to 100');
            }
            $data['term_years'] = (int)$years;
        }

        // Value in the base currency: the same amount, what was typed, or converted with the saved rate
        $base = $this->billing->baseCurrency();
        if ($currency === $base) {
            return [$data + ['amount_base' => $amount], null, null];
        }
        $typedBase = $text('amount_base');
        if ($typedBase !== '') {
            $amountBase = MoneyHelper::parse($typedBase);
            if ($amountBase === null) {
                return $bad("Enter the value in {$base} as a plain number, such as 1200.50");
            }
            return [$data + ['amount_base' => $amountBase], null, null];
        }
        $rate = $this->billing->rateFor($currency);
        if ($rate === null) {
            return $bad("Enter the value in {$base}: no rate is saved for {$currency}");
        }

        return [$data, $rate, null];
    }
}
