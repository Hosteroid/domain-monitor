<?php

namespace App\Services;

class DnsService
{
    private Logger $logger;

    // https://www.cloudflare.com/ips-v4/ and /ips-v6/
    private const CLOUDFLARE_IPV4_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    private const CLOUDFLARE_IPV6_RANGES = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    private const SUBDOMAIN_WORDLIST = [
        'www', 'mail', 'ftp', 'smtp', 'pop', 'pop3', 'imap', 'webmail', 'email',
        'ns1', 'ns2', 'ns3', 'ns4', 'dns', 'dns1', 'dns2',
        'mx', 'mx1', 'mx2', 'relay', 'gateway', 'mailgw',
        'vpn', 'vpn1', 'vpn2', 'remote', 'access', 'proxy', 'fw', 'firewall',
        'api', 'api2', 'app', 'app1', 'app2', 'dev', 'dev2',
        'stage', 'staging', 'test', 'beta', 'demo', 'sandbox',
        'admin', 'panel', 'portal', 'dashboard', 'cms', 'cpanel', 'whm', 'plesk',
        'db', 'db1', 'db2', 'mysql', 'postgres', 'redis',
        'cdn', 'cdn1', 'cdn2', 'static', 'assets', 'media', 'img', 'images', 'files',
        'shop', 'store', 'pay', 'billing',
        'blog', 'forum', 'wiki', 'docs',
        'help', 'support', 'kb',
        'git', 'gitlab', 'ci', 'jenkins',
        'monitor', 'status', 'grafana',
        'sso', 'auth', 'login', 'id', 'oauth',
        'm', 'mobile',
        'intranet', 'internal', 'corp',
        'backup', 'old', 'legacy',
        'cloud', 'autodiscover', 'autoconfig', 'lyncdiscover', 'sip',
        'server', 'server1', 'server2', 'host', 'node1', 'node2',
        'web', 'web1', 'web2', 'www1', 'www2',
        'mail1', 'mail2', 'mail3', 'smtp1', 'smtp2', 'mta',
        'lb', 'haproxy', 'nginx', 'cache',
        'owa', 'exchange', 'outlook',
        'ns', 'mx0',
    ];

    private const SPECIAL_TXT_SUBDOMAINS = [
        '_dmarc',
        '_mta-sts',
    ];

    private const ROOT_RECORD_TYPES = [
        DNS_A     => 'A',
        DNS_AAAA  => 'AAAA',
        DNS_MX    => 'MX',
        DNS_TXT   => 'TXT',
        DNS_NS    => 'NS',
        DNS_CNAME => 'CNAME',
        DNS_SOA   => 'SOA',
        DNS_SRV   => 'SRV',
        DNS_CAA   => 'CAA',
    ];

    public function __construct()
    {
        $this->logger = new Logger('dns');
    }

    // ========================================================================
    // DNS SCAN METHODS
    // ========================================================================

    /**
     * Re-check only records that already exist in the database.
     * Queries root domain for all types + known subdomain hosts.
     * No wordlist brute force, no crt.sh. Used by the cron and Refresh button.
     */
    public function refreshExisting(string $domain, array $existingHosts = []): array
    {
        $this->logger->info("DNS refresh started", ['domain' => $domain, 'known_hosts' => count($existingHosts)]);

        [$records, $seen] = $this->queryRootDomain($domain);

        // Query known subdomain hosts directly (no existence probe needed)
        foreach ($existingHosts as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_A, 'A', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_CNAME, 'CNAME', $domain, $records, $seen);
            if (in_array($sub, ['_dmarc', '_mta-sts', '_domainkey']) || str_starts_with($sub, '_')) {
                $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
            }
        }

        foreach (self::SPECIAL_TXT_SUBDOMAINS as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
        }

        $this->resolveMxTargets($domain, $records, $seen);
        $this->resolveNsIps($records);
        $this->sortRecords($records);

        $totalRecords = array_sum(array_map('count', $records));
        $this->logger->info("DNS refresh completed", [
            'domain'        => $domain,
            'total_records' => $totalRecords,
        ]);

