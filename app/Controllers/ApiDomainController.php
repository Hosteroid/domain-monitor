<?php

namespace App\Controllers;

use App\Helpers\ApiFormat;
use App\Helpers\InputValidator;
use App\Helpers\SslHelper;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SslCertificate;
use App\Services\DomainService;
use Core\ApiError;
use Core\ApiRequest;

/**
 * API calls about domains. Reading uses the queries of the Domains page, and adding, changing,
 * deleting and refreshing go through DomainService, as the pages do.
 */
class ApiDomainController
{
    /** Values of the status filter, the ones the Domains page offers */
    private const STATUSES = [
        'active', 'expiring_soon', 'expired', 'redemption_period', 'pending_delete', 'available', 'error', 'inactive',
    ];

    /** Sort keys of the list, by the column each sorts on */
    private const SORTS = [
        'domain' => 'domain_name',
        'registrar' => 'registrar',
        'expiration_date' => 'expiration_date',
        'status' => 'status',
        'group' => 'group_name',
        'created_at' => 'created_at',
        'last_checked' => 'last_checked',
    ];

    /** Fields a domain can be added with, and fields that can be changed */
    private const ADD_FIELDS = ['domain', 'notification_group_id', 'tags', 'notes'];
    private const CHANGE_FIELDS = [
        'notification_group_id', 'is_active', 'dns_monitoring', 'ssl_monitoring', 'tags', 'notes', 'expiration_date',
    ];

    private Domain $domains;
    private DomainService $service;

    public function __construct(private ApiRequest $request)
    {
        $this->domains = new Domain();
        $this->service = new DomainService();
    }

    /**
     * GET /domains: the list, filtered, sorted and cut into pages
     */
    public function index(): array
    {
        $scope = $this->request->scopeUserId();
        $threshold = $this->expiringThreshold();

        // One exact name: for a client that cannot put the name into the path
        $exact = $this->request->query('domain', 255);
        if ($exact !== null) {
            $found = $this->findByName($exact);
            $rows = $found ? [$this->domains->getWithTagsAndGroups((int)$found['id'], $scope) ?? $found] : [];

            return ['data' => $this->format($rows, $threshold), 'meta' => $this->request->paginate($rows)[1]];
        }

        $status = $this->request->query('status', 20);
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ApiError(422, 'invalid', 'The status must be one of: ' . implode(', ', self::STATUSES) . '.');
        }

        $sort = $this->request->query('sort', 20) ?? 'domain';
        if (!isset(self::SORTS[$sort])) {
            throw new ApiError(422, 'invalid', 'The sort must be one of: ' . implode(', ', array_keys(self::SORTS)) . '.');
        }

        $order = $this->request->query('order', 4) ?? 'asc';
        if ($order !== 'asc' && $order !== 'desc') {
            throw new ApiError(422, 'invalid', 'The order must be asc or desc.');
        }

        $perPage = $this->request->queryInt('per_page', 25, 1, 100);
        $page = $this->request->queryInt('page', 1, 1, 1000000);

        $result = $this->domains->getFilteredPaginated([
            'search' => $this->request->query('search', 100) ?? '',
            'status' => $status ?? '',
            'group' => $this->request->queryInt('group', 0, 0, 999999999),
            'tag' => $this->request->query('tag', 50) ?? '',
        ], self::SORTS[$sort], $order, $page, $perPage, $threshold, $scope);

        $total = (int)$result['pagination']['total'];
        $totalPages = (int)ceil($total / $perPage);

        // The model moves a page past the end back to the last one. Here it is empty, so a client
        // that reads page after page until nothing comes back does stop.
        $rows = $page > $totalPages ? [] : $result['domains'];

