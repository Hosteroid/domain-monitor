<?php

namespace App\Services;

use App\Services\CtLogs\CertSpotterSource;
use App\Services\CtLogs\CrtshSource;
use App\Services\CtLogs\CtLogSourceInterface;

class DnsService
{
    /** Certificate transparency sources a deep scan can ask, by the id stored in ct_log_sources */
    public const CT_LOG_SOURCES = [
        'crtsh' => 'crt.sh',
        'certspotter' => 'Cert Spotter',
    ];

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

    // CAA is added in queryRootDomain() when available: Windows builds of PHP do not define DNS_CAA
    private const ROOT_RECORD_TYPES = [
        DNS_A     => 'A',
        DNS_AAAA  => 'AAAA',
        DNS_MX    => 'MX',
        DNS_TXT   => 'TXT',
        DNS_NS    => 'NS',
        DNS_CNAME => 'CNAME',
        DNS_SOA   => 'SOA',
        DNS_SRV   => 'SRV',
    ];

    public function __construct()
    {
        $this->logger = new Logger('dns');
    }

    // trailing dot: an absolute name is never retried with the resolver's search suffixes
    private static function absolute(string $name): string
    {
        return rtrim($name, '.') . '.';
    }

    // ========================================================================
    // DNS SCAN METHODS
    // ========================================================================

    /**
     * Re-check only records that already exist in the database.
     * Queries root domain for all types + known subdomain hosts.
     * No wordlist brute force, no certificate logs. Used by the cron and Refresh button.
     */
    public function refreshExisting(string $domain, array $existingHosts = []): array
    {
        $this->logger->info("DNS refresh started", ['domain' => $domain, 'known_hosts' => count($existingHosts)]);

        [$records, $seen] = $this->queryRootDomain($domain);
        $wildcard = $this->probeWildcard($domain);
        $this->mergeRecords($wildcard, $records, $seen);

        // Query known subdomain hosts directly (no existence probe needed)
        $wildcardHosts = 0;
        foreach ($existingHosts as $sub) {
            if ($sub === '*') {
                continue; // listed from the wildcard probe
            }
            if (!$this->collectHost($sub, $domain, $wildcard, $records, $seen)) {
                $wildcardHosts++;
            }
        }

        // Known hosts above already covered these when they exist
        foreach (array_diff(self::SPECIAL_TXT_SUBDOMAINS, $existingHosts) as $sub) {
            $this->collectHost($sub, $domain, $wildcard, $records, $seen, [DNS_TXT => 'TXT']);
        }

        $this->resolveMxTargets($domain, $records, $seen);
        $this->resolveNsIps($records);
        $this->sortRecords($records);

        $totalRecords = array_sum(array_map('count', $records));
        $this->logger->info("DNS refresh completed", [
            'domain'         => $domain,
            'total_records'  => $totalRecords,
            'wildcard_hosts' => $wildcardHosts,
        ]);

        return $records;
    }

