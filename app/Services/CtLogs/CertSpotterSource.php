<?php

namespace App\Services\CtLogs;

/**
 * Cert Spotter by SSLMate (https://sslmate.com/ct_search_api/): lists new certificates
 * within minutes. Works without a key for a few lookups an hour; with an API key the
 * account's plan sets the limit.
 */
class CertSpotterSource extends AbstractCtLogSource
{
    public const API_URL = 'https://api.certspotter.com/v1/issuances';

    private const HTTP_TIMEOUT = 60;
    /** Every page counts against the hourly limit, so a scan reads at most this many */
    private const MAX_PAGES = 5;
    /** A rate limit pause up to this long is waited out once; a longer one ends the lookup */
    private const MAX_WAIT_SECONDS = 60;

    public function __construct(
        private string $apiKey = '',
        private string $apiUrl = self::API_URL
    ) {
    }

    public function getId(): string
    {
        return 'certspotter';
    }

    public function getName(): string
    {
        return 'Cert Spotter';
    }

    /**
     * Read the unexpired issuances for the domain and its subdomains, page by page
     * until an empty page (the API's "no more results")
     */
    public function fetchSubdomains(string $domain, callable $log): array
    {
        $headers = ['User-Agent: Domain Monitor (+https://github.com/Hosteroid/domain-monitor)'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $names = [];
        $after = null;
        $waited = false;
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $query = ['domain' => $domain, 'include_subdomains' => 'true', 'expand' => 'dns_names'];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $url = $this->apiUrl . '?' . http_build_query($query);

            $log("page $page: GET $url");
            $response = $this->getJson($url, self::HTTP_TIMEOUT, $headers, $log);
            $data = $response['data'];

            if ($response['status'] === 429) {
                $retryAfter = $response['retry_after'] ?? self::DEFAULT_RETRY_AFTER;
                if (!$waited && $retryAfter <= self::MAX_WAIT_SECONDS) {
                    $log("page $page: rate limited, trying again in {$retryAfter}s");
                    sleep($retryAfter);
                    $waited = true;
                    continue;
                }

                $log("page $page: rate limited for {$retryAfter}s" . ($this->apiKey === ''
                    ? '; an API key can be added under Settings → Monitoring → DNS Discovery'
                    : ''));
                return $this->result($page > 1, $this->subdomainsFromNames($names, $domain), $retryAfter);
            }

            if ($response['status'] !== 200 || !is_array($data) || !array_is_list($data)) {
                // Errors come back as {"code": "...", "message": "..."}
                $error = is_array($data) ? (string)($data['message'] ?? $data['code'] ?? '') : '';
                $log("page $page: request failed (HTTP {$response['status']}" . ($error !== '' ? ": $error" : '') . ')');
                return $this->result($page > 1, $this->subdomainsFromNames($names, $domain));
            }

            if ($data === []) {
                break;
            }

            foreach ($data as $issuance) {
                foreach ((array)($issuance['dns_names'] ?? []) as $name) {
                    $names[] = $name;
                }
            }

            $after = (string)(end($data)['id'] ?? '');
            if ($after === '') {
                break;
            }

            if ($response['rate_limit_remaining'] === 0) {
                $log("page $page: no requests left this hour, stopping here");
                break;
            }

            if ($page === self::MAX_PAGES) {
                $log('stopped after ' . self::MAX_PAGES . ' pages');
            }
            $page++;
        }

        $subs = $this->subdomainsFromNames($names, $domain);
        $log(count($subs) . ' subdomain(s) extracted');
        return $this->result(true, $subs);
    }
}
