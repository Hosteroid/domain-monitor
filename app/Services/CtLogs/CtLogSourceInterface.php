<?php

namespace App\Services\CtLogs;

/**
 * A certificate transparency search service that a DNS deep scan asks for subdomains
 */
interface CtLogSourceInterface
{
    /**
     * Id stored in the ct_log_sources setting
     */
    public function getId(): string;

    /**
     * Name shown in logs and settings
     */
    public function getName(): string;

    /**
     * Find the subdomains of $domain named in certificates.
     *
     * @param  string   $domain The base domain (e.g. "example.com")
     * @param  callable $log    fn(string $line) for progress and response details
     * @return array{ok: bool, subs: string[], retry_after: ?int}
     *         ok: the service answered, even with an empty list
     *         retry_after: seconds to leave the service alone after it rate limited us
     */
    public function fetchSubdomains(string $domain, callable $log): array;
}
