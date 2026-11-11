<?php

namespace App\Controllers;

use Core\Auth;
use App\Helpers\CsvHelper;
use App\Helpers\InputValidator;
use App\Helpers\MoneyHelper;
use App\Models\BillingPrice;
use App\Services\Logger;

/**
 * Billing (#69): the price list, and a domain's own renewal price.
 * In shared mode everyone works on one list. In isolated mode each user has their own.
 */
class BillingPriceController extends BillingController
{
    /** Columns of the price list export, which the import reads back */
    public const PRICE_COLUMNS = ['tld', 'registrar', 'registration_price', 'renewal_price', 'transfer_price', 'currency'];

    private const PRICE_LABELS = ['registration_price' => 'registration', 'renewal_price' => 'renewal', 'transfer_price' => 'transfer'];

    private BillingPrice $prices;

    public function __construct()
    {
        parent::__construct();
        $this->prices = new BillingPrice();
    }

    /**
     * The price list
     */
    public function prices()
    {
        $this->requireBilling();
        $scope = $this->scope();
        $domains = $this->domainModel->getAllWithGroups($scope);

        // How many domains take their renewal price from each row
        $used = [];
        foreach ($this->billing->expectedRenewals($domains, $scope) as $price) {
            if (isset($price['price_id'])) {
                $used[$price['price_id']] = ($used[$price['price_id']] ?? 0) + 1;
            }
        }
        $rows = $this->prices->allVisible($scope);
        foreach ($rows as &$row) {
            $row['used'] = $used[(int)$row['id']] ?? 0;
        }
        unset($row);

        // Registrar names as the checks read them, to pick from
        $registrars = [];
        foreach ($domains as $domain) {
            $registrar = trim((string)($domain['registrar'] ?? ''));
            if (BillingPrice::registrarKey($registrar) !== '' && ($domain['status'] ?? '') !== 'available') {
                $registrars[$registrar] = true;
            }
        }
        ksort($registrars, SORT_FLAG_CASE | SORT_STRING);

        $this->view('billing/prices', [
            'title' => 'Price List',
            'prices' => $rows,
            'registrars' => array_keys($registrars),
            'base' => $this->billing->baseCurrency(),
            'currencies' => array_values($this->billing->currencies()),
        ]);
    }

    /**
     * Add a price row or change one. A second row for the same TLD and registrar updates the first.
     */
    public function savePrice()
    {
        $back = $this->startChange('/billing/prices');
        [$data, $error] = $this->readPrice($_POST);
        if ($error !== null) {
            $this->fail($error, $back);
        }

        $scope = $this->scope();
        $id = (int)($_POST['id'] ?? 0);
        $same = $this->prices->findSame($data['tld'], $data['registrar_key'], $scope);
        if ($id && !$this->prices->findVisible($id, $scope)) {
            $this->fail('Price not found', $back);
        }
        if ($id && $same && (int)$same['id'] !== $id) {
            $this->fail("There is already a price for {$data['tld']}" . ($data['registrar'] !== '' ? " at {$data['registrar']}" : ' for any registrar'), $back);
        }

        try {
            if ($id || $same) {
                $this->prices->update($id ?: (int)$same['id'], $data);
                $_SESSION['success'] = $id ? 'Price updated' : 'Price updated: this TLD and registrar were on the list already';
            } else {
                $this->prices->create($data + ['user_id' => Auth::id()]);
                $_SESSION['success'] = 'Price added';
            }
        } catch (\Throwable $e) {
            $this->logError('Price not saved', $e);
            $_SESSION['error'] = 'The price could not be saved';
        }
        $this->redirect($back);
    }

    /**
     * Delete the selected price rows
     */
    public function deletePrices()
    {
        $back = $this->startChange('/billing/prices');
        $ids = $_POST['price_ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            $this->fail('No prices selected', $back);
        }
        $sizeError = InputValidator::validateArraySize($ids, 1000, 'Price selection');
        if ($sizeError) {
            $this->fail($sizeError, $back);
        }

        $deleted = $this->prices->deleteVisible($ids, $this->scope());
        $_SESSION['success'] = "Deleted {$deleted} " . ($deleted === 1 ? 'price' : 'prices');
        $this->redirect($back);
    }