    /**
     * Standard DNS lookup: root domain + resolve targets + special TXT.
     * No subdomain brute force, no certificate logs. Like running nslookup/dig.
     * Used by Discover > Quick Scan.
     *
     * @param array $existingHosts Hosts already saved for the domain. They are asked again: what a scan
     *                             returns replaces the domain's looked-up records, so a host left out
     *                             here would lose its records.
     */
    public function quickScan(string $domain, array $existingHosts = []): array
    {
        $this->logger->info("DNS quick scan started", ['domain' => $domain, 'known_hosts' => count($existingHosts)]);

        [$records, $seen] = $this->queryRootDomain($domain);
        $wildcard = $this->probeWildcard($domain);
        $this->mergeRecords($wildcard, $records, $seen);

        // Subdomains found as NS/MX/CNAME/SRV targets, and the known hosts ("*" is listed from the wildcard probe)
        $subs = array_diff(
            array_unique(array_merge($this->extractTargetSubdomains($domain, $records), $existingHosts)),
            ['*', '@']
        );
        foreach ($subs as $sub) {
            $this->collectHost($sub, $domain, $wildcard, $records, $seen);
        }

        // The hosts above already covered these when they exist
        foreach (array_diff(self::SPECIAL_TXT_SUBDOMAINS, $subs) as $sub) {
            $this->collectHost($sub, $domain, $wildcard, $records, $seen, [DNS_TXT => 'TXT']);
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
     * Full discovery: root + wordlist brute force + certificate log extras + wildcard detection.
     * Used by Discover > Deep Scan and the discover_dns.php script.
     *
     * @param string        $domain          The domain to scan
     * @param array         $extraSubdomains Additional candidates (e.g. from certificate logs)
     * @param callable|null $onProgress      Optional callback for progress messages: fn(string $msg)
     * @param array         $knownHosts      Hosts already saved for the domain. They are asked for their
     *                                       records without the existence probe, which only looks for A,
     *                                       AAAA and CNAME: a host with TXT or SRV records only would
     *                                       fail it and lose its records.
     */
    public function lookup(string $domain, array $extraSubdomains = [], ?callable $onProgress = null, array $knownHosts = []): array
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
        $log("Wildcard detection: probing random subdomain...");
        $wildcard = $this->probeWildcard($domain);
        $wildcardDetected = !empty($wildcard);
        if ($wildcardDetected) {
            $this->logger->info("Wildcard DNS detected, skipping brute force", ['domain' => $domain]);
            $log("⚠ Wildcard DNS detected — brute force skipped, using only certificate log/known hosts");
            $this->mergeRecords($wildcard, $records, $seen);
            // Only use certificate log/extra candidates + DB hosts (real subdomains), not wordlist
            $candidates = array_values(array_unique(array_diff($extraSubdomains, ['*'])));
        } else {
            $log("No wildcard detected");
        }

        // Known hosts skip the probe ("*" is listed from the wildcard probe)
        $knownHosts = array_values(array_diff(array_unique($knownHosts), ['*', '@']));
        $candidates = array_values(array_diff($candidates, $knownHosts));

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

        // Deep scan discovered subdomains
        if (!empty($discovered)) {
            $log("Querying " . count($discovered) . " discovered subdomain(s)...");
        }
        if (!empty($knownHosts)) {
            $log("Querying " . count($knownHosts) . " known host(s)...");
        }
        $wildcardHosts = 0;
        foreach (array_merge($knownHosts, $discovered) as $sub) {
            if (!$this->collectHost($sub, $domain, $wildcard, $records, $seen)) {
                $wildcardHosts++;
            }
        }
        if ($wildcardHosts > 0) {
            $log("{$wildcardHosts} subdomain(s) only answer through the wildcard, not listed");
        }

        $log("Querying special TXT subdomains...");
        foreach (self::SPECIAL_TXT_SUBDOMAINS as $sub) {
            $this->collectHost($sub, $domain, $wildcard, $records, $seen, [DNS_TXT => 'TXT']);
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
            $raw = @dns_get_record(self::absolute($fqdn), $dnsConst);
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
            $all = @dns_get_record(self::absolute($fqdn), DNS_ALL);
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
     * Add records collected for one host to the domain's records (dedup).
     */
    private function mergeRecords(array $hostRecords, array &$records, array &$seen): void
    {
        foreach ($hostRecords as $type => $typeRecords) {
            foreach ($typeRecords as $record) {
                $this->addIfNew($type, $record, $records, $seen);
            }
        }
    }

    /**
     * Check the zone for a wildcard record: what a random, unused name gets back, listed as host
     * "*" (the literal "*" can't be queried on every platform). Empty when there is no wildcard.
     *
     * @return array Records by type
     */
    private function probeWildcard(string $domain): array
    {
        $probe = 'dm-wc-' . bin2hex(random_bytes(4)) . '.' . $domain;
        $records = [];
        $seen = [];
        foreach ([DNS_A => 'A', DNS_AAAA => 'AAAA', DNS_CNAME => 'CNAME', DNS_MX => 'MX', DNS_TXT => 'TXT'] as $dnsConst => $typeName) {
            $this->queryAndCollect($probe, $dnsConst, $typeName, $domain, $records, $seen);
        }

        foreach ($records as &$typeRecords) {
            foreach ($typeRecords as &$record) {
                $record['host'] = '*';
                $record['raw']['host'] = "*.{$domain}";
            }
            unset($record);
        }
        unset($typeRecords);

        return $records;
    }

    /**
     * Query one subdomain and add its records, unless they are just the zone's wildcard answering
     * for it: a wildcard answers for every name, so such a host has no records of its own (#76).
     *
     * @param array      $wildcard probeWildcard() result
     * @param array|null $types    Record types to ask for (DNS_* => name); by default the ones the host can hold
     * @return bool False when the host only answers through the wildcard (nothing is added)
     */
    private function collectHost(
        string $sub, string $domain, array $wildcard,
        array &$records, array &$seen, ?array $types = null
    ): bool {
        // Underscore names hold TXT, SRV or a CNAME (e.g. delegated _acme-challenge), never A/AAAA/MX
        $types ??= str_starts_with($sub, '_')
            ? [DNS_CNAME => 'CNAME', DNS_TXT => 'TXT', DNS_SRV => 'SRV']
            : [DNS_A => 'A', DNS_AAAA => 'AAAA', DNS_CNAME => 'CNAME', DNS_MX => 'MX', DNS_TXT => 'TXT'];

        $hostRecords = [];
        $hostSeen = [];
        foreach ($types as $dnsConst => $typeName) {
            $this->queryAndCollect("{$sub}.{$domain}", $dnsConst, $typeName, $domain, $hostRecords, $hostSeen);
        }

        if (!empty($wildcard) && !empty($hostRecords)) {
            $sameAsWildcard = true;
            foreach ($types as $typeName) {
                if ($this->recordValues($hostRecords[$typeName] ?? []) !== $this->recordValues($wildcard[$typeName] ?? [])) {
                    $sameAsWildcard = false;
                    break;
                }
            }
            if ($sameAsWildcard) {
                return false;
            }
        }

        $this->mergeRecords($hostRecords, $records, $seen);
        return true;
    }

    /**
     * Values of one type of a host's records, for comparing hosts
     */
    private function recordValues(array $records): array
    {
        $values = array_map(
            static fn(array $record): string => $record['value'] . '|' . ($record['priority'] ?? ''),
            $records
        );
        sort($values);
        return $values;
    }

    /**
     * Fast existence check for a subdomain.
     */
    private function subdomainExists(string $fqdn): bool
    {
        $name = self::absolute($fqdn);
        if (@checkdnsrr($name, 'A'))     return true;
        if (@checkdnsrr($name, 'AAAA'))  return true;
        if (@checkdnsrr($name, 'CNAME')) return true;
        $ip = @gethostbyname($name);
        return ($ip !== $name);
    }

    /**
     * Resolve a hostname to its A and AAAA IPs.
     */
    private function resolveHostIps(string $hostname): array
    {
        $ips = ['ipv4' => [], 'ipv6' => []];

        $a = @dns_get_record(self::absolute($hostname), DNS_A);
        if (is_array($a)) {
            foreach ($a as $r) {
                if (!empty($r['ip'])) {
                    $ips['ipv4'][] = $r['ip'];
                }
            }
        }

        $aaaa = @dns_get_record(self::absolute($hostname), DNS_AAAA);
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

        $recordTypes = self::ROOT_RECORD_TYPES;
        if (defined('DNS_CAA')) {
            $recordTypes[DNS_CAA] = 'CAA';
        }

        foreach ($recordTypes as $dnsConst => $typeName) {
            $this->queryAndCollect($domain, $dnsConst, $typeName, $domain, $records, $seen);
        }

        if (array_sum(array_map('count', $records)) === 0) {
            $this->queryAllFallback($domain, $domain, $records, $seen);
        }

        if (empty($records['A'])) {
            $ips = @gethostbynamel(self::absolute($domain));
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
     * Sort records: root (@) first, then alphabetical by host.
     */
    private function sortRecords(array &$records): void
    {
        foreach (['A', 'AAAA', 'MX', 'TXT', 'CAA'] as $type) {
            if (empty($records[$type])) {
                continue;
            }
            usort($records[$type], [self::class, 'compareByHost']);
        }
    }

    /**
     * Order two records: root (@) first, then by host, then by priority
     */
    private static function compareByHost(array $a, array $b): int
    {
        $hostA = (string)($a['host'] ?? '@');
        $hostB = (string)($b['host'] ?? '@');
        if ($hostA === '@' && $hostB !== '@') return -1;
        if ($hostB === '@' && $hostA !== '@') return 1;
        $hostCmp = strcmp($hostA, $hostB);
        if ($hostCmp !== 0) return $hostCmp;
        return ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0);
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
     *   @  IN  SOA  ns1.example.com. admin.example.com. (
     *               2024010101 7200 900 1209600 300 )
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

        for ($i = 0, $lineCount = count($lines); $i < $lineCount; $i++) {
            $line = $lines[$i];
            // A line that starts with whitespace leaves its name out and takes the previous line's
            $indented = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
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

            $line = self::stripZoneComment($line);

            // Standard BIND format: [name] [ttl] [class] type rdata
            $tokens = preg_split('/\s+/', $line);
            if (count($tokens) < 3) {
                continue;
            }

            $fields = self::readZoneLine($tokens, $indented, $supportedTypes);
            if ($fields === null) {
                continue;
            }

            [$host, $ttl, $type, $rdataStart] = $fields;
            $ttl ??= $defaultTtl;

            // The record's data as written: splitting it on whitespace loses the spaces inside quotes.
            // In parentheses it goes on over the next lines, which are then read with this one.
            $data = preg_split('/\s+/', $line, $rdataStart + 1)[$rdataStart] ?? '';
            [$data, $i] = self::readZoneGroup($type, $data, $lines, $i);
            if ($data === '') {
                continue;
            }
            $rdata = preg_split('/\s+/', $data);

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
                    // "." alone is the root, as in "MX 0 ." (the host takes no mail): it stays as it is
                    $target = ($rdata[1] ?? '') === '.' ? '.' : rtrim($rdata[1] ?? '', '.');
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
                    $txtValue = self::parseZoneText($data);
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
                        $target = $rdata[3] === '.' ? '.' : rtrim($rdata[3], '.');
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
                            'host' => $host, 'value' => rtrim($rdata[0], '.'), 'ttl' => $ttl,
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

    /**
     * Find the name, TTL and type on a zone file line: "[name] [ttl] [class] type data".
     *
     * A line may leave its name out and take the previous line's. The standard marks that by
     * starting the line with whitespace, but pasted zone text is indented or not at random, so the
     * line is read both ways first, with and without a name, and each reading has to find a record
     * type in its place. When both do, the one whose data fits its type is taken:
     * "mx IN A 192.0.2.1" is an address for the host mx, not a mail server called IN. Only when
     * that leaves both ("123 IN A 192.0.2.1": a host named 123, or a TTL of 123) does the
     * indentation decide, as the standard says.
     *
     * @param string[] $tokens   The line split on whitespace
     * @param bool     $indented Whether the line started with whitespace
     * @param string[] $types    Record types the importer reads
     * @return array{0: ?string, 1: ?int, 2: string, 3: int}|null Name (null when left out), TTL (null
     *         when left out), type and the index of the first data token; null when there is no record
     */
    private static function readZoneLine(array $tokens, bool $indented, array $types): ?array
    {
        $named = self::readZoneFields($tokens, 1, $types);
        $unnamed = self::readZoneFields($tokens, 0, $types);

        if ($named !== null && $unnamed !== null) {
            $namedFits = self::zoneDataFits($named[1], array_slice($tokens, $named[2]), $types);
            $unnamedFits = self::zoneDataFits($unnamed[1], array_slice($tokens, $unnamed[2]), $types);

            // A line that starts with "IN" was always read as starting with its class
            $hasName = $namedFits !== $unnamedFits
                ? $namedFits
                : !$indented && strtoupper($tokens[0]) !== 'IN';
        } else {
            $hasName = $named !== null;
        }

        $fields = $hasName ? $named : $unnamed;

        return $fields === null ? null : [$hasName ? $tokens[0] : null, ...$fields];
    }

    /**
     * Read "[ttl] [class] type data" from one position of a line.
     *
     * @return array{0: ?int, 1: string, 2: int}|null TTL (null when left out), type and the index of
     *         the first data token; null when no record type the importer reads is there, or no data
     */
    private static function readZoneFields(array $tokens, int $idx, array $types): ?array
    {
        $ttl = null;
        if (isset($tokens[$idx]) && ctype_digit($tokens[$idx])) {
            $ttl = (int)$tokens[$idx];
            $idx++;
        }

        if (isset($tokens[$idx]) && strtoupper($tokens[$idx]) === 'IN') {
            $idx++;
        }

        $type = strtoupper($tokens[$idx] ?? '');
        if (!in_array($type, $types, true) || !isset($tokens[$idx + 1])) {
            return null;
        }

        return [$ttl, $type, $idx + 1];
    }

    /**
     * Whether a record's data looks like what its type holds. It only helps choose between two
     * readings of a line: a record is never left out because its data looks wrong.
     */
    private static function zoneDataFits(string $type, array $rdata, array $types): bool
    {
        $count = count($rdata);
        $isNumber = static fn(int $i): bool => isset($rdata[$i]) && ctype_digit($rdata[$i]);

        switch ($type) {
            case 'A':
                return $count === 1 && filter_var($rdata[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            case 'AAAA':
                return $count === 1 && filter_var($rdata[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            case 'NS':
            case 'CNAME':
                return $count === 1;
            case 'MX':
                return $count === 2 && $isNumber(0);
            case 'SRV':
                return $count === 4 && $isNumber(0) && $isNumber(1) && $isNumber(2);
            case 'CAA':
                return $count >= 3 && $isNumber(0);
            case 'SOA':
                return $count >= 7 && $isNumber(2) && $isNumber(3) && $isNumber(4) && $isNumber(5) && $isNumber(6);
            case 'TXT':
                // Text can be anything, but it hardly starts with what a line has before its type
                $first = strtoupper($rdata[0]);
                return $first !== 'IN' && !in_array($first, $types, true);
        }

        return false;
    }

    /** Lines a record may go on for inside parentheses: an SOA takes six, a long DKIM key a few more */
    private const ZONE_GROUP_MAX_LINES = 30;

    /**
     * Put a record that goes on over several lines back together. Inside parentheses a line break
     * does not end the record: BIND and hosting panels write SOA records that way, and opendkim
     * its DKIM keys.
     *
     * Parentheses inside quotes are text. In a TXT record they only group when the data starts
     * with one, so text typed without quotes keeps its parentheses. Parentheses that are never
     * closed are not a group: the line is then read on its own, as it always was.
     *
     * @param string   $data  The record's data on its first line
     * @param string[] $lines The file's lines
     * @param int      $i     Index of the line the record starts on
     * @return array{0: string, 1: int} The data without its grouping parentheses, and the index of
     *         the last line it took
     */
    private static function readZoneGroup(string $type, string $data, array $lines, int $i): array
    {
        if (!str_contains($data, '(') || ($type === 'TXT' && $data[0] !== '(')) {
            return [$data, $i];
        }

        $joined = '';
        $depth = 0;
        $last = min(count($lines) - 1, $i + self::ZONE_GROUP_MAX_LINES);

        for ($j = $i; $j <= $last; $j++) {
            // A comment ends with its line, so it is cut off before the lines are put together
            $part = $j === $i ? $data : self::stripZoneComment(trim($lines[$j]));

            $inQuotes = false;
            for ($k = 0, $length = strlen($part); $k < $length; $k++) {
                $char = $part[$k];
                if ($char === '\\') {
                    $k++; // the next character is escaped
                } elseif ($char === '"') {
                    $inQuotes = !$inQuotes;
                } elseif (!$inQuotes && ($char === '(' || $char === ')')) {
                    $depth += $char === '(' ? 1 : -1;
                    $part[$k] = ' ';
                }
            }

            $joined .= ' ' . $part;
            if ($depth <= 0) {
                return [trim($joined), $j];
            }
        }

        return [$data, $i];
    }

    /**
     * Cut the comment off a zone file line: from a ";" that follows whitespace and is not inside
     * quotes. What comes after it may hold quotes of its own, as in Cloudflare's
     * `; cf_tags=team:"web"`. A ";" right after other text stays, so an unquoted
     * "v=DMARC1; p=none" is still read whole.
     */
    private static function stripZoneComment(string $line): string
    {
        $inQuotes = false;
        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '\\') {
                $i++; // the next character is escaped
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === ';' && !$inQuotes && ($i === 0 || ctype_space($line[$i - 1]))) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return $line;
    }

    /**
     * Read the text of a TXT record from a zone file line. A long text is written as several quoted
     * strings ("part one" "part two"), which are one text put together, with \" \\ and \DDD escapes.
     * Text without quotes is taken as written.
     */
    private static function parseZoneText(string $rdata): string
    {
        $rdata = trim($rdata);
        if ($rdata === '' || $rdata[0] !== '"') {
            return trim(preg_replace('/\s+/', ' ', $rdata), '"');
        }

        $text = '';
        $length = strlen($rdata);
        $i = 0;
        while ($i < $length) {
            if ($rdata[$i] !== '"') {
                $i++; // whitespace between the strings
                continue;
            }

            for ($i++; $i < $length && $rdata[$i] !== '"'; $i++) {
                if ($rdata[$i] !== '\\' || $i + 1 >= $length) {
                    $text .= $rdata[$i];
                } elseif (preg_match('/\G\d{3}/', $rdata, $code, 0, $i + 1)) {
                    $text .= chr((int)$code[0]);
                    $i += 3;
                } else {
                    $text .= $rdata[++$i];
                }
            }
            $i++; // the closing quote
        }

        return $text;
    }

    // ========================================================================
    // BIND ZONE FILE WRITER
    // ========================================================================

    /** Record types in the order a zone file lists them */
    private const ZONE_TYPE_ORDER = ['SOA', 'NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    /**
     * Write records as a BIND zone file: what parseBindZone() reads, the other way round.
     *
     * Every name is written in full with a trailing dot and every record on one line, which
     * parseBindZone(), BIND and DNS providers' importers all read the same way. Nothing follows a
     * record on its line: Cloudflare imports a comment there as that record's comment.
     *
     * @param string   $domain  The zone's domain name
     * @param array    $records Records by type, as DnsRecord::getPreviousSnapshot() returns them
     * @param string[] $notes   Lines for the comment at the top, e.g. when the records are from
     */
    public function buildBindZone(string $domain, array $records, array $notes = []): string
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        $body = [];
        $skipped = 0;
        $proxied = 0;

        foreach (self::ZONE_TYPE_ORDER as $type) {
            $typeRecords = $records[$type] ?? [];
            usort($typeRecords, [self::class, 'compareByHost']);

            $lines = [];
            foreach ($typeRecords as $record) {
                $owner = self::zoneOwner((string)($record['host'] ?? '@'), $domain);
                $rdata = $owner === null ? null : self::zoneRdata($type, $record);
                if ($rdata === null) {
                    $skipped++;
                    continue;
                }

                $discovered = ($record['source'] ?? 'discovered') === 'discovered';
                // A looked-up TTL is what the resolver's cache had left of it, not the zone's own
                $ttl = !$discovered && (int)($record['ttl'] ?? 0) > 0 ? (int)$record['ttl'] . "\t" : '';
                $lines[] = "{$owner}\t{$ttl}IN\t{$type}\t{$rdata}";
                $proxied += !empty($record['is_cloudflare']) ? 1 : 0;
            }

            if (!empty($lines)) {
                array_push($body, '', "; {$type} records", ...$lines);
            }
        }

        $header = ["; Zone file for {$domain}"];
        foreach ($notes as $note) {
            $header[] = '; ' . trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$note));
        }
        $header[] = ';';
        $header[] = '; Written by Domain Monitor from DNS lookups, not from your DNS provider: records the';
        $header[] = '; lookups never found are not in it. Looked-up records are written without a TTL of';
        $header[] = '; their own, so the $TTL below applies to them.';
        if ($proxied > 0) {
            $header[] = "; {$proxied} address record(s) point to Cloudflare's proxy, not to your own server.";
        }
        if ($skipped > 0) {
            $header[] = "; {$skipped} record(s) could not be written as a zone file line and were left out.";
        }
        $header[] = "\$ORIGIN {$domain}.";
        $header[] = '$TTL 3600';

        return implode("\n", array_merge($header, $body)) . "\n";
    }

    /**
     * A record's host as a full name with a trailing dot, null when it can't be a name in a zone file.
     * Written in full so no reader has to tell a host from a record type or a TTL (hosts "mx", "123").
     */
    private static function zoneOwner(string $host, string $domain): ?string
    {
        $host = rtrim(trim($host), '.');
        if ($host === '@' || $host === '') {
            return "{$domain}.";
        }

        return self::isZoneName($host) ? "{$host}.{$domain}." : null;
    }

    /**
     * A record's target (CNAME, MX, NS, SRV, SOA) as a zone file writes it, null when it is not a name.
     * A looked-up name is always complete and gets its trailing dot. One typed or imported without
     * a dot in it may be meant inside the zone ("mail" for mail.example.com), which is how a zone
     * file reads it as written.
     */
    private static function zoneTarget(string $target, bool $complete): ?string
    {
        $target = trim($target);
        if ($target === '.') {
            return '.'; // the root: "MX 0 ." says the host takes no mail, "SRV 0 0 0 ." that there is no such service
        }

        $name = rtrim($target, '.');
        if ($name === '' || !self::isZoneName($name)) {
            return null;
        }

        return $complete || str_contains($name, '.') || str_ends_with($target, '.') ? "{$name}." : $name;
    }

    /**
     * Whether a name can be written into a zone file as it is: no empty labels, and none of the
     * characters a zone file gives a meaning to
     */
    private static function isZoneName(string $name): bool
    {
        return !str_contains($name, '..') && !str_starts_with($name, '.') && preg_match('/^[^\s"();\\\\@$\x00-\x1F\x7F]+$/', $name) === 1;
    }

    /**
     * What follows the type on a record's zone file line, null when the record can't be written
     */
    private static function zoneRdata(string $type, array $record): ?string
    {
        $value = trim((string)($record['value'] ?? ''));
        $discovered = ($record['source'] ?? 'discovered') === 'discovered';
        $priority = (int)($record['priority'] ?? 0);

        switch ($type) {
            case 'A':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $value : null;

            case 'AAAA':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $value : null;

            case 'NS':
            case 'CNAME':
                return self::zoneTarget($value, $discovered);

            case 'MX':
                $target = self::zoneTarget($value, $discovered);
                return $target === null ? null : "{$priority} {$target}";

            case 'TXT':
                return self::zoneText((string)($record['value'] ?? ''));

            case 'SRV':
                $weight = $record['weight'] ?? null;
                $port = $record['port'] ?? null;
                if ($weight === null || $port === null) {
                    // Added by hand, where the value is typed as "weight port target"
                    if (!preg_match('/^(\d+)\s+(\d+)\s+(\S+)$/', $value, $typed)) {
                        return null;
                    }
                    [, $weight, $port, $value] = $typed;
                }
                $target = self::zoneTarget($value, $discovered);
                return $target === null ? null : "{$priority} " . (int)$weight . ' ' . (int)$port . " {$target}";

            case 'CAA':
                // Stored as: flags tag "value"
                if (!preg_match('/^(\d+)\s+([A-Za-z0-9]+)\s+(.+)$/s', $value, $caa)) {
                    return null;
                }
                return (int)$caa[1] . " {$caa[2]} " . self::zoneText(trim($caa[3], '"'), false);

            case 'SOA':
                $mname = self::zoneTarget($value, true);
                $rname = self::zoneTarget((string)($record['rname'] ?? ''), true);
                $numbers = [];
                foreach (['serial', 'refresh', 'retry', 'expire', 'minimum'] as $field) {
                    if (!isset($record[$field])) {
                        return null; // added by hand: only its primary nameserver is known
                    }
                    $numbers[] = (int)$record[$field];
                }
                return $mname === null || $rname === null ? null : "{$mname} {$rname} " . implode(' ', $numbers);
        }

        return null;
    }

    /**
     * Text as a zone file writes it: in quotes, with quotes, backslashes and control characters
     * escaped. DNS holds text in strings of up to 255 bytes, so a longer one (a DKIM key) is
     * written as several strings, which readers put back together.
     */
    private static function zoneText(string $text, bool $split = true): string
    {
        $utf8 = mb_check_encoding($text, 'UTF-8');

        $chunks = [];
        for ($offset = 0, $length = strlen($text); $offset < $length; $offset += strlen($chunk)) {
            // mb_strcut() cuts between characters, never inside one
            $chunk = !$split ? substr($text, $offset) : ($utf8 ? mb_strcut($text, $offset, 255, 'UTF-8') : substr($text, $offset, 255));
            if ($chunk === '') {
                break;
            }
            $chunks[] = $chunk;
        }

        // Bytes that are not valid UTF-8 are escaped too, so the file itself always is
        $escape = '/[\x00-\x1F\x7F"\\\\' . ($utf8 ? '' : '\x80-\xFF') . ']/';
        $quote = static fn(string $chunk): string => '"' . preg_replace_callback(
            $escape,
            static fn(array $m): string => $m[0] === '"' || $m[0] === '\\' ? '\\' . $m[0] : sprintf('\\%03d', ord($m[0])),
            $chunk
        ) . '"';

        return empty($chunks) ? '""' : implode(' ', array_map($quote, $chunks));
    }

    // ========================================================================
    // CERTIFICATE TRANSPARENCY (crt.sh, Cert Spotter)
    // ========================================================================

    /**
     * Build the certificate transparency source with this id, or null for an unknown id
     */
    public function createCtLogSource(string $id): ?CtLogSourceInterface
    {
        return match ($id) {
            'crtsh' => new CrtshSource(),
            'certspotter' => new CertSpotterSource((new \App\Models\Setting())->getCertSpotterApiKey()),
            default => null,
        };
    }

    /**
     * Discover subdomains in one certificate transparency source.
     *
     * Spawns check_dns.php --ctlog as a subprocess with a hard timeout to
     * protect against a source that hangs (crt.sh can). The subprocess handles HTTP
     * retries and streams debug output to stderr, relayed via the $onStderrLine callback.
     *
     * @param  string        $sourceId        Key of self::CT_LOG_SOURCES
     * @param  string        $domain          The domain to scan
     * @param  int           $maxSubdomains   Cap on returned subdomains (0 = no limit)
     * @param  int           $timeoutSeconds  Hard kill timeout for the subprocess
     * @param  callable|null $onStderrLine    fn(string $line) for real-time stderr relay
     * @return array{subs: string[], ok: bool, retry_after: ?int}
     *         ok: the source answered; retry_after: seconds to leave it alone after a rate limit
     */
    public function fetchCtLogSubdomains(
        string $sourceId,
        string $domain,
        int $maxSubdomains = 100,
        int $timeoutSeconds = 1800,
        ?callable $onStderrLine = null
    ): array {
        $failed     = ['subs' => [], 'ok' => false, 'retry_after' => null];
        $phpBin     = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $scriptPath = dirname(__DIR__, 2) . '/cron/check_dns.php';
        $cmd        = [$phpBin, $scriptPath, '--ctlog', $sourceId, $domain];

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
            $this->logger->error('Failed to spawn certificate log subprocess', ['source' => $sourceId, 'domain' => $domain]);
            return $failed;
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
                $this->flushStderrLines($stderrBuffer, $onStderrLine);
                proc_terminate($proc, 9);
                proc_close($proc);
                $this->logger->warning("Certificate log subprocess killed after {$elapsed}s", ['source' => $sourceId, 'domain' => $domain]);
                return $failed;
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
                        $this->flushStderrLines($stderrBuffer, $onStderrLine);
                    }
                }
            }
            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderrBuffer .= stream_get_contents($pipes[2]);
        $this->flushStderrLines($stderrBuffer, $onStderrLine);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return $failed;
        }

        $result = [
            'subs'        => array_map('strval', (array)($decoded['subs'] ?? [])),
            'ok'          => !empty($decoded['ok']),
            'retry_after' => isset($decoded['retry_after']) ? (int)$decoded['retry_after'] : null,
        ];

        $this->logger->info('Certificate log discovery completed', [
            'source'           => $sourceId,
            'domain'           => $domain,
            'subdomains_found' => count($result['subs']),
            'server_ok'        => $result['ok'],
        ]);

        return $result;
    }

    /**
     * Merge the subdomain lists of several sources, taking one name from each in turn,
     * so a capped result still holds names from every source.
     *
     * @param  string[][] $lists
     * @param  int        $max   Cap on the merged list (0 = no limit)
     * @return string[]
     */
    public static function mergeSubdomainLists(array $lists, int $max = 0): array
    {
        $lists = array_map('array_values', $lists);
        $longest = $lists ? max(array_map('count', $lists)) : 0;
        $merged = [];

        for ($i = 0; $i < $longest; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i])) {
                    $merged[(string)$list[$i]] = true;
                }
            }
        }

