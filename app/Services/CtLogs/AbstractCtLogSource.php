<?php

namespace App\Services\CtLogs;

/**
 * HTTP and hostname handling shared by the certificate transparency sources
 */
abstract class AbstractCtLogSource implements CtLogSourceInterface
{
    /** Longest body preview written to the log */
    private const PREVIEW_BYTES = 2000;
    /** Pause assumed when a rate limited answer has no Retry-After header */
    protected const DEFAULT_RETRY_AFTER = 3600;

    /**
     * GET a JSON URL and log the response headers, size and a body preview.
     *
     * @param  string[] $headers Request headers, e.g. "User-Agent: ..."
     * @return array{status: int, data: mixed, retry_after: ?int, rate_limit_remaining: ?int}
     *         status is 0 when the connection failed or timed out
     */
    protected function getJson(string $url, int $timeout, array $headers, callable $log): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", array_merge(['Accept: application/json, text/plain, */*'], $headers)),
            ],
        ]);

        $start = microtime(true);
        $http_response_header = null;
        $body = @file_get_contents($url, false, $ctx);
        $elapsed = microtime(true) - $start;
        $bodyLen = is_string($body) ? strlen($body) : 0;

        $log('--- response ---');
        $log('Time: ' . sprintf('%.1f', $elapsed) . 's');
        $responseHeaders = is_array($http_response_header) ? $http_response_header : [];
        foreach ($responseHeaders ?: ['(no response headers — connection failed or timeout)'] as $header) {
            $log($header);
        }
        $log("Body: $bodyLen bytes");
        if ($bodyLen > 0) {
            $log($bodyLen > self::PREVIEW_BYTES ? substr($body, 0, self::PREVIEW_BYTES) . "\n... [truncated, $bodyLen total]" : $body);
        }
        $log('--- end response ---');

        // After a redirect the headers of every response are listed; the last status line is the answer
        $status = 0;
        $retryAfter = null;
        $remaining = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int)$m[1];
                $retryAfter = $remaining = null;
            } elseif (preg_match('/^Retry-After:\s*(.+)$/i', $header, $m)) {
                // Seconds or an HTTP date
                $value = trim($m[1]);
                if (ctype_digit($value)) {
                    $retryAfter = (int)$value;
                } elseif (($time = strtotime($value)) !== false) {
                    $retryAfter = max(0, $time - time());
                }
            } elseif (preg_match('/^X-Ratelimit-Remaining:\s*(\d+)/i', $header, $m)) {
                $remaining = (int)$m[1];
            }
        }

        return [
            'status' => $status,
            'data' => $bodyLen > 0 ? json_decode($body, true) : null,
            'retry_after' => $retryAfter,
            'rate_limit_remaining' => $remaining,
        ];
    }

    /**
     * Turn certificate names into unique subdomain labels of $domain
     * ("*.api.example.com" and "api.example.com" both give "api").
     *
     * @param  iterable $names Names from the certificates
     * @return string[]
     */
    protected function subdomainsFromNames(iterable $names, string $domain): array
    {
        $domainLower = strtolower($domain);
        $suffix = '.' . $domainLower;
        $subs = [];

        foreach ($names as $name) {
            $name = strtolower(trim((string)$name));

            if (str_starts_with($name, '*.')) {
                $name = substr($name, 2);
            }

            if ($name === $domainLower || !str_ends_with($name, $suffix)) {
                continue;
            }

            $sub = substr($name, 0, -strlen($suffix));
            if ($sub !== '') {
                $subs[$sub] = true;
            }
        }

        // Labels like "1" become integer keys, so turn them back into strings
        return array_map('strval', array_keys($subs));
    }

    /**
     * @return array{ok: bool, subs: string[], retry_after: ?int}
     */
    protected function result(bool $ok, array $subs, ?int $retryAfter = null): array
    {
        return ['ok' => $ok, 'subs' => $subs, 'retry_after' => $retryAfter];
    }
}
