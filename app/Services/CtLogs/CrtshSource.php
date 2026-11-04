<?php

namespace App\Services\CtLogs;

/**
 * crt.sh (https://crt.sh): free and needs no key, but can be slow to answer
 * and is sometimes a day or more behind on new certificates.
 */
class CrtshSource extends AbstractCtLogSource
{
    private const MAX_ATTEMPTS = 5;
    private const RETRY_DELAY_SECONDS = 10;
    private const HTTP_TIMEOUT = 900;

    private const HEADERS = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive',
    ];

    public function getId(): string
    {
        return 'crtsh';
    }

    public function getName(): string
    {
        return 'crt.sh';
    }

    /**
     * Wildcard query ?q=%.domain.com, retried up to 5 times when crt.sh doesn't answer with a 200.
     * A rate limited answer ends the lookup at once, with the pause to leave crt.sh alone for.
     */
    public function fetchSubdomains(string $domain, callable $log): array
    {
        $url = 'https://crt.sh/?q=%25.' . urlencode($domain) . '&output=json';
        $attempts = self::MAX_ATTEMPTS;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $log("attempt $attempt/$attempts: GET $url");
            $response = $this->getJson($url, self::HTTP_TIMEOUT, self::HEADERS, $log);

            if ($response['status'] === 200) {
                // Each entry's name_value holds one or more names, one per line
                $names = [];
                foreach (is_array($response['data']) ? $response['data'] : [] as $entry) {
                    if (is_array($entry) && !empty($entry['name_value'])) {
                        array_push($names, ...explode("\n", (string)$entry['name_value']));
                    }
                }

                $subs = $this->subdomainsFromNames($names, $domain);
                $log("attempt $attempt/$attempts: " . ($names
                    ? count($subs) . ' subdomain(s) extracted'
                    : '200 OK but no cert data (domain may have no CT entries)'));
                return $this->result(true, $subs);
            }

            if ($response['status'] === 429) {
                $retryAfter = $response['retry_after'] ?? self::DEFAULT_RETRY_AFTER;
                $log("attempt $attempt/$attempts: rate limited for {$retryAfter}s");
                return $this->result(false, [], $retryAfter);
            }

            if ($attempt < $attempts) {
                $log("attempt $attempt/$attempts: retrying in " . self::RETRY_DELAY_SECONDS . 's...');
                sleep(self::RETRY_DELAY_SECONDS);
            }
        }

        $log("all $attempts attempts failed");
        return $this->result(false, []);
    }
}