    /**
     * Download the price list as CSV
     */
    public function exportPrices()
    {
        $this->requireBilling();
        $rows = [];
        foreach ($this->prices->allVisible($this->scope()) as $row) {
            $rows[] = array_map(fn(string $column) => $row[$column], self::PRICE_COLUMNS);
        }
        $this->sendCsv(CsvHelper::build($rows, self::PRICE_COLUMNS), 'billing_prices_' . date('Y-m-d'));
    }

    /**
     * Read a price list from CSV. A row for a TLD and registrar that is on the list updates it.
     */
    public function importPrices()
    {
        $back = $this->startChange('/billing/prices');
        $scope = $this->scope();
        $added = $updated = 0;
        $skipped = [];

        foreach ($this->readCsvUpload($back) as $number => $row) {
            [$data, $error] = $this->readPrice($row);
            if ($error !== null) {
                $skipped[] = 'row ' . ($number + 2);
                continue;
            }
            try {
                $same = $this->prices->findSame($data['tld'], $data['registrar_key'], $scope);
                if ($same) {
                    $this->prices->update((int)$same['id'], $data);
                    $updated++;
                } else {
                    $this->prices->create($data + ['user_id' => Auth::id()]);
                    $added++;
                }
            } catch (\Throwable $e) {
                $this->logError('Price row not imported', $e);
                $skipped[] = 'row ' . ($number + 2);
            }
        }

        (new Logger('import'))->info('Price list import', ['added' => $added, 'updated' => $updated, 'skipped' => count($skipped), 'user_id' => Auth::id()]);
        $_SESSION['success'] = "{$added} added, {$updated} updated";
        if ($skipped) {
            $_SESSION['warning'] = count($skipped) . ' not imported, because a TLD, a price or the currency was not valid: '
                . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ' and more' : '');
        }
        $this->redirect($back);
    }

    /**
     * Save or remove a domain's own renewal price, which is used before the price list
     */
    public function saveDomainPrice($params = [])
    {
        $back = $this->startChange();
        $domain = $this->visibleDomain((int)($params['id'] ?? 0));
        if (!$domain) {
            $this->fail('Domain not found', $back);
        }
        [$price, $currency, $error] = $this->billing->readOwnPrice($_POST['renewal_price'] ?? '', $_POST['renewal_currency'] ?? '');
        if ($error !== null) {
            $this->fail($error, $back);
        }

        try {
            $this->domainModel->update((int)$domain['id'], ['renewal_price' => $price, 'renewal_currency' => $currency]);
            $_SESSION['success'] = $price === null ? 'This domain uses the price list again' : "This domain's own price was saved";
        } catch (\Throwable $e) {
            $this->logError('Own price not saved', $e);
            $_SESSION['error'] = 'The price could not be saved';
        }
        $this->redirect($back);
    }

    /**
     * Check one price row from the form or from a CSV file
     *
     * @return array [column values or null, error message or null]
     */
    private function readPrice(array $in): array
    {
        $text = fn(string $key): string => InputValidator::sanitizeText(is_string($in[$key] ?? null) ? $in[$key] : '');

        $tld = strtolower($text('tld'));
        if ($tld !== '' && $tld[0] !== '.') {
            $tld = '.' . $tld;
        }
        if (strlen($tld) > 63 || !preg_match('/^(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $tld)) {
            return [null, 'Enter a TLD such as .com or .co.uk'];
        }
        $registrar = $text('registrar');
        if (mb_strlen($registrar) > 255) {
            return [null, 'The registrar name is too long'];
        }
        $registrarKey = BillingPrice::registrarKey($registrar);
        $data = ['tld' => $tld, 'registrar' => $registrarKey === '' ? '' : $registrar, 'registrar_key' => $registrarKey];

        $any = false;
        foreach (self::PRICE_LABELS as $column => $label) {
            $value = $text($column);
            $data[$column] = null;
            if ($value !== '') {
                $data[$column] = MoneyHelper::parse($value);
                if ($data[$column] === null) {
                    return [null, "Enter the {$label} price as a plain number, such as 14.99"];
                }
                $any = true;
            }
        }
        if (!$any) {
            return [null, 'Enter at least one price'];
        }

        $currency = MoneyHelper::currency($in['currency'] ?? '');
        if ($currency === null) {
            return [null, 'The currency must be a three-letter code, such as USD'];
        }
        if ($this->billing->rateFor($currency) === null) {
            return [null, "{$currency} has no rate yet. An admin can add it in Settings, Billing."];
        }
        $data['currency'] = $currency;

        return [$data, null];
    }
}