        return $records;
    }

    /**
     * Standard DNS lookup: root domain + resolve targets + special TXT.
     * No subdomain brute force, no crt.sh. Like running nslookup/dig.
     * Used by Discover > Quick Scan.
     */
    public function quickScan(string $domain): array
    {
        $this->logger->info("DNS quick scan started", ['domain' => $domain]);

        [$records, $seen] = $this->queryRootDomain($domain);

        // Add subdomains found as NS/MX/CNAME/SRV targets
        $targetSubs = $this->extractTargetSubdomains($domain, $records);
        foreach ($targetSubs as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_A, 'A', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_CNAME, 'CNAME', $domain, $records, $seen);
        }

        foreach (self::SPECIAL_TXT_SUBDOMAINS as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
        }

        $this->resolveMxTargets($domain, $records, $seen);
        $this->resolveNsIps($records);
        $this->sortRecords($records);

        $totalRecords = array_sum(array_map('count', $records));
        $this->logger->info("DNS quick scan completed", [
            'domain'        => $domain,
            'total_records' => $totalRecords,
        ]);

        return $records;
    }

    /**
     * Full discovery: root + wordlist brute force + crt.sh extras + wildcard detection.
     * Used by Discover > Deep Scan and the discover_dns.php script.
     *
     * @param string        $domain          The domain to scan
     * @param array         $extraSubdomains Additional candidates (e.g. from crt.sh or previous scans)
     * @param callable|null $onProgress      Optional callback for progress messages: fn(string $msg)
     */
    public function lookup(string $domain, array $extraSubdomains = [], ?callable $onProgress = null): array
    {
        $log = $onProgress ?? function (string $msg) {};

        $this->logger->info("DNS deep lookup started", ['domain' => $domain]);

        $log("Querying root domain...");
        [$records, $seen] = $this->queryRootDomain($domain);
        $rootCount = array_sum(array_map('count', $records));
        $log("Root query done: {$rootCount} record(s)");

        // Build subdomain candidates from wordlist + extras + targets found in NS/MX/CNAME/SRV
        $candidates = array_merge(self::SUBDOMAIN_WORDLIST, $extraSubdomains);
        $targetSubs = $this->extractTargetSubdomains($domain, $records);
        $candidates = array_unique(array_merge($candidates, $targetSubs));

        // Wildcard detection: probe a random nonsense subdomain
        $wildcardDetected = false;
        $probeHost = '_dm-wc-' . bin2hex(random_bytes(4)) . '.' . $domain;
        $log("Wildcard detection: probing random subdomain...");
        if ($this->subdomainExists($probeHost)) {
            $wildcardDetected = true;
            $this->logger->info("Wildcard DNS detected, skipping brute force", ['domain' => $domain]);
            $log("⚠ Wildcard DNS detected — brute force skipped, using only crt.sh/known hosts");
            // Only use crt.sh/extra candidates + DB hosts (real subdomains), not wordlist
            $candidates = array_values(array_unique($extraSubdomains));
        } else {
            $log("No wildcard detected");
        }

        // Probe subdomains — fast checkdnsrr existence test
        $total = count($candidates);
        $log("Probing {$total} subdomain candidate(s)...");
        $discovered = [];
        $probed = 0;
        foreach ($candidates as $sub) {
            $fqdn = "{$sub}.{$domain}";
            if ($this->subdomainExists($fqdn)) {
                $discovered[] = $sub;
            }
            $probed++;
            if ($probed % 25 === 0 || $probed === $total) {
                $log("Probed {$probed}/{$total} — found " . count($discovered) . " so far");
            }
        }
        $log("Subdomain probe complete: " . count($discovered) . " found out of {$total}");

        // Deep scan discovered subdomains (A, AAAA, CNAME, TXT)
        if (!empty($discovered)) {
            $log("Querying " . count($discovered) . " discovered subdomain(s)...");
        }
        foreach ($discovered as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_A, 'A', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_CNAME, 'CNAME', $domain, $records, $seen);
            if (in_array($sub, ['_dmarc', '_mta-sts', '_domainkey']) || str_starts_with($sub, '_')) {
                $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
            }
        }

        $log("Querying special TXT subdomains...");
        foreach (self::SPECIAL_TXT_SUBDOMAINS as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
        }

        $log("Resolving MX/NS targets...");
        $this->resolveMxTargets($domain, $records, $seen);
        $this->resolveNsIps($records);
        $this->sortRecords($records);

        $totalRecords = array_sum(array_map('count', $records));
        $this->logger->info("DNS deep lookup completed", [
            'domain'             => $domain,
            'total_records'      => $totalRecords,
            'subdomains_discovered' => count($discovered),
            'wildcard_detected'  => $wildcardDetected,
        ]);

        return $records;
    }

    // ========================================================================
    // LOOKUP HELPERS
    // ========================================================================

    /**
     * Query a FQDN for a specific record type and collect deduplicated results.
     */
    private function queryAndCollect(
        string $fqdn, int $dnsConst, string $typeName,
        string $baseDomain, array &$records, array &$seen
    ): void {
        try {
            $raw = @dns_get_record($fqdn, $dnsConst);
            if ($raw === false || empty($raw)) {
                return;
            }
            foreach ($raw as $entry) {
                $parsed = $this->parseRecord($typeName, $entry, $baseDomain);
                if ($parsed) {
                    $this->addIfNew($typeName, $parsed, $records, $seen);
                }
            }
        } catch (\Throwable $e) {
            // Non-existent subdomain or network issue — not worth logging
        }
    }

    /**
     * DNS_ALL fallback to catch records missed by individual queries.
     */
    private function queryAllFallback(string $fqdn, string $baseDomain, array &$records, array &$seen): void
    {
        try {
            $all = @dns_get_record($fqdn, DNS_ALL);
            if (!is_array($all) || empty($all)) {
                return;
            }
            foreach ($all as $entry) {
                $type = strtoupper($entry['type'] ?? '');
                if ($type && isset($records[$type])) {
                    $parsed = $this->parseRecord($type, $entry, $baseDomain);
                    if ($parsed) {
                        $this->addIfNew($type, $parsed, $records, $seen);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Add a record only if it hasn't been seen before (dedup).
     */
    private function addIfNew(string $type, array $parsed, array &$records, array &$seen): void
    {
        $priority = $parsed['priority'] ?? '';
        $dedupKey = "{$type}:{$parsed['host']}:{$parsed['value']}:{$priority}";
        if (isset($seen[$dedupKey])) {
            return;
        }
        $seen[$dedupKey] = true;
        $records[$type][] = $parsed;
    }

    /**
     * Fast existence check for a subdomain.
     */
    private function subdomainExists(string $fqdn): bool
    {
        if (@checkdnsrr($fqdn, 'A'))     return true;
        if (@checkdnsrr($fqdn, 'AAAA'))  return true;
        if (@checkdnsrr($fqdn, 'CNAME')) return true;
        $ip = @gethostbyname($fqdn);
        return ($ip !== $fqdn);
    }

    /**
     * Resolve a hostname to its A and AAAA IPs.
     */
    private function resolveHostIps(string $hostname): array
    {
        $ips = ['ipv4' => [], 'ipv6' => []];

        $a = @dns_get_record($hostname, DNS_A);
        if (is_array($a)) {
            foreach ($a as $r) {
                if (!empty($r['ip'])) {
                    $ips['ipv4'][] = $r['ip'];
                }
            }
        }

        $aaaa = @dns_get_record($hostname, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (!empty($r['ipv6'])) {
                    $ips['ipv6'][] = $r['ipv6'];
                }
            }
        }

        return $ips;
    }

    // ========================================================================
    // SHARED SCAN HELPERS
    // ========================================================================

    /**
     * Query root domain for all record types + DNS_ALL fallback + gethostbynamel fallback.
     *
     * @return array{0: array, 1: array} [$records, $seen]
     */
    private function queryRootDomain(string $domain): array
    {
        $records = [
            'A' => [], 'AAAA' => [], 'MX' => [], 'TXT' => [],
            'NS' => [], 'CNAME' => [], 'SOA' => [], 'SRV' => [], 'CAA' => [],
        ];
        $seen = [];

        foreach (self::ROOT_RECORD_TYPES as $dnsConst => $typeName) {
            $this->queryAndCollect($domain, $dnsConst, $typeName, $domain, $records, $seen);
        }

        $this->queryAllFallback($domain, $domain, $records, $seen);

        if (empty($records['A'])) {
            $ips = @gethostbynamel($domain);
            if (is_array($ips)) {
                foreach ($ips as $ip) {
                    $this->addIfNew('A', [
                        'host' => '@', 'value' => $ip, 'ttl' => 0,
                        'is_cloudflare' => $this->isCloudflareIp($ip),
                        'raw' => ['host' => $domain, 'type' => 'A', 'ip' => $ip, 'ttl' => 0],
                    ], $records, $seen);
                }
            }
        }

        return [$records, $seen];
    }

    /**
     * Extract subdomain labels found as NS/MX/CNAME/SRV targets under the given domain.
     */
    private function extractTargetSubdomains(string $domain, array $records): array
    {
        $subs = [];
        foreach (['NS', 'MX', 'CNAME', 'SRV'] as $type) {
            foreach ($records[$type] as $rec) {
                $target = rtrim($rec['value'] ?? '', '.');
                if ($target && str_ends_with(strtolower($target), '.' . strtolower($domain))) {
                    $sub = str_replace('.' . $domain, '', strtolower($target));
                    if ($sub && !in_array($sub, $subs)) {
                        $subs[] = $sub;
                    }
                }
            }
        }
        return $subs;
    }

    /**
     * Resolve MX targets that are under the domain — add their A/AAAA records.
     */
    private function resolveMxTargets(string $domain, array &$records, array &$seen): void
    {
        foreach ($records['MX'] as $mxRec) {
            $target = rtrim($mxRec['value'] ?? '', '.');
            if ($target && str_ends_with(strtolower($target), '.' . strtolower($domain))) {
                $this->queryAndCollect($target, DNS_A, 'A', $domain, $records, $seen);
                $this->queryAndCollect($target, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            }
        }
    }

    /**
     * Resolve NS server hostnames to their A/AAAA IPs (stored in raw data for display).
     */
    private function resolveNsIps(array &$records): void
    {
        foreach ($records['NS'] as &$nsRec) {
            $nsHost = rtrim($nsRec['value'] ?? '', '.');
            if ($nsHost) {
                $nsIps = $this->resolveHostIps($nsHost);
                $nsRec['raw']['_ns_ips'] = $nsIps;
            }
        }
        unset($nsRec);
    }

    /**
     * Sort A/AAAA records: root (@) first, then alphabetical by host.
     */
    private function sortRecords(array &$records): void
    {
        foreach (['A', 'AAAA'] as $type) {
            usort($records[$type], function ($a, $b) {
                if ($a['host'] === '@') return -1;
                if ($b['host'] === '@') return 1;
                return strcmp($a['host'], $b['host']);
            });
        }
    }

    // ========================================================================
    // BIND ZONE FILE PARSER
    // ========================================================================

    /**
     * Parse BIND zone file content into grouped records matching our internal format.
     *
     * Handles standard BIND syntax:
     *   @  IN  A  1.2.3.4
     *   www  IN  CNAME  example.com.
     *   mail  IN  MX  10  mx.example.com.
     *   @ 3600 IN TXT "v=spf1 ..."
     *
     * @return array Grouped records ['A' => [...], 'MX' => [...], ...]
     */
    public function parseBindZone(string $content, string $domain): array
    {
        $records = [
            'A' => [], 'AAAA' => [], 'MX' => [], 'TXT' => [],
            'NS' => [], 'CNAME' => [], 'SOA' => [], 'SRV' => [], 'CAA' => [],
        ];
        $seen = [];
        $supportedTypes = array_keys($records);

        $lines = preg_split('/\r?\n/', $content);
        $lastHost = '@';
        $defaultTtl = 3600;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === ';') {
                continue;
            }

            // $TTL directive
            if (preg_match('/^\$TTL\s+(\d+)/i', $line, $m)) {
                $defaultTtl = (int)$m[1];
                continue;
            }

            // Skip other directives ($ORIGIN, $INCLUDE, etc.)
            if ($line[0] === '$') {
                continue;
            }

            // Strip inline comments (not inside quotes)
            $line = preg_replace('/\s;[^"]*$/', '', $line);

            // Standard BIND format: [name] [ttl] [class] type rdata
            $tokens = preg_split('/\s+/', $line);
            if (count($tokens) < 3) {
                continue;
            }

            $host = null;
            $ttl  = $defaultTtl;
            $type = null;
            $rdataStart = 0;

            $idx = 0;

            // First token: hostname, or continuation (starts with a type or digit)
            if (!ctype_digit($tokens[0]) && !in_array(strtoupper($tokens[0]), $supportedTypes)
                && strtoupper($tokens[0]) !== 'IN') {
                $host = $tokens[0];
                $idx = 1;
            }

            // Optional TTL (numeric)
            if (isset($tokens[$idx]) && ctype_digit($tokens[$idx])) {
                $ttl = (int)$tokens[$idx];
                $idx++;
            }

            // Optional class (IN)
            if (isset($tokens[$idx]) && strtoupper($tokens[$idx]) === 'IN') {
                $idx++;
            }

            // Record type
            if (!isset($tokens[$idx])) {
                continue;
            }
            $type = strtoupper($tokens[$idx]);
            $idx++;

            if (!in_array($type, $supportedTypes)) {
                continue;
            }

            $rdataStart = $idx;
            $rdata = array_slice($tokens, $rdataStart);
            if (empty($rdata)) {
                continue;
            }

            // Resolve host
            if ($host === null) {
                $host = $lastHost;
            } elseif ($host === '@') {
                $lastHost = '@';
            } else {
                $host = rtrim($host, '.');
                // Strip the domain suffix to get just the subdomain label
                $lowerHost = strtolower($host);
                $lowerDomain = strtolower($domain);
                if ($lowerHost === $lowerDomain) {
                    $host = '@';
                } elseif (str_ends_with($lowerHost, '.' . $lowerDomain)) {
                    $host = substr($host, 0, -(strlen($domain) + 1));
                }
                $lastHost = $host;
            }

            // Build record
            $value = implode(' ', $rdata);
            $priority = null;
            $parsed = null;

            switch ($type) {
                case 'A':
                    $parsed = [
                        'host' => $host, 'value' => $rdata[0], 'ttl' => $ttl,
                        'is_cloudflare' => $this->isCloudflareIp($rdata[0]),
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'A', 'ip' => $rdata[0], 'ttl' => $ttl],
                    ];
                    break;
                case 'AAAA':
                    $parsed = [
                        'host' => $host, 'value' => $rdata[0], 'ttl' => $ttl,
                        'is_cloudflare' => false,
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'AAAA', 'ipv6' => $rdata[0], 'ttl' => $ttl],
                    ];
                    break;
                case 'CNAME':
                    $parsed = [
                        'host' => $host, 'value' => rtrim($rdata[0], '.'), 'ttl' => $ttl,
                        'is_cloudflare' => false,
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'CNAME', 'target' => rtrim($rdata[0], '.'), 'ttl' => $ttl],
                    ];
                    break;
                case 'MX':
                    $priority = (int)($rdata[0] ?? 0);
                    $target = rtrim($rdata[1] ?? '', '.');
                    $parsed = [
                        'host' => $host, 'value' => $target, 'ttl' => $ttl,
                        'priority' => $priority, 'is_cloudflare' => false,
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'MX', 'pri' => $priority, 'target' => $target, 'ttl' => $ttl],
                    ];
                    break;
                case 'NS':
                    $parsed = [
                        'host' => $host, 'value' => rtrim($rdata[0], '.'), 'ttl' => $ttl,
                        'is_cloudflare' => false,
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'NS', 'target' => rtrim($rdata[0], '.'), 'ttl' => $ttl],
                    ];
                    break;
                case 'TXT':
                    $txtValue = implode(' ', $rdata);
                    $txtValue = trim($txtValue, '"');
                    $parsed = [
                        'host' => $host, 'value' => $txtValue, 'ttl' => $ttl,
                        'is_cloudflare' => false,
                        'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'TXT', 'txt' => $txtValue, 'ttl' => $ttl],
                    ];
                    break;
                case 'SRV':
                    if (count($rdata) >= 4) {
                        $priority = (int)$rdata[0];
                        $weight = (int)$rdata[1];
                        $port = (int)$rdata[2];
                        $target = rtrim($rdata[3], '.');
                        $parsed = [
                            'host' => $host, 'value' => $target, 'ttl' => $ttl,
                            'priority' => $priority, 'is_cloudflare' => false,
                            'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'SRV', 'pri' => $priority, 'weight' => $weight, 'port' => $port, 'target' => $target, 'ttl' => $ttl],
                        ];
                    }
                    break;
                case 'CAA':
                    if (count($rdata) >= 3) {
                        $flags = (int)$rdata[0];
                        $tag = $rdata[1];
                        $caaValue = trim(implode(' ', array_slice($rdata, 2)), '"');
                        $parsed = [
                            'host' => $host, 'value' => "{$flags} {$tag} \"{$caaValue}\"", 'ttl' => $ttl,
                            'is_cloudflare' => false,
                            'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'CAA', 'flags' => $flags, 'tag' => $tag, 'value' => $caaValue, 'ttl' => $ttl],
                        ];
                    }
                    break;
                case 'SOA':
                    if (count($rdata) >= 7) {
                        $parsed = [
                            'host' => $host, 'value' => implode(' ', $rdata), 'ttl' => $ttl,
                            'is_cloudflare' => false,
                            'raw' => ['host' => ($host === '@' ? $domain : "{$host}.{$domain}"), 'type' => 'SOA', 'mname' => rtrim($rdata[0], '.'), 'rname' => rtrim($rdata[1], '.'), 'serial' => (int)$rdata[2], 'refresh' => (int)$rdata[3], 'retry' => (int)$rdata[4], 'expire' => (int)$rdata[5], 'minimum-ttl' => (int)$rdata[6], 'ttl' => $ttl],
                        ];
                    }
                    break;
            }

            if ($parsed) {
                $this->addIfNew($type, $parsed, $records, $seen);
            }
        }

        $this->sortRecords($records);
        return $records;
    }

    // ========================================================================
    // CERTIFICATE TRANSPARENCY (crt.sh)
    // ========================================================================

    /**
     * Discover subdomains via crt.sh Certificate Transparency logs.
     *
     * Spawns check_dns.php --crtsh as a subprocess with a hard timeout to
     * protect against crt.sh hangs. The subprocess handles HTTP retries and
     * streams debug output to stderr, relayed via the $onStderrLine callback.
     *
     * @param  string        $domain          The domain to scan
     * @param  int           $maxSubdomains   Cap on returned subdomains (0 = no limit)
     * @param  int           $timeoutSeconds  Hard kill timeout for the subprocess
     * @param  callable|null $onStderrLine    fn(string $line) for real-time stderr relay
     * @return array{0: string[], 1: bool}    [subdomains, serverResponded]
     */
    public function fetchCrtshSubdomains(
        string $domain,
        int $maxSubdomains = 100,
        int $timeoutSeconds = 1800,
        ?callable $onStderrLine = null
    ): array {
        $phpBin     = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $scriptPath = dirname(__DIR__, 2) . '/cron/check_dns.php';
        $cmd        = [$phpBin, $scriptPath, '--crtsh', $domain];

        if ($maxSubdomains > 0) {
            $cmd[] = (string) $maxSubdomains;
        }

        $projectRoot = dirname(__DIR__, 2);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $projectRoot);

        if (!is_resource($proc)) {
            $this->logger->error('Failed to spawn crt.sh subprocess', ['domain' => $domain]);
            return [[], false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start        = time();
        $stdout       = '';
        $stderrBuffer = '';

        while (true) {
            $status = proc_get_status($proc);

            if (!$status['running']) {
                break;
            }

            $elapsed = time() - $start;

            if ($elapsed >= $timeoutSeconds) {
                $stdout .= self::drainStream($pipes[1]);
                $stderrBuffer .= self::drainStream($pipes[2]);
                $this->flushCrtshStderrLines($stderrBuffer, $onStderrLine);
                proc_terminate($proc, 9);
                proc_close($proc);
                $this->logger->warning("crt.sh subprocess killed after {$elapsed}s", ['domain' => $domain]);
                return [[], false];
            }

            $readable = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($readable, $w, $e, 0, 200000) > 0) {
                foreach ($readable as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderrBuffer .= $chunk;
                        $this->flushCrtshStderrLines($stderrBuffer, $onStderrLine);
                    }
                }
            }
            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderrBuffer .= stream_get_contents($pipes[2]);
        $this->flushCrtshStderrLines($stderrBuffer, $onStderrLine);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $decoded = json_decode($stdout, true);
        $ok   = is_array($decoded) && !empty($decoded['ok']);
        $subs = is_array($decoded) && isset($decoded['subs']) ? $decoded['subs'] : [];

        $this->logger->info('crt.sh discovery completed', [
            'domain'           => $domain,
            'subdomains_found' => count($subs),
            'server_ok'        => $ok,
        ]);

        return [$subs, $ok];
    }

    /**
     * Fetch a crt.sh URL with optional debug output to stderr.
     *
     * Called from the crt.sh subprocess where stderr is relayed to the parent
     * in real-time. Pass $debug = true in subprocess context.
     *
     * @return array{status: int, body_length: int, data: array, time: float}
     */
    public function fetchCrtshUrl(string $url, int $timeout = 900, bool $debug = false): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: application/json, text/plain, */*',
                    'Accept-Language: en-US,en;q=0.9',
                    'Connection: keep-alive',
                ]),
            ],
        ]);

        $start = microtime(true);
        $http_response_header = null;
        $body = @file_get_contents($url, false, $ctx);
        $elapsed = microtime(true) - $start;

        $bodyLen = is_string($body) ? strlen($body) : 0;

        if ($debug) {
            fwrite(STDERR, "--- response ---\n");
            fwrite(STDERR, "Time: " . sprintf('%.1f', $elapsed) . "s\n");

            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    fwrite(STDERR, "$h\n");
                }
            } else {
                fwrite(STDERR, "(no response headers — connection failed or timeout)\n");
            }

            fwrite(STDERR, "Body: $bodyLen bytes\n");

            if (is_string($body) && $bodyLen > 0) {
                $preview = $bodyLen > 2000 ? substr($body, 0, 2000) . "\n... [truncated, $bodyLen total]" : $body;
                fwrite(STDERR, $preview . "\n");
            }

            fwrite(STDERR, "--- end response ---\n");
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int) $m[0];
        }

        $data = [];
        if ($status === 200 && is_string($body) && $bodyLen > 2) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'status'      => $status,
            'body_length' => $bodyLen,
            'data'        => $data,
            'time'        => $elapsed,
        ];
    }

    /**
     * Extract unique subdomain prefixes from raw crt.sh JSON response.
     *
     * Each entry has a name_value field that may contain multiple newline-separated
     * names, including wildcards. Returns only the subdomain prefixes
     * (e.g. "www", "mail", "api").
     *
     * @param  array  $crtshData  Decoded JSON array from crt.sh
     * @param  string $domain     The base domain (e.g. "example.com")
     * @return string[]           Unique subdomain prefixes
     */
    public function extractCrtshSubdomains(array $crtshData, string $domain): array
    {
        $domainLower = strtolower($domain);
        $suffix      = '.' . $domainLower;
        $suffixLen   = strlen($suffix);
        $subs        = [];

        foreach ($crtshData as $entry) {
            if (empty($entry['name_value'])) {
                continue;
            }

            foreach (explode("\n", $entry['name_value']) as $name) {
                $name = strtolower(trim($name));

                if (strpos($name, '*.') === 0) {
                    $name = substr($name, 2);
                }

                if ($name === $domainLower) {
                    continue;
                }

                if (substr($name, -$suffixLen) !== $suffix) {
                    continue;
                }

                $sub = substr($name, 0, strlen($name) - $suffixLen);
                if (!empty($sub)) {
                    $subs[$sub] = true;
                }
            }
        }

        return array_keys($subs);
    }

    /**
     * Flush complete stderr lines from buffer via callback.
     */
    private function flushCrtshStderrLines(string &$buffer, ?callable $onLine): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line !== '' && $onLine) {
                $onLine($line);
            }
        }
    }

    /**
     * Drain remaining data from a non-blocking stream and close it.
     */
    private static function drainStream($stream): string
    {
        if (!is_resource($stream)) {
            return '';
        }
        $data = stream_get_contents($stream);
        fclose($stream);
        return $data ?: '';
    }

    // ========================================================================
    // RECORD PARSING
    // ========================================================================

    /**
     * Parse a raw dns_get_record entry into a normalized record.
     */
    private function parseRecord(string $type, array $entry, string $domain): ?array
    {
        $host = $entry['host'] ?? $domain;
        $hostLower = strtolower($host);
        $domainLower = strtolower($domain);

        // Skip records that resolved to external domains (e.g. CNAME target chains)
        if ($hostLower !== $domainLower && !str_ends_with($hostLower, '.' . $domainLower)) {
            return null;
        }

        $hostLabel = ($hostLower === $domainLower)
            ? '@'
            : str_ireplace('.' . $domain, '', $host);
        $ttl = $entry['ttl'] ?? null;

        switch ($type) {
            case 'A':
                $ip = $entry['ip'] ?? '';
                if (empty($ip)) return null;
                return [
                    'host'          => $hostLabel,
                    'value'         => $ip,
                    'ttl'           => $ttl,
                    'is_cloudflare' => $this->isCloudflareIp($ip),
                    'raw'           => $entry,
                ];

            case 'AAAA':
                $ip = $entry['ipv6'] ?? '';
                if (empty($ip)) return null;
                return [
                    'host'          => $hostLabel,
                    'value'         => $ip,
                    'ttl'           => $ttl,
                    'is_cloudflare' => $this->isCloudflareIpv6($ip),
                    'raw'           => $entry,
                ];

            case 'MX':
                $target = $entry['target'] ?? '';
                if (empty($target)) return null;
                return [
                    'host'     => $hostLabel,
                    'value'    => $target,
                    'priority' => $entry['pri'] ?? 0,
                    'ttl'      => $ttl,
                    'raw'      => $entry,
                ];

            case 'TXT':
                $txt = $entry['txt'] ?? '';
                if (empty($txt)) return null;
                return [
                    'host'     => $hostLabel,
                    'value'    => $txt,
                    'ttl'      => $ttl,
                    'txt_type' => $this->classifyTxtRecord($txt),
                    'raw'      => $entry,
                ];

            case 'NS':
                $target = $entry['target'] ?? '';
                if (empty($target)) return null;
                return [
                    'host'  => $hostLabel,
                    'value' => $target,
                    'ttl'   => $ttl,
                    'raw'   => $entry,
                ];

            case 'CNAME':
                $target = $entry['target'] ?? '';
                if (empty($target)) return null;
                return [
                    'host'  => $hostLabel,
                    'value' => $target,
                    'ttl'   => $ttl,
                    'raw'   => $entry,
                ];

            case 'SOA':
                return [
                    'host'    => $hostLabel,
                    'value'   => $entry['mname'] ?? '',
                    'rname'   => $entry['rname'] ?? '',
                    'serial'  => $entry['serial'] ?? 0,
                    'refresh' => $entry['refresh'] ?? 0,
                    'retry'   => $entry['retry'] ?? 0,
                    'expire'  => $entry['expire'] ?? 0,
                    'minimum' => $entry['minimum-ttl'] ?? 0,
                    'ttl'     => $ttl,
                    'raw'     => $entry,
                ];

            case 'SRV':
                $target = $entry['target'] ?? '';
                if (empty($target)) return null;
                return [
                    'host'     => $hostLabel,
                    'value'    => $target,
                    'priority' => $entry['pri'] ?? 0,
                    'weight'   => $entry['weight'] ?? 0,
                    'port'     => $entry['port'] ?? 0,
                    'ttl'      => $ttl,
                    'raw'      => $entry,
                ];

            case 'CAA':
                $value = ($entry['flags'] ?? 0) . ' ' . ($entry['tag'] ?? '') . ' "' . ($entry['value'] ?? '') . '"';
                return [
                    'host'  => $hostLabel,
                    'value' => $value,
                    'flags' => $entry['flags'] ?? 0,
                    'tag'   => $entry['tag'] ?? '',
                    'ca'    => $entry['value'] ?? '',
                    'ttl'   => $ttl,
                    'raw'   => $entry,
                ];

            default:
                return null;
        }
    }

    /**
     * Classify a TXT record's purpose.
     */
    private function classifyTxtRecord(string $value): string
    {
        $lower = strtolower($value);
        if (str_starts_with($lower, 'v=spf1'))  return 'SPF';
        if (str_starts_with($lower, 'v=dkim1')) return 'DKIM';
        if (str_starts_with($lower, 'v=dmarc1')) return 'DMARC';
        if (str_contains($lower, 'google-site-verification')) return 'Google Verification';
        if (str_contains($lower, 'ms='))        return 'Microsoft Verification';
        if (str_contains($lower, 'facebook-domain-verification')) return 'Facebook Verification';
        if (str_contains($lower, 'apple-domain-verification')) return 'Apple Verification';
        if (str_contains($lower, 'amazonses:')) return 'Amazon SES';
        if (str_contains($lower, 'docusign'))   return 'DocuSign';
        if (str_contains($lower, 'atlassian-domain-verification')) return 'Atlassian Verification';
        if (str_contains($lower, '_mta-sts')) return 'MTA-STS';
        return 'TXT';
    }

    // ========================================================================
    // CLOUDFLARE DETECTION
    // ========================================================================

    public function isCloudflareIp(string $ip): bool
    {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (self::CLOUDFLARE_IPV4_RANGES as $cidr) {
            [$subnet, $mask] = explode('/', $cidr);
            $subnetLong = ip2long($subnet);
            $maskLong = ~((1 << (32 - (int)$mask)) - 1);

            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                return true;
            }
        }

        return false;
    }

    public function isCloudflareIpv6(string $ip): bool
    {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $ipBin = inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach (self::CLOUDFLARE_IPV6_RANGES as $cidr) {
            [$subnet, $prefixLen] = explode('/', $cidr);
            $subnetBin = inet_pton($subnet);
            if ($subnetBin === false) {
                continue;
            }

            $prefixLen = (int)$prefixLen;
            $fullBytes = intdiv($prefixLen, 8);
            $remainingBits = $prefixLen % 8;

            $match = true;
            for ($i = 0; $i < $fullBytes; $i++) {
                if ($ipBin[$i] !== $subnetBin[$i]) {
                    $match = false;
                    break;
                }
            }

            if ($match && $remainingBits > 0 && $fullBytes < 16) {
                $bitmask = 0xFF << (8 - $remainingBits) & 0xFF;
                if ((ord($ipBin[$fullBytes]) & $bitmask) !== (ord($subnetBin[$fullBytes]) & $bitmask)) {
                    $match = false;
                }
            }

            if ($match) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // IP DETAILS (PTR, ASN, GEO)
    // ========================================================================

    /**
     * Batch-lookup IP details (ASN, PTR, org, country) for a list of IPs.
     * PTR via gethostbyaddr(); ASN/geo via ip-api.com batch.
     */
    public function lookupIpDetails(array $ips): array
    {
        $unique = array_values(array_unique(array_filter($ips)));
        if (empty($unique)) {
            return [];
        }

        $result = [];

        foreach ($unique as $ip) {
            $ptr = @gethostbyaddr($ip);
            $result[$ip] = [
                'reverse' => ($ptr !== false && $ptr !== $ip) ? $ptr : '',
            ];
        }

        $requestBody = [];
        foreach ($unique as $ip) {
            $requestBody[] = [
                'query'  => $ip,
                'fields' => 'status,query,as,asname,isp,org,country,countryCode,regionName,city,hosting',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nUser-Agent: DomainMonitor/1.0",
                'content' => json_encode($requestBody),
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents('http://ip-api.com/batch', false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (($item['status'] ?? '') === 'success' && isset($item['query'])) {
                        $result[$item['query']] = array_merge($result[$item['query']] ?? [], $item);
                    }
                }
            }
        } else {
            $this->logger->warning('ip-api.com batch request failed');
        }

        return $result;
    }

    // ========================================================================
    // DIFF / NOTIFICATIONS
    // ========================================================================

    /**
     * Compare two sets of DNS records and return changes.
     */
    public function diffRecords(array $oldRecords, array $newRecords): array
    {
        $changes = ['added' => [], 'removed' => [], 'changed' => []];

        $oldFlat = $this->flattenRecords($oldRecords);
        $newFlat = $this->flattenRecords($newRecords);

        foreach ($newFlat as $key => $record) {
            if (!isset($oldFlat[$key])) {
                $changes['added'][] = $record;
            } elseif ($oldFlat[$key]['value'] !== $record['value']) {
                $changes['changed'][] = [
                    'record' => $record,
                    'old_value' => $oldFlat[$key]['value'],
                    'new_value' => $record['value'],
                ];
            }
        }

        foreach ($oldFlat as $key => $record) {
            if (!isset($newFlat[$key])) {
                $changes['removed'][] = $record;
            }
        }

        return $changes;
    }

    private function flattenRecords(array $grouped): array
    {
        $flat = [];
        foreach ($grouped as $type => $records) {
            foreach ($records as $record) {
                $host = $record['host'] ?? '@';
                $value = $record['value'] ?? '';
                $priority = $record['priority'] ?? '';
                $key = "{$type}:{$host}:{$value}:{$priority}";
                $flat[$key] = array_merge($record, ['record_type' => $type]);
            }
        }
        return $flat;
    }

    public function formatChangesSummary(array $changes, string $domain): string
    {
        $parts = [];

        if (!empty($changes['added'])) {
            $parts[] = count($changes['added']) . " new record(s) added";
        }
        if (!empty($changes['removed'])) {
            $parts[] = count($changes['removed']) . " record(s) removed";
        }
        if (!empty($changes['changed'])) {
            $parts[] = count($changes['changed']) . " record(s) changed";
        }

        return empty($parts) ? '' : "DNS changes detected for {$domain}: " . implode(', ', $parts);
    }

    public function formatChangesDetail(array $changes, string $domain): string
    {
        $lines = ["🔄 DNS Changes Detected: {$domain}\n"];

        if (!empty($changes['added'])) {
            $lines[] = "➕ New Records:";
            foreach ($changes['added'] as $r) {
                $type = $r['record_type'] ?? 'UNKNOWN';
                $lines[] = "  {$type} {$r['host']} → {$r['value']}";
            }
            $lines[] = '';
        }

        if (!empty($changes['removed'])) {
            $lines[] = "➖ Removed Records:";
            foreach ($changes['removed'] as $r) {
                $type = $r['record_type'] ?? 'UNKNOWN';
                $lines[] = "  {$type} {$r['host']} → {$r['value']}";
            }
            $lines[] = '';
        }

        if (!empty($changes['changed'])) {
            $lines[] = "✏️ Changed Records:";
            foreach ($changes['changed'] as $c) {
                $type = $c['record']['record_type'] ?? 'UNKNOWN';
                $lines[] = "  {$type} {$c['record']['host']}: {$c['old_value']} → {$c['new_value']}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