        return [
            'data' => $this->format($rows, $threshold),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
        ];
    }

    /**
     * GET /domains/{id or name}: one domain with its certificates
     */
    public function show(): array
    {
        return ['data' => $this->detail($this->resolve())];
    }

    /**
     * GET /domains/{id or name}/dns: its DNS records
     */
    public function dns(): array
    {
        $domain = $this->resolve();
        $records = [];
        foreach ((new DnsRecord())->getByDomainGrouped((int)$domain['id']) as $ofType) {
            foreach ($ofType as $record) {
                $records[] = ApiFormat::dnsRecord($record);
            }
        }

        return ['data' => $records];
    }

    /**
     * POST /domains: add a domain. The lookup runs inside the call, as on the Add Domain page.
     */
    public function store(): array
    {
        $this->refuseUnknownFields(self::ADD_FIELDS);

        $input = ['domain_name' => $this->request->has('domain') ? $this->request->string('domain', 255) : ''];
        if ($this->request->has('notification_group_id')) {
            $input['notification_group_id'] = $this->request->idOrNull('notification_group_id');
        }
        if ($this->request->has('tags')) {
            $input['tags'] = $this->tags();
        }
        if ($this->request->has('notes')) {
            $input['notes'] = $this->request->string('notes', 5000);
        }

        $result = $this->service->add($input, $this->request->userId());
        $this->refuseFailure($result);

        $domain = $this->service->findById((int)$result['id'], $this->request->userId());
        if (!$domain) {
            throw new ApiError(404, 'not_found', 'Domain not found.');
        }

        return ['data' => $this->detail($domain), 'status' => 201];
    }

    /**
     * PATCH /domains/{id or name}: change the fields that are sent, and only those
     */
    public function update(): array
    {
        $domain = $this->resolve();
        $this->refuseUnknownFields(self::CHANGE_FIELDS);
        if (!$this->request->bodyKeys()) {
            throw new ApiError(422, 'invalid', 'Send at least one field to change: ' . implode(', ', self::CHANGE_FIELDS) . '.');
        }

        $changes = [];
        if ($this->request->has('notification_group_id')) {
            $changes['notification_group_id'] = $this->request->idOrNull('notification_group_id');
        }
        foreach (['is_active' => 'is_active', 'dns_monitoring' => 'dns_monitoring_enabled', 'ssl_monitoring' => 'ssl_monitoring_enabled'] as $field => $column) {
            if ($this->request->has($field)) {
                $changes[$column] = $this->request->bool($field) ? 1 : 0;
            }
        }
        if ($this->request->has('tags')) {
            $changes['tags'] = $this->tags();
        }
        if ($this->request->has('notes')) {
            $changes['notes'] = $this->request->string('notes', 5000);
        }
        if ($this->request->has('expiration_date')) {
            // A date the registry reports cannot be set by hand: the next check would put it back,
            // and a date set earlier would then be taken for a renewal
            $saved = json_decode((string)($domain['whois_data'] ?? ''), true);
            if (is_array($saved) && !empty($saved['expiration_date'])) {
                throw new ApiError(409, 'registry_date', 'The registry reports the expiry date of this domain, so it cannot be set by hand.');
            }
            $changes['expiration_date'] = $this->request->stringOrNull('expiration_date', 10);
        }

        // No "monitoring changed" messages: like the bulk actions of the list, and a script that
        // goes through many domains would flood the notification channels
        $result = $this->service->update($domain, $changes, $this->request->userId(), false);
        $this->refuseFailure($result);

        return ['data' => $this->detail($domain)];
    }

    /**
     * DELETE /domains/{id or name}
     */
    public function destroy(): array
    {
        $domain = $this->resolve();
        $this->service->delete($domain);

        return ['data' => ['id' => (int)$domain['id'], 'domain' => (string)$domain['domain_name'], 'deleted' => true]];
    }

    /**
     * POST /domains/{id or name}/refresh: look the domain up again, as the Refresh button does
     */
    public function refresh(): array
    {
        $domain = $this->resolve();
        $this->refuseFailure($this->service->refresh($domain, $this->request->userId()));

        return ['data' => $this->detail($domain)];
    }

    /**
     * The domain the path names, by id or by name, among those this user may see
     */
    private function resolve(): array
    {
        $value = $this->request->param('domain');

        // Digits only is an id. Anything else is read as a name, which never is all digits.
        $domain = preg_match('/^[1-9][0-9]{0,9}$/', $value)
            ? $this->service->findById((int)$value, $this->request->userId())
            : $this->findByName($value);

        if (!$domain) {
            throw new ApiError(404, 'not_found', 'Domain not found.');
        }

        return $domain;
    }

    private function findByName(string $name): ?array
    {
        $name = strtolower($name);
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }

        return InputValidator::validateDomain($name) ? $this->service->findByName($name, $this->request->userId()) : null;
    }

    /**
     * The single-domain answer, read fresh so that it shows what a change left behind
     */
    private function detail(array $domain): array
    {
        $id = (int)$domain['id'];
        $row = $this->domains->getWithTagsAndGroups($id, $this->request->scopeUserId());
        if (!$row) {
            throw new ApiError(404, 'not_found', 'Domain not found.');
        }

        return ApiFormat::domainDetail(
            $row,
            $this->expiringThreshold(),
            $this->domains->createdTimestamps([$id])[$id] ?? null,
            SslHelper::formatMultiple((new SslCertificate())->getByDomain($id))
        );
    }

    /**
     * @param array $rows Domain rows with group_name and tags
     */
    private function format(array $rows, int $threshold): array
    {
        $created = $this->domains->createdTimestamps(array_column($rows, 'id'));

        return array_map(
            static fn(array $row): array => ApiFormat::domain($row, $threshold, $created[(int)$row['id']] ?? null),
            array_values($rows)
        );
    }

    /**
     * Days before expiry from which a domain counts as expiring soon: the longest reminder, as on the pages
     */
    private function expiringThreshold(): int
    {
        $days = (new Setting())->getNotificationDays();

        return !empty($days) ? (int)max($days) : 30;
    }

    /**
     * The tags of the body as the comma-separated text the service takes. Each is checked here:
     * the service's own check splits on commas, so "a,b" sent as one tag would become two.
     */
    private function tags(): string
    {
        $tags = [];
        foreach ($this->request->stringList('tags', 10, 50) as $tag) {
            $tag = strtolower(trim($tag));
            if (!preg_match('/^[a-z0-9-]{1,50}$/', $tag) || $tag === '0') {
                throw new ApiError(422, 'invalid', 'A tag holds letters, numbers and hyphens only, up to 50 characters.');
            }
            $tags[$tag] = true;
        }

        return implode(',', array_keys($tags));
    }

    private function refuseUnknownFields(array $known): void
    {
        $unknown = array_diff($this->request->bodyKeys(), $known);
        if ($unknown) {
            // Only the count and the known names: an unknown key is the caller's text
            throw new ApiError(422, 'invalid', count($unknown) . ' field(s) cannot be set. These can: ' . implode(', ', $known) . '.');
        }
    }

    /**
     * Turn a failed result of the service into the error answer for it
     */
    private function refuseFailure(array $result): void
    {
        if ($result['ok']) {
            return;
        }

        $code = $result['code'] ?? 'invalid';
        if ($code === 'lookup_failed') {
            throw new ApiError(
                !empty($result['rate_limited']) ? 503 : 502,
                'lookup_failed',
                $result['error'] ?? 'The registry could not be asked about this domain.'
            );
        }
        if ($code === 'exists') {
            throw new ApiError(409, 'exists', (string)$result['error']);
        }

        throw new ApiError(422, 'invalid', (string)$result['error']);
    }
}
