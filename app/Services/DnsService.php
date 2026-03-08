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
    // MAIN LOOKUP
    // ========================================================================

    /**
     * Comprehensive DNS lookup for a domain.
     * Scans root + common subdomains + targets extracted from NS/MX/CNAME.
     * Resolves NS/MX targets to A/AAAA IPs.
     *
     * @param string $domain       The domain to scan
     * @param array  $extraSubdomains Additional subdomain candidates (e.g. from crt.sh or previous scans)
     */
    public function lookup(string $domain, array $extraSubdomains = []): array
    {
        $this->logger->info("DNS lookup started", ['domain' => $domain]);

        $records = [
            'A' => [], 'AAAA' => [], 'MX' => [], 'TXT' => [],
            'NS' => [], 'CNAME' => [], 'SOA' => [], 'SRV' => [], 'CAA' => [],
        ];
        $seen = []; // "TYPE:host:value" dedup keys

        // Phase 1: Root domain — query each type individually
        foreach (self::ROOT_RECORD_TYPES as $dnsConst => $typeName) {
            $this->queryAndCollect($domain, $dnsConst, $typeName, $domain, $records, $seen);
        }

        // Phase 1b: DNS_ALL fallback to catch anything we missed
        $this->queryAllFallback($domain, $domain, $records, $seen);

        // Phase 1c: gethostbynamel fallback for A records
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

        // Phase 2: Build subdomain candidates from wordlist + extras + targets found in NS/MX/CNAME/SRV
        $candidates = array_merge(self::SUBDOMAIN_WORDLIST, $extraSubdomains);
        foreach (['NS', 'MX', 'CNAME', 'SRV'] as $type) {
            foreach ($records[$type] as $rec) {
                $target = rtrim($rec['value'] ?? '', '.');
                if ($target && str_ends_with(strtolower($target), '.' . strtolower($domain))) {
                    $sub = str_replace('.' . $domain, '', strtolower($target));
                    if ($sub && !in_array($sub, $candidates)) {
                        $candidates[] = $sub;
                    }
                }
            }
        }
        $candidates = array_unique($candidates);

        // Phase 3: Probe subdomains — fast checkdnsrr existence test first
        $discovered = [];
        foreach ($candidates as $sub) {
            $fqdn = "{$sub}.{$domain}";
            if ($this->subdomainExists($fqdn)) {
                $discovered[] = $sub;
            }
        }

        // Phase 4: Deep scan discovered subdomains (A, AAAA, CNAME, TXT)
        foreach ($discovered as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_A, 'A', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            $this->queryAndCollect($fqdn, DNS_CNAME, 'CNAME', $domain, $records, $seen);
            // TXT only for known useful subdomains
            if (in_array($sub, ['_dmarc', '_mta-sts', '_domainkey']) || str_starts_with($sub, '_')) {
                $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
            }
        }

        // Phase 4b: Special TXT subdomains (always query even if not "discovered")
        foreach (self::SPECIAL_TXT_SUBDOMAINS as $sub) {
            $fqdn = "{$sub}.{$domain}";
            $this->queryAndCollect($fqdn, DNS_TXT, 'TXT', $domain, $records, $seen);
        }

        // Phase 5: Resolve MX targets that are under this domain — add their A/AAAA records
        foreach ($records['MX'] as $mxRec) {
            $target = rtrim($mxRec['value'] ?? '', '.');
            if ($target && str_ends_with(strtolower($target), '.' . strtolower($domain))) {
                $this->queryAndCollect($target, DNS_A, 'A', $domain, $records, $seen);
                $this->queryAndCollect($target, DNS_AAAA, 'AAAA', $domain, $records, $seen);
            }
        }

        // Phase 6: Resolve NS server IPs — store in raw data for display
        foreach ($records['NS'] as &$nsRec) {
            $nsHost = rtrim($nsRec['value'] ?? '', '.');
            if ($nsHost) {
                $nsIps = $this->resolveHostIps($nsHost);
                $nsRec['raw']['_ns_ips'] = $nsIps;
            }
        }
        unset($nsRec);

        // Sort A/AAAA: root first, then alphabetical
        foreach (['A', 'AAAA'] as $type) {
            usort($records[$type], function ($a, $b) {
                if ($a['host'] === '@') return -1;
                if ($b['host'] === '@') return 1;
                return strcmp($a['host'], $b['host']);
            });
        }

        $totalRecords = array_sum(array_map('count', $records));
        $this->logger->info("DNS lookup completed", [
            'domain'        => $domain,
            'total_records' => $totalRecords,
            'subdomains_discovered' => count($discovered),
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
    // CERTIFICATE TRANSPARENCY (crt.sh)
    // ========================================================================

    /**
     * Discover subdomains via crt.sh Certificate Transparency logs.
     * Returns an array of subdomain labels (e.g. ['www', 'mail', 'api']).
     * Slow/unreliable — use only in cron, not on manual refresh.
     */
    public function crtshSubdomains(string $domain): array
    {
        $url = 'https://crt.sh/?q=' . urlencode("%.$domain") . '&output=json';

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 30,
                'ignore_errors' => true,
                'header'        => "User-Agent: DomainMonitor/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) {
            $this->logger->warning('crt.sh request failed', ['domain' => $domain]);
            return [];
        }

        $entries = @json_decode($json, true);
        if (!is_array($entries)) {
            return [];
        }

        $subdomains = [];
        $domainLower = strtolower($domain);

        foreach ($entries as $entry) {
            $name = $entry['name_value'] ?? '';
            foreach (explode("\n", $name) as $n) {
                $n = strtolower(trim($n));
                $n = ltrim($n, '*.');
                if (empty($n)) continue;

                if ($n === $domainLower) continue;

                if (str_ends_with($n, '.' . $domainLower)) {
                    $sub = str_replace('.' . $domainLower, '', $n);
                    if ($sub !== '' && !isset($subdomains[$sub])) {
                        $subdomains[$sub] = true;
                    }
                }
            }
        }

        $result = array_keys($subdomains);
        $this->logger->info('crt.sh discovery completed', [
            'domain' => $domain,
            'subdomains_found' => count($result),
        ]);

        return $result;
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