        $merged = array_map('strval', array_keys($merged));
        return $max > 0 ? array_slice($merged, 0, $max) : $merged;
    }

    /**
     * Flush complete stderr lines from buffer via callback.
     */
    private function flushStderrLines(string &$buffer, ?callable $onLine): void
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

    private static array $ptrCache = [];
    private static array $ipApiCache = [];
    private static bool $ipApiUnavailable = false;
    private static int $ipApiPausedUntil = 0;

    /**
     * Batch-lookup IP details (ASN, PTR, org, country) for a list of IPs.
     * PTR via gethostbyaddr(); ASN/geo via ip-api.com batch.
     * Both are cached for the process, so IPs shared by many domains are looked up once per run.
     */
    public function lookupIpDetails(array $ips): array
    {
        $unique = array_values(array_unique(array_filter($ips)));
        if (empty($unique)) {
            return [];
        }

        $result = [];

        foreach ($unique as $ip) {
            if (!array_key_exists($ip, self::$ptrCache)) {
                $ptr = @gethostbyaddr($ip);
                self::$ptrCache[$ip] = ($ptr !== false && $ptr !== $ip) ? $ptr : '';
            }
            $result[$ip] = ['reverse' => self::$ptrCache[$ip]];
        }

        // ip-api.com allows 15 batch requests per minute and 100 IPs per batch, so only ask for IPs not seen this run
        $pending = array_values(array_filter($unique, fn($ip) => !array_key_exists($ip, self::$ipApiCache)));
        foreach (array_chunk($pending, 100) as $chunk) {
            if (self::$ipApiUnavailable || !$this->waitForIpApiWindow()) {
                break;
            }
            // In CLI runs a chunk refused with 429 is sent once more after the pause; a web request skips it
            if ($this->fetchIpApiBatch($chunk) === 429 && php_sapi_name() === 'cli' && $this->waitForIpApiWindow()) {
                $this->fetchIpApiBatch($chunk);
            }
        }

        foreach ($unique as $ip) {
            if (!empty(self::$ipApiCache[$ip])) {
                $result[$ip] = array_merge($result[$ip], self::$ipApiCache[$ip]);
            }
        }

        return $result;
    }

    /**
     * Query ip-api.com for one batch of IPs and cache the answers for the rest of the run.
     * Returns the HTTP status, 0 if nothing answered.
     */
    private function fetchIpApiBatch(array $ips): int
    {
        $requestBody = [];
        foreach ($ips as $ip) {
            $requestBody[] = [
                'query'  => $ip,
                'fields' => 'status,query,as,asname,isp,org,country,countryCode,regionName,city,hosting',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: DomainMonitor/1.0",
                'content'       => json_encode($requestBody),
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = null;
        $response = @file_get_contents('http://ip-api.com/batch', false, $context);

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int) $m[0];
        }

        // X-Rl = requests left in the window, X-Ttl = seconds until it resets.
        // ip-api.com bans clients that keep sending once X-Rl is 0.
        $remaining = null;
        $ttl = 60;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^X-Rl:\s*(\d+)/i', $header, $m)) {
                $remaining = (int) $m[1];
            } elseif (preg_match('/^X-Ttl:\s*(\d+)/i', $header, $m)) {
                $ttl = (int) $m[1];
            }
        }
        if ($remaining === 0 || $status === 429) {
            self::$ipApiPausedUntil = time() + min($ttl, 60) + 1;
        }

        if ($status === 429) {
            $this->logger->warning('ip-api.com rate limit exceeded, pausing IP details until the window resets');
            return $status;
        }
        if ($status !== 200 || !is_string($response)) {
            self::$ipApiUnavailable = true;
            $this->logger->warning('ip-api.com batch request failed, skipping IP details for the rest of this run');
            return $status;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return $status;
        }

        // IPs ip-api.com has no data for (private ranges etc.) are cached as empty so they aren't asked again
        foreach ($ips as $ip) {
            self::$ipApiCache[$ip] = [];
        }
        foreach ($data as $item) {
            if (($item['status'] ?? '') === 'success' && isset($item['query'])) {
                self::$ipApiCache[$item['query']] = $item;
            }
        }

        return $status;
    }

    /**
     * Wait for ip-api.com's rate limit window to reset once it is used up.
     * Only the CLI scripts wait; a web request skips the lookup and keeps the stored details.
     */
    private function waitForIpApiWindow(): bool
    {
        $wait = self::$ipApiPausedUntil - time();
        if ($wait <= 0) {
            return true;
        }
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        $this->logger->info("ip-api.com rate limit reached, waiting {$wait}s for the next batch");
        sleep($wait);
        return true;
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

        // A host that now only answers through the zone's wildcard is no longer listed, but it still
        // resolves to the same thing, so that is not a removal (#76). A host still listed has records
        // of its own, which the wildcard doesn't answer for, so what it lost is a removal.
        $wildcardValues = [];
        $newHosts = [];
        foreach ($newFlat as $record) {
            $newHosts[$record['host'] ?? '@'] = true;
            if (($record['host'] ?? '') === '*') {
                $wildcardValues["{$record['record_type']}:{$record['value']}:" . ($record['priority'] ?? '')] = true;
            }
        }

        foreach ($oldFlat as $key => $record) {
            if (!isset($newFlat[$key])) {
                // Manual and imported records stay saved whether the lookup finds them or not
                // (a relative name or a split TXT may not read back the same), so only records
                // the lookups found can be removed
                if (($record['source'] ?? 'discovered') !== 'discovered') {
                    continue;
                }

                $host = $record['host'] ?? '@';
                $value = "{$record['record_type']}:{$record['value']}:" . ($record['priority'] ?? '');
                if ($host !== '@' && $host !== '*' && !isset($newHosts[$host]) && isset($wildcardValues[$value])) {
                    continue;
                }
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
