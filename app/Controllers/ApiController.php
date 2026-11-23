<?php

namespace App\Controllers;

use App\Helpers\ApiFormat;
use App\Helpers\SslHelper;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Models\SslCertificate;
use App\Models\Tag;
use Core\ApiError;
use Core\ApiRequest;

/**
 * API calls that are not about one domain. Each method returns ['data' => ...], a list adds
 * 'meta'. Every answer is built field by field, never from a whole database row.
 */
class ApiController
{
    public function __construct(private ApiRequest $request)
    {
    }

    /**
     * GET /api/v1: checks a token and says what it may do
     */
    public function index(): array
    {
        $token = $this->request->token();
        $writes = $token['can_write'] && $this->request->setting('access') === 'read_write';

        return ['data' => [
            'app' => 'Domain Monitor',
            'version' => $this->request->setting('app_version'),
            'user' => $this->request->username(),
            'token' => $token['name'],
            'access' => $writes ? 'read_write' : 'read',
            'expires_at' => ApiFormat::unixTime($token['expires_at']),
        ]];
    }

    /**
     * GET /stats: the totals the dashboard shows, for the domains this user sees
     */
    public function stats(): array
    {
        $scope = $this->request->scopeUserId();
        $domains = (new Domain())->getStatistics($scope);
        $ssl = SslHelper::buildStats(SslHelper::formatMultiple((new SslCertificate())->getAllMonitored($scope)));

        // Domain::getStatistics() only has a key for a status some domain is in
        $count = static fn(array $from, string $key): int => (int)($from[$key] ?? 0);

        return ['data' => [
            'domains' => [
                'total' => $count($domains, 'total'),
                'active' => $count($domains, 'active'),
                'expiring_soon' => $count($domains, 'expiring_soon'),
                'expired' => $count($domains, 'expired'),
                'redemption_period' => $count($domains, 'redemption_period'),
                'pending_delete' => $count($domains, 'pending_delete'),
                'available' => $count($domains, 'available'),
                'error' => $count($domains, 'error'),
                'inactive' => $count($domains, 'inactive'),
                'expiring_threshold_days' => $count($domains, 'expiring_threshold'),
            ],
            'ssl' => [
                'total' => $count($ssl, 'total'),
                'valid' => $count($ssl, 'valid'),
                'expiring' => $count($ssl, 'expiring'),
                'expired' => $count($ssl, 'expired'),
                'invalid' => $count($ssl, 'invalid'),
            ],
        ]];
    }

    /**
     * GET /ssl: certificates of the domains with SSL monitoring on, soonest expiry first
     */
    public function certificates(): array
    {
        $status = $this->request->query('status', 20);
        if ($status !== null && !in_array($status, ['valid', 'expiring', 'expired', 'invalid'], true)) {
            throw new ApiError(422, 'invalid', 'The status must be valid, expiring, expired or invalid.');
        }

        $rows = SslHelper::formatMultiple((new SslCertificate())->getAllMonitored($this->request->scopeUserId()));
        if ($status !== null) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === $status));
        }
        [$rows, $meta] = $this->request->paginate($rows);

        return [
            'data' => array_map(static fn(array $row): array => [
                'domain_id' => (int)$row['domain_id'],
                'domain' => (string)$row['domain_name'],
            ] + ApiFormat::certificate($row), $rows),
            'meta' => $meta,
        ];
    }

    /**
     * GET /groups: notification groups by id and name, for add and update calls. Their channels
     * stay out: those hold webhook addresses and bot tokens.
     */
    public function groups(): array
    {
        $rows = (new NotificationGroup())->getAllWithChannelCount($this->request->scopeUserId());

        return ['data' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'purpose' => (string)($row['purpose'] ?? 'own'),
            'domains' => (int)$row['domain_count'],
        ], $rows)];
    }

    /**
     * GET /tags: the tags this user can use, with the number of domains that carry each
     */
    public function tags(): array
    {
        $rows = (new Tag())->getAllWithUsage($this->request->scopeUserId());

        return ['data' => array_map(static fn(array $row): array => [
            'name' => (string)$row['name'],
            'domains' => (int)$row['usage_count'],
        ], $rows)];
    }
}
