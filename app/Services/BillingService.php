<?php

namespace App\Services;

use App\Helpers\DomainHelper;
use App\Helpers\MoneyHelper;
use App\Models\BillingCurrency;
use App\Models\BillingPrice;
use App\Models\BillingTransaction;
use App\Models\NotificationGroup;
use App\Models\Setting;

/**
 * Billing Service
 *
 * What the domains cost: payments, the price list and what the next renewals will cost.
 * The methods called from a check, a refresh or a delete never throw: a billing failure is
 * logged and must not stop them.
 */
class BillingService
{
    private BillingTransaction $transactions;
    private BillingPrice $prices;
    private BillingCurrency $currencyModel;
    private Setting $settings;
    private Logger $logger;

    private ?bool $available = null;
    private ?bool $on = null;
    private ?string $base = null;
    private ?array $currencies = null;
    private ?array $watchGroups = null;

    /** Base currency and symbols for the money filter, read once per request */
    private static ?array $formatCache = null;

    public function __construct()
    {
        $this->transactions = new BillingTransaction();
        $this->prices = new BillingPrice();
        $this->currencyModel = new BillingCurrency();
        $this->settings = new Setting();
        $this->logger = new Logger('billing');
    }

    /**
     * Whether the billing tables exist. They do not between a file update and its migration.
     */
    public function isAvailable(): bool
    {
        if ($this->available === null) {
            try {
                $this->available = $this->transactions->isInstalled();
            } catch (\Throwable $e) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    /**
     * Whether billing is installed and switched on
     */
    public function isOn(): bool
    {
        if ($this->on === null) {
            try {
                $this->on = $this->isAvailable() && $this->settings->isBillingOn();
            } catch (\Throwable $e) {
                $this->on = false;
            }
        }

        return $this->on;
    }

    /**
     * The user whose rows count, or null when everyone shares everything
     */
    public function scopeFor(?int $userId): ?int
    {
        if ($this->settings->getValue('user_isolation_mode', 'shared') !== 'isolated') {
            return null;
        }

        // In isolated mode a row without an owner belongs to nobody
        return $userId ?? 0;
    }

    public function baseCurrency(): string
    {
        return $this->base ??= $this->settings->getBillingCurrency();
    }

    /**
     * Every currency by code. The base currency is always there, with rate 1.
     */
    public function currencies(): array
    {
        if ($this->currencies === null) {
            $base = $this->baseCurrency();
            $currencies = $this->currencyModel->allByCode();
            $currencies[$base] = ['code' => $base, 'symbol' => $currencies[$base]['symbol'] ?? null, 'rate' => '1.00000000'];
            ksort($currencies);
            $this->currencies = $currencies;
        }

        return $this->currencies;
    }

    /**
     * Value of one unit of a currency in the base currency, or null when no rate is saved
     */
    public function rateFor(string $currency): ?string
    {
        return $this->currencies()[$currency]['rate'] ?? null;
    }

    /**
     * Amount with its currency for display. Without a code it is the base currency.
     */
    public static function formatMoney($amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '-';
        }
        if (self::$formatCache === null) {
            try {
                $service = new self();
                self::$formatCache = [
                    'base' => $service->baseCurrency(),
                    'symbols' => array_column($service->currencies(), 'symbol', 'code'),
                ];
            } catch (\Throwable $e) {
                self::$formatCache = ['base' => 'USD', 'symbols' => []];
            }
        }
        $currency = $currency ?: self::$formatCache['base'];

        return MoneyHelper::format($amount, $currency, self::$formatCache['symbols'][$currency] ?? null);
    }

    // ---------------------------------------------------------------------
    // Expected prices
    // ---------------------------------------------------------------------

    /**
     * Expected yearly renewal price of each domain: its own price, then the price list, then the
     * yearly amount of its last confirmed renewal
     *
     * @param array $domains Rows with id, domain_name, registrar, user_id, renewal_price and renewal_currency
     * @param int|null $scope From scopeFor(): whose price list and payments apply
     * @return array Domain id => ['price', 'currency', 'base' (null without a rate), 'source' (own, list, last)]
     */
    public function expectedRenewals(array $domains, ?int $scope): array
    {
        $index = $this->priceIndex($scope);
        $last = $this->transactions->lastRenewals($scope);
        $base = $this->baseCurrency();

        $expected = [];
        foreach ($domains as $domain) {
            $id = (int)$domain['id'];
            $ownPrice = $domain['renewal_price'] ?? null;
            if ($ownPrice !== null && $ownPrice !== '') {
                $expected[$id] = ['price' => (string)$ownPrice, 'currency' => ($domain['renewal_currency'] ?? '') ?: $base, 'source' => 'own'];
            } elseif ($row = $this->matchPrice($index, $domain, 'renewal_price')) {
                $expected[$id] = ['price' => (string)$row['renewal_price'], 'currency' => $row['currency'], 'source' => 'list', 'price_id' => (int)$row['id']];
            } elseif (isset($last[$id])) {
                $expected[$id] = ['price' => $last[$id]['amount'], 'currency' => $last[$id]['currency'], 'source' => 'last'];
            }
        }

        return $this->withBaseValues($expected);
    }

    /**
     * One price of the list for a domain: registration_price, renewal_price or transfer_price
     *
     * @return array|null ['price', 'currency', 'base']
     */
    public function listPrice(array $domain, string $column, ?int $scope): ?array
    {
        $row = $this->matchPrice($this->priceIndex($scope), $domain, $column);
        if (!$row) {
            return null;
        }

        return $this->withBaseValues([0 => ['price' => (string)$row[$column], 'currency' => $row['currency'], 'source' => 'list']])[0];
    }

    /**
     * A domain's own yearly price as typed into a form. An empty price removes it.
     *
     * @return array [price or null, currency or null, error message or null]
     */
    public function readOwnPrice($price, $currency): array
    {
        $price = is_string($price) ? trim($price) : '';
        if ($price === '') {
            return [null, null, null];
        }
        $amount = MoneyHelper::parse($price);
        if ($amount === null) {
            return [null, null, 'Enter the renewal price as a plain number, such as 14.99'];
        }
        $code = MoneyHelper::currency(is_string($currency) && trim($currency) !== '' ? $currency : $this->baseCurrency());
        if ($code === null) {
            return [null, null, 'The currency must be a three-letter code, such as USD'];
        }
        if ($this->rateFor($code) === null) {
            return [null, null, "{$code} has no rate yet. An admin can add it in Settings, Billing."];
        }

        return [$amount, $code, null];
    }

    /**
     * The price list as tld => registrar key => rows, oldest row first
     */
    private function priceIndex(?int $scope): array
    {
        $index = [];
        foreach ($this->prices->allVisible($scope) as $row) {
            $index[$row['tld']][$row['registrar_key']][] = $row;
        }

        return $index;
    }

    /**
     * The row for a domain: the longest suffix that has a price, first for the domain's registrar,
     * then for any registrar. Among several rows the domain owner's own row wins.
     */
    private function matchPrice(array $index, array $domain, string $column): ?array
    {
        $registrarKey = BillingPrice::registrarKey($domain['registrar'] ?? '');
        foreach (DomainHelper::suffixes((string)($domain['domain_name'] ?? '')) as $suffix) {
            foreach (array_unique([$registrarKey, '']) as $key) {
                $rows = array_filter($index[$suffix][$key] ?? [], fn(array $row): bool => $row[$column] !== null);
                if (!$rows) {
                    continue;
                }
                foreach ($rows as $row) {
                    if ($row['user_id'] !== null && (string)$row['user_id'] === (string)($domain['user_id'] ?? '')) {
                        return $row;
                    }
                }

                return reset($rows);
            }
        }

        return null;
    }

    /**
     * Add each price's value in the base currency, converted by the database
     */
    private function withBaseValues(array $prices): array
    {
        $base = $this->baseCurrency();
        $pairs = [];
        foreach ($prices as $key => $price) {
            $rate = $price['currency'] === $base ? null : $this->rateFor($price['currency']);
            $prices[$key]['base'] = $price['currency'] === $base ? $price['price'] : null;
            if ($rate !== null) {
                $pairs[$key] = [$price['price'], $rate];
            }
        }
        foreach ($this->currencyModel->convertMany($pairs) as $key => $value) {
            $prices[$key]['base'] = (string)$value;
        }

        return $prices;
    }

    // ---------------------------------------------------------------------
    // One domain
    // ---------------------------------------------------------------------

    /**
     * Everything the Billing tab and the Overview card show for a domain
     */
    public function domainSummary(array $domain, ?int $scope): array
    {
        $id = (int)$domain['id'];
        $rows = $this->transactions->getForDomain($id, $scope);
        $sums = $this->transactions->sumsByType(['domain_id' => $id], $scope);
        $total = fn(string $type): string => $sums[$type]['total'] ?? '0.00';
        $invested = MoneyHelper::sum(array_map($total, BillingTransaction::EXPENSE_TYPES));
        $income = MoneyHelper::sum(array_map($total, BillingTransaction::INCOME_TYPES));

        $purchase = null;
        $sale = null;
        $pending = [];
        $review = 0;
        foreach ($rows as $row) {
            // Rows come newest first: the purchase shown is the earliest, the sale the latest
            if ($row['type'] === 'purchase') {
                $purchase = $row;
            }
            if ($row['type'] === 'sale' && $sale === null) {
                $sale = $row;
            }
            if ($row['status'] === 'pending') {
                $pending[] = $row['amount_base'];
            }
            $review += (int)$row['needs_review'];
        }

        // What was spent up to the end of each year
        $timeline = [];
        $running = '0.00';
        $expenseTypes = array_flip(BillingTransaction::EXPENSE_TYPES);
        foreach ($this->transactions->sumsByYear(['domain_id' => $id], $scope) as $year => $byType) {
            $running = MoneyHelper::sum(array_merge([$running], array_values(array_intersect_key($byType, $expenseTypes))));
            $timeline[] = ['year' => $year, 'total' => $running];
        }

        return [
            'transactions' => $rows,
            'purchase' => $purchase,
            'sale' => $sale,
            'totals' => [
                'purchase' => $total('purchase'),
                'renewals' => $total('renewal'),
                'renewal_count' => $sums['renewal']['count'] ?? 0,
                'transfers' => $total('transfer'),
                'other' => $total('other'),
                'invested' => $invested,
                'income' => $income,
                'profit' => MoneyHelper::subtract($income, $invested),
                'pending' => MoneyHelper::sum($pending),
            ],
            'expected' => $this->expectedRenewals([$domain], $scope)[$id] ?? null,
            'review_count' => $review,
            'timeline' => $timeline,
        ];
    }

    /**
     * The Renewal Cost and Total Spent columns of the Domains list
     *
     * @return array ['renewal_cost' => [domain id => decimal string or null], 'total_spent' => [domain id => decimal string]]
     */
    public function listValues(array $domains, ?int $scope): array
    {
        $costs = [];
        foreach ($this->expectedRenewals($this->payable($domains, $scope), $scope) as $id => $price) {
            $costs[$id] = $price['base'];
        }

        return ['renewal_cost' => $costs, 'total_spent' => $this->transactions->expensesByDomain([], $scope)];
    }

    /**
     * The domains whose renewals are the user's to pay: registered, not on a watchlist and not sold
     */
    private function payable(array $domains, ?int $scope): array
    {
        $sold = array_flip($this->transactions->soldDomainIds($scope));

        return array_values(array_filter($domains, fn(array $domain): bool => !isset($sold[(int)$domain['id']])
            && !$this->isWatched($domain) && ($domain['status'] ?? '') !== 'available'));
    }

    /**
     * The dashboard card: the renewals of the coming days with their expected cost, and how many
     * automatic payments wait. Null while billing is off, when nothing is priced and nothing waits,
     * and whenever reading it fails: the dashboard must not depend on it.
     */
    public function dashboardCard(array $domains, ?int $userId, int $days = 30): ?array
    {
        try {
            if (!$this->isOn()) {
                return null;
            }
            $scope = $this->scopeFor($userId);
            $today = date('Y-m-d');
            $until = date('Y-m-d', strtotime("+{$days} days"));
            $due = array_values(array_filter($this->payable($domains, $scope), function (array $domain) use ($today, $until): bool {
                $expires = substr((string)($domain['expiration_date'] ?? ''), 0, 10);
                return $expires !== '' && $expires >= $today && $expires <= $until;
            }));
            $expected = $this->expectedRenewals($due, $scope);

            $rows = [];
            $amounts = [];
            foreach ($due as $domain) {
                $id = (int)$domain['id'];
                $expires = substr((string)$domain['expiration_date'], 0, 10);
                $rows[] = [
                    'id' => $id,
                    'domain_name' => $domain['domain_name'],
                    'expiration_date' => $expires,
                    'days' => (int)round((strtotime($expires) - strtotime($today)) / 86400),
                    'expected' => $expected[$id] ?? null,
                ];
                if (($expected[$id]['base'] ?? null) !== null) {
                    $amounts[] = $expected[$id]['base'];
                }
            }
            usort($rows, fn(array $a, array $b): int => [$a['days'], $a['domain_name']] <=> [$b['days'], $b['domain_name']]);

            $review = $this->transactions->countReview($scope);
            if (!$amounts && $review === 0) {
                return null;
            }

            return [
                'days' => $days,
                'rows' => array_slice($rows, 0, 5),
                'count' => count($rows),
                'total' => MoneyHelper::sum($amounts),
                'unknown' => count($rows) - count($amounts),
                'review_count' => $review,
            ];
        } catch (\Throwable $e) {
            $this->logFailure('Billing card not read', [], $e);
            return null;
        }
    }

    /**
     * The renewal cost for an expiry reminder, such as "14.99 EUR". Null when none is known or wanted.
     */
    public function renewalCostText(array $domain): ?string
    {
        try {
            if (!$this->isOn() || !$this->settings->isBillingCostInReminders() || $this->isWatched($domain)) {
                return null;
            }
            $price = $this->expectedRenewals([$domain], $this->scopeFor($this->ownerOf($domain)))[(int)$domain['id']] ?? null;

            return $price ? MoneyHelper::format($price['price'], $price['currency']) : null;
        } catch (\Throwable $e) {
            $this->logFailure('Renewal cost not read', $domain, $e);
            return null;
        }
    }

    /**
     * Whether the domain is in a watchlist group: it belongs to someone else, who pays for it
     */
    public function isWatched(array $domain): bool
    {
        $groupId = (int)($domain['notification_group_id'] ?? 0);
        if (!$groupId) {
            return false;
        }
        if ($this->watchGroups === null) {
            $this->watchGroups = [];
            foreach ((new NotificationGroup())->all() as $group) {
                if (($group['purpose'] ?? 'own') === 'watch') {
                    $this->watchGroups[(int)$group['id']] = true;
                }
            }
        }

        return isset($this->watchGroups[$groupId]);
    }

    /**
     * After a domain was added: attach the payments saved under its name before
     */
    public function relinkDomain(int $domainId, string $domainName, ?int $ownerId): void
    {
        try {
            if ($this->isAvailable()) {
                $this->transactions->relinkByName($domainId, $domainName, $this->scopeFor($ownerId));
            }
        } catch (\Throwable $e) {
            $this->logFailure('Earlier payments not attached', ['domain_name' => $domainName], $e);
        }
    }

    /**
     * Whether a domain has payments. They stay under its name when it is deleted.
     */
    public function hasPayments(array $domain): bool
    {
        try {
            return $this->isAvailable() && $this->transactions->countForDomain((int)$domain['id'], null) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // The whole portfolio
    // ---------------------------------------------------------------------

    /**
     * Everything the Billing page shows above its list of payments
     *
     * @param array $domains The domains the user can see, as Domain::getAllWithGroups() returns them
     * @param int|null $scope From scopeFor()
     */
    public function portfolio(array $domains, ?int $scope): array
    {
        $today = date('Y-m-d');
        $total = fn(array $sums, array $types): string => MoneyHelper::sum(array_map(fn(string $type): string => $sums[$type]['total'] ?? '0.00', $types));
        $spent = fn(array $filters): string => $total($this->transactions->sumsByType($filters, $scope), BillingTransaction::EXPENSE_TYPES);
        $allTime = $this->transactions->sumsByType([], $scope);
        $invested = $total($allTime, BillingTransaction::EXPENSE_TYPES);
        $income = $total($allTime, BillingTransaction::INCOME_TYPES);

        $own = $this->payable($domains, $scope);
        $expected = $this->expectedRenewals($own, $scope);

        $windows = [30 => [], 90 => [], 365 => []];
        foreach ($windows as $limit => $unused) {
            $windows[$limit] = ['count' => 0, 'amounts' => [], 'unknown' => 0];
        }
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $first = strtotime(date('Y-m-01') . " +{$i} month");
            $months[date('Y-m', $first)] = ['label' => date('M Y', $first), 'amounts' => [], 'count' => 0];
        }

        $yearly = [];
        $withoutPrice = 0;
        $upcoming = [];
        foreach ($own as $domain) {
            $id = (int)$domain['id'];
            $base = $expected[$id]['base'] ?? null;
            if ($base === null) {
                $withoutPrice++;
            } else {
                $yearly[] = $base;
            }

            $expires = substr((string)($domain['expiration_date'] ?? ''), 0, 10);
            if ($expires === '' || $expires < $today) {
                continue;
            }
            $days = (int)round((strtotime($expires) - strtotime($today)) / 86400);
            foreach ($windows as $limit => $window) {
                if ($days <= $limit) {
                    $windows[$limit]['count']++;
                    if ($base === null) {
                        $windows[$limit]['unknown']++;
                    } else {
                        $windows[$limit]['amounts'][] = $base;
                    }
                }
            }
            $month = substr($expires, 0, 7);
            if (isset($months[$month])) {
                $months[$month]['count']++;
                if ($base !== null) {
                    $months[$month]['amounts'][] = $base;
                }
            }
            if ($days <= 365) {
                $upcoming[] = ['id' => $id, 'domain_name' => $domain['domain_name'], 'expiration_date' => $expires, 'days' => $days, 'expected' => $expected[$id] ?? null];
            }
        }
        usort($upcoming, fn(array $a, array $b): int => [$a['days'], $a['domain_name']] <=> [$b['days'], $b['domain_name']]);
        foreach ($windows as $limit => $window) {
            $windows[$limit] = ['count' => $window['count'], 'total' => MoneyHelper::sum($window['amounts']), 'unknown' => $window['unknown']];
        }
        foreach ($months as $key => $month) {
            $months[$key] = ['label' => $month['label'], 'total' => MoneyHelper::sum($month['amounts']), 'count' => $month['count']];
        }

        // Spending per year, the last eight years that have payments
        $years = [];
        foreach (array_slice($this->transactions->sumsByYear([], $scope), -8, null, true) as $year => $byType) {
            $years[] = ['year' => $year, 'income' => $total(array_map(fn(string $sum): array => ['total' => $sum], $byType), BillingTransaction::INCOME_TYPES)]
                + array_map(fn(string $type): string => $byType[$type] ?? '0.00', array_combine(BillingTransaction::EXPENSE_TYPES, BillingTransaction::EXPENSE_TYPES));
        }

        $spentByDomain = $this->transactions->expensesByDomain([], $scope);
        $visibleSpent = MoneyHelper::sum(array_intersect_key($spentByDomain, array_flip(array_map('intval', array_column($domains, 'id')))));

        return [
            'totals' => [
                'this_year' => $spent(['from' => date('Y-01-01'), 'to' => date('Y-12-31')]),
                'last_12_months' => $spent(['from' => date('Y-m-d', strtotime('-1 year +1 day')), 'to' => $today]),
                'invested' => $invested,
                'income' => $income,
                'profit' => MoneyHelper::subtract($income, $invested),
                'pending' => $this->transactions->pendingTotal($scope),
            ],
            'yearly_cost' => MoneyHelper::sum($yearly),
            'own_count' => count($own),
            'without_price' => $withoutPrice,
            'windows' => $windows,
            'upcoming' => array_slice($upcoming, 0, 10),
            'upcoming_count' => count($upcoming),
            'months' => array_values($months),
            'years' => $years,
            'breakdowns' => $this->breakdowns($domains, $expected, $spentByDomain),
            // Payments of deleted domains and costs that belong to no domain
            'other_spent' => MoneyHelper::subtract($invested, $visibleSpent),
            'review_count' => $this->transactions->countReview($scope),
        ];
    }

    /**
     * Yearly renewal cost and money spent per registrar, TLD, tag and notification group.
     * A domain with several tags counts under each of them.
     */
    private function breakdowns(array $domains, array $expected, array $spentByDomain): array
    {
        $groups = ['registrar' => [], 'tld' => [], 'tag' => [], 'group' => []];
        foreach ($domains as $domain) {
            $id = (int)$domain['id'];
            $registrar = trim((string)($domain['registrar'] ?? ''));
            $registrarKey = BillingPrice::registrarKey($registrar);
            $suffixes = DomainHelper::suffixes((string)$domain['domain_name']);
            $tags = array_filter(array_map('trim', explode(',', (string)($domain['tags'] ?? ''))), fn(string $tag): bool => $tag !== '');
            $places = [
                'registrar' => [[$registrarKey, $registrarKey !== '' ? $registrar : 'Unknown registrar']],
                'tld' => [[(string)end($suffixes), (string)end($suffixes) ?: 'No TLD']],
                'tag' => $tags ? array_map(fn(string $tag): array => [$tag, $tag], $tags) : [['', 'No tag']],
                'group' => [[(string)($domain['group_name'] ?? ''), ($domain['group_name'] ?? '') ?: 'No group']],
            ];
            foreach ($places as $kind => $labels) {
                foreach ($labels as [$key, $label]) {
                    $groups[$kind][$key] ??= ['label' => $label, 'domains' => 0, 'yearly' => [], 'spent' => []];
                    $groups[$kind][$key]['domains']++;
                    if (($expected[$id]['base'] ?? null) !== null) {
                        $groups[$kind][$key]['yearly'][] = $expected[$id]['base'];
                    }
                    if (isset($spentByDomain[$id])) {
                        $groups[$kind][$key]['spent'][] = $spentByDomain[$id];
                    }
                }
            }
        }

        foreach ($groups as $kind => $rows) {
            $rows = array_map(fn(array $row): array => ['label' => $row['label'], 'domains' => $row['domains'],
                'yearly' => MoneyHelper::sum($row['yearly']), 'spent' => MoneyHelper::sum($row['spent'])], array_values($rows));
            usort($rows, fn(array $a, array $b): int => [MoneyHelper::toCents($b['spent']), MoneyHelper::toCents($b['yearly']), $a['label']]
                <=> [MoneyHelper::toCents($a['spent']), MoneyHelper::toCents($a['yearly']), $b['label']]);
            $groups[$kind] = $rows;
        }

        return $groups;
    }

    // ---------------------------------------------------------------------
    // Renewals found by a check
    // ---------------------------------------------------------------------

    /**
     * Log the payment for a renewal a check has just detected (WhoisService::detectRenewal()).
     * It waits for confirmation. Nothing is logged for a watched or sold domain, for a renewal that
     * is logged already, or for a domain that has neither a price nor any payment.
     *
     * @param array $domain The domain row from before the check
     * @param array $whoisData The fresh lookup
     * @param array $renewal old_expiration, new_expiration and auto_renew_period
     * @return bool Whether a payment was added
     */
    public function logDetectedRenewal(array $domain, array $whoisData, array $renewal): bool
    {
        try {
            if (!$this->isOn() || !$this->settings->isBillingAutoLogOn() || $this->isWatched($domain)) {
                return false;
            }
            $domainId = (int)($domain['id'] ?? 0);
            $oldTime = strtotime((string)($renewal['old_expiration'] ?? ''));
            $newTime = strtotime((string)($renewal['new_expiration'] ?? ''));
            if (!$domainId || $oldTime === false || $newTime === false || $newTime <= $oldTime) {
                return false;
            }
            $expiresOn = date('Y-m-d', $newTime);
            $today = date('Y-m-d');
            $scope = $this->scopeFor($this->ownerOf($domain));

            // Sold: the buyer renewed it. Logged before or entered by hand: there is nothing to add.
            if ($this->transactions->hasSale($domainId, $scope)
                || $this->transactions->renewalLogged($domainId, $expiresOn)
                || $this->transactions->claimManual($domainId, $scope, $expiresOn, $today)) {
                return false;
            }

            // A move to another registrar adds a year too, and is paid there at the transfer price
            $oldRegistrar = trim((string)($domain['registrar'] ?? ''));
            $newRegistrar = trim((string)($whoisData['registrar'] ?? $oldRegistrar));
            $oldKey = BillingPrice::registrarKey($oldRegistrar);
            $newKey = BillingPrice::registrarKey($newRegistrar);
            $transferred = $oldKey !== '' && $newKey !== '' && $oldKey !== $newKey;
            $current = ['registrar' => $newRegistrar] + $domain;

            $price = $transferred ? $this->listPrice($current, 'transfer_price', $scope) : null;
            $price ??= $this->expectedRenewals([$current], $scope)[$domainId] ?? null;
            $rate = $price ? $this->rateFor($price['currency']) : null;
            $known = $price !== null && $rate !== null;
            if (!$known && $this->transactions->countForDomain($domainId, $scope) === 0) {
                // Neither a price nor a payment: this domain is not tracked in billing
                return false;
            }

            $years = max(1, (int)round(($newTime - $oldTime) / 86400 / 365));
            $grace = !empty($renewal['auto_renew_period']);
            $notes = 'Logged automatically: the expiry date moved from ' . date('Y-m-d', $oldTime) . ' to ' . $expiresOn . '.';
            if ($transferred) {
                $notes .= ' The registrar changed from ' . $oldRegistrar . ' to ' . $newRegistrar . '.';
            }
            if ($grace) {
                $notes .= ' The registry renewed it on its own, and the registrar can still delete it if it is not paid.';
            }
            if (!$known) {
                $notes .= ' No price is known, so the amount is missing.';
            }

            return $this->transactions->addDetected([
                'domain_id' => $domainId,
                'domain_name' => $domain['domain_name'],
                'user_id' => $this->ownerOf($domain),
                'type' => $transferred ? 'transfer' : 'renewal',
                'currency' => $known ? $price['currency'] : $this->baseCurrency(),
                'transaction_date' => $today,
                'vendor' => $newKey !== '' ? mb_substr($newRegistrar, 0, 255) : null,
                'status' => $grace ? 'pending' : 'paid',
                'term_years' => min($years, 100),
                'expires_on' => $expiresOn,
                'notes' => $notes,
            ], $known ? $price['price'] : '0.00', $known ? $rate : '1');
        } catch (\Throwable $e) {
            $this->logFailure('Renewal payment not logged', $domain, $e);
            return false;
        }
    }

    private function ownerOf(array $domain): ?int
    {
        return isset($domain['user_id']) && $domain['user_id'] !== '' ? (int)$domain['user_id'] : null;
    }

    private function logFailure(string $what, array $domain, \Throwable $e): void
    {
        try {
            $this->logger->warning($what, ['domain' => $domain['domain_name'] ?? null, 'error' => $e->getMessage()]);
        } catch (\Throwable $ignored) {
            // Logging must not fail a check either
        }
    }
}
