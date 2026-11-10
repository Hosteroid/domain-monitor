<?php

namespace App\Services;

use Exception;
use App\Models\TldRegistry;

class WhoisService
{
    // Cache for discovered TLD servers to avoid repeated IANA queries
    private static array $tldCache = [];
    
    // Cache TTL in seconds (24 hours)
    private const CACHE_TTL = 86400;

    // A renewal adds at least a month; a smaller move of the expiration date is a correction
    private const RENEWAL_MIN_DAYS = 28;

    // Field names that an answer gives in Japanese only, under the English names the parser reads
    private const FIELD_NAMES = [
        '登録年月日' => 'Created on',
        '有効期限' => 'Expires on',
        '状態' => 'Status',
        'ロック状態' => 'Lock Status',
        '最終更新' => 'Last Updated',
        'ネームサーバ' => 'Name Server',
    ];

    // Wording that stands where a registrant's name would be when it is not published.
    // General phrases only: no privacy service or registry is listed by name.
    private const HIDDEN_WORDING = '/redacted|withheld|privacy|disclos|obfuscat|confidential|anonym|prox(?:y|ies)\b'
        . '|\bgdpr\b|\bmask(?:ed|ing)\b|\bon\s+behalf\s+of\b|\brdds\b|\bregistrar\s+of\s+record\b|^https?:\/\/'
        . '|\b(?:not|non|un)[\s-]*(?:published|public|shown|displayed|provided|available|applicable)\b'
        . '|^(?:private?|hidden|restricted|protected|unknown|none|n\/?a|[-*x.]+|not?\s+(?:\w+\s+)?found\W*)$'
        . '|\bhidden\s+(?:upon|by|for|per|due)\b|\bregistry\s+escrow\b'
        . '|\bprivate?\W*(?:person|individual|registra\w*|customer|name|owner|whois|domain|contact|by\s+design)'
        . '|\bregistra\w*\W+private\b|\bwhois\W*agent\b|\bdata\W*protect'
        . '|\b(?:whois|domain|identity|contact|registra\w*)\W*(?:protect|shield|guard|mask)'
        . '|\bprotect\w*\W+(?:domain|whois|registra|contact)/i';

    private TldRegistry $tldModel;
    
    // Track last error type for rate limit detection
    private static ?string $lastErrorType = null;

    // Why the last getDomainInfo call failed, worded for the user (null when there is nothing specific to say)
    private static ?string $lastErrorMessage = null;

    // Server, port and error of the last WHOIS connection that could not be opened
    private ?array $lastConnectionError = null;

    /**
     * Clear TLD cache (useful for testing or forcing fresh lookups)
     */
    public static function clearTldCache(): void
    {
        self::$tldCache = [];
    }
    
    /**
     * Check if the last getDomainInfo call failed due to rate limiting
     */
    public static function wasLastErrorRateLimit(): bool
    {
        return self::$lastErrorType === 'rate_limit';
    }
    
    /**
     * Clear last error type
     */
    public static function clearLastError(): void
    {
        self::$lastErrorType = null;
        self::$lastErrorMessage = null;
    }

    /**
     * Why the last getDomainInfo call failed, worded for the user, or null if there is no specific reason
     */
    public static function getLastErrorMessage(): ?string
    {
        return self::$lastErrorMessage;
    }

    /**
     * Advice for a WHOIS server that can't be reached on port 43
     */
    public static function connectionFailureHint(string $tld, bool $hasRdap): string
    {
        if ($hasRdap) {
            return "The RDAP lookup failed too, so check this server's outbound network access.";
        }
        return ".{$tld} has no RDAP service, so WHOIS on port 43 is required. Check that this server can make "
             . "outbound connections to port 43; some hosting providers block it.";
    }

    /**
     * Whether a WHOIS reply is the registry refusing this server's IP (e.g. Red.es for .es) rather than domain data
     */
    public static function isAccessDeniedResponse(string $response): bool
    {
        return (bool)preg_match('/IP address\b.{0,60}\bnot\s+authori[sz]ed|exceeded\s+the\s+established\s+limit/is', $response);
    }

    public function __construct()
    {
        $this->tldModel = new TldRegistry();
    }

    /**
     * Get domain information via WHOIS or RDAP
     */
    public function getDomainInfo(string $domain): ?array
    {
        // Clear last error at start of each lookup
        self::$lastErrorType = null;
        self::$lastErrorMessage = null;

        try {
            // Get TLD
            $parts = explode('.', $domain);
            if (count($parts) < 2) {
                return null;
            }

            // Handle double TLDs like co.uk
            $tld = $parts[count($parts) - 1];
            $doubleTld = null;
            if (count($parts) >= 3) {
                $doubleTld = $parts[count($parts) - 2] . '.' . $tld;
            }

            // Try double TLD first (e.g., co.uk), then single TLD
            $servers = null;
            if ($doubleTld) {
                $servers = $this->discoverTldServers($doubleTld);
                // If double TLD lookup failed, try single TLD
                if (!$servers['rdap_url'] && !$servers['whois_server']) {
                    $servers = $this->discoverTldServers($tld);
                } elseif (!$servers['rdap_url']) {
                    // The RDAP bootstrap only lists TLDs; the parent TLD's server answers for
                    // its second-level suffixes too (e.g. rdap.registro.br for .com.br)
                    $servers['rdap_url'] = $this->discoverTldServers($tld)['rdap_url'];
                }
            } else {
                $servers = $this->discoverTldServers($tld);
            }

            $rdapUrl = $servers['rdap_url'];
            $whoisServer = $servers['whois_server'];

            // Try RDAP first (modern, structured JSON protocol)
            if ($rdapUrl) {
                $rdapData = $this->queryRDAPGeneric($domain, $rdapUrl);
                if ($rdapData) {
                    $logger = new \App\Services\Logger();
                    $logger->debug("RDAP Success", [
                        'domain' => $domain,
                        'status' => $rdapData['status'] ?? [],
                        'registrar' => $rdapData['registrar'] ?? 'null'
                    ]);
                    // If RDAP succeeded but is missing expiration date, try WHOIS as fallback
                    // But only if the domain is not already marked as available
                    $isAvailable = false;
                    if (isset($rdapData['status']) && is_array($rdapData['status'])) {
                        foreach ($rdapData['status'] as $status) {
                            if (is_string($status) && self::isAvailableStatus($status)) {
                                $isAvailable = true;
                                break;
                            }
                        }
                    }
                    
                    if (empty($rdapData['expiration_date']) && !$isAvailable && $whoisServer) {
                        $whoisData = $this->queryWhois($domain, $whoisServer);
                        if ($whoisData) {
                            // Check if we got a referral to another WHOIS server
                            $referralServer = $this->extractReferralServer($whoisData);
                            if ($referralServer && $referralServer !== $whoisServer) {
                                $referralWhoisData = $this->queryWhois($domain, $referralServer);
                                if ($referralWhoisData) {
                                    $whoisData = $referralWhoisData;
                                }
                            }
                            
                            if ($whoisData) {
                                // Parse WHOIS data to get expiration date and cleaner registrar
                                $whoisInfo = $this->parseWhoisData($domain, $whoisData, $referralServer ?? $whoisServer);
                                
                                // If rate limited, skip WHOIS merge but keep RDAP data
                                if ($whoisInfo === null) {
                                    // Rate limited - return RDAP data as-is
                                    return $rdapData;
                                }
                                
                                // Merge expiration date from WHOIS into RDAP data
                                if (!empty($whoisInfo['expiration_date'])) {
                                    $rdapData['expiration_date'] = $whoisInfo['expiration_date'];
                                }
                                
                                // Also merge registrar if WHOIS has a cleaner version (without "Name:" prefix)
                                if (!empty($whoisInfo['registrar']) && 
                                    $whoisInfo['registrar'] !== 'Unknown' &&
                                    (!empty($rdapData['registrar']) && strpos($rdapData['registrar'], 'Name:') !== false)) {
                                    $rdapData['registrar'] = $whoisInfo['registrar'];
                                }
                            }
                        }
                    }
                    return $rdapData;
                }
                // If RDAP failed, fall through to WHOIS
            }

            // Fallback to WHOIS if RDAP not available or failed. With no WHOIS server known for
            // the TLD, IANA is asked: its answer may name the registry's server.
            $ianaAnswer = !$whoisServer;
            if (!$whoisServer) {
                $whoisServer = 'whois.iana.org';
            }

            // Get WHOIS data
            $this->lastConnectionError = null;
            $whoisData = $this->queryWhois($domain, $whoisServer);

            if (!$whoisData) {
                self::$lastErrorType = 'no_data';
                if ($this->lastConnectionError !== null) {
                    self::$lastErrorType = 'connection_failed';
                    self::$lastErrorMessage = "Could not connect to {$whoisServer} on port {$this->lastConnectionError['port']} "
                        . "({$this->lastConnectionError['error']}). " . self::connectionFailureHint($tld, $rdapUrl !== null);
                }
                $logger = new \App\Services\Logger();
                $logger->warning('No WHOIS data received', [
                    'domain' => $domain,
                    'server' => $whoisServer
                ]);
                return null;
            }

            if (self::isAccessDeniedResponse($whoisData)) {
                self::$lastErrorType = 'access_denied';
                self::$lastErrorMessage = "{$whoisServer} refused the query: the registry has not authorised this server's IP address "
                    . "for WHOIS, or its query limit was exceeded. WHOIS Lookup shows the registry's message, including how to request access.";
                $logger = new \App\Services\Logger();
                $logger->warning('WHOIS query refused by registry', [
                    'domain' => $domain,
                    'server' => $whoisServer
                ]);
                return null;
            }

            $logger = new \App\Services\Logger();
            $logger->debug('WHOIS data received', [
                'domain' => $domain,
                'server' => $whoisServer,
                'data_length' => strlen($whoisData),
                'first_200_chars' => substr($whoisData, 0, 200)
            ]);

            // Check if we got a referral to another WHOIS server
            $referralServer = $this->extractReferralServer($whoisData);
            if ($referralServer && $referralServer !== $whoisServer) {
                // Check if the original response already has complete data or is rate limited
                $originalInfo = $this->parseWhoisData($domain, $whoisData, $whoisServer);
                
                // If rate limited, return null immediately
                if ($originalInfo === null) {
                    return null;
                }
                
                $hasCompleteData = !empty($originalInfo['registrar']) && 
                                 $originalInfo['registrar'] !== 'Unknown' && 
                                 !empty($originalInfo['expiration_date']);
                
                if (!$hasCompleteData) {
                    // Only query the referred server if original data is incomplete
                    $logger = new \App\Services\Logger();
                    $logger->debug('Following WHOIS referral', [
                        'domain' => $domain,
                        'original_server' => $whoisServer,
                        'referral_server' => $referralServer
                    ]);
                    
                    $referralData = $this->queryWhois($domain, $referralServer);
                    if ($referralData) {
                        $whoisData = $referralData;
                        $ianaAnswer = false;
                    }
                } else {
                    $logger = new \App\Services\Logger();
                    $logger->debug('Skipping WHOIS referral - original data is complete', [
                        'domain' => $domain,
                        'original_server' => $whoisServer,
                        'referral_server' => $referralServer,
                        'original_registrar' => $originalInfo['registrar'],
                        'original_expiration' => $originalInfo['expiration_date']
                    ]);
                    $referralServer = null; // Don't use referral server
                }
            }

            // What IANA itself answers is about the TLD: its nameservers and dates are not the
            // domain's. Either a server that is known gave no answer, or none is known.
            if ($ianaAnswer) {
                if ($referralServer || $rdapUrl) {
                    self::$lastErrorType ??= 'no_data';
                    self::$lastErrorMessage ??= $referralServer
                        ? "{$referralServer}, the WHOIS server IANA names for .{$tld}, gave no answer."
                        : 'The RDAP lookup at ' . parse_url($rdapUrl, PHP_URL_HOST) . " failed, and .{$tld} has no WHOIS server to ask instead.";
                    return null;
                }

                return self::recordWithoutData($domain, $whoisServer, self::noServerNote($tld, $whoisData));
            }

            // Parse the response
            $actualServer = $referralServer ?? $whoisServer;
            $info = $this->parseWhoisData($domain, $whoisData, $actualServer);
            
            // If rate limited, return null
            if ($info === null) {
                return null;
            }
            
            // Override whois_server to reflect the actual server that provided the data
            $info['whois_server'] = $actualServer;
            
            // Debug logging using proper Logger service
            $logger = new \App\Services\Logger();
            $logger->debug('WHOIS parsing completed', [
                'domain' => $domain,
                'server' => $referralServer ?? $whoisServer,
                'raw_data_length' => strlen($whoisData),
                'parsed_registrar' => $info['registrar'] ?? 'null',
                'parsed_expiration' => $info['expiration_date'] ?? 'null',
                'parsed_nameservers_count' => count($info['nameservers'] ?? [])
            ]);

            return $info;

        } catch (Exception $e) {
            self::$lastErrorType = 'exception';
            $logger = new \App\Services\Logger();
            $logger->error('WHOIS lookup failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Discover RDAP and WHOIS servers for a TLD using TLD registry data
     * Returns array with 'rdap_url' and 'whois_server' keys
     */
    private function discoverTldServers(string $tld): array
    {
        // Check cache first (with TTL)
        if (isset(self::$tldCache[$tld])) {
            $cached = self::$tldCache[$tld];
            if (isset($cached['timestamp']) && (time() - $cached['timestamp']) < self::CACHE_TTL) {
                return $cached['data'];
            }
            // Cache expired, remove it
            unset(self::$tldCache[$tld]);
        }

        $result = [
            'rdap_url' => null,
            'whois_server' => null
        ];

        try {
            // First, try to get TLD info from our registry database
            $tldInfo = $this->tldModel->getByTld($tld);
            
            if ($tldInfo) {
                // Use WHOIS server from registry
                if (!empty($tldInfo['whois_server'])) {
                    $result['whois_server'] = $tldInfo['whois_server'];
                }
                
                // Use RDAP servers from registry
                if (!empty($tldInfo['rdap_servers'])) {
                    $rdapServers = json_decode($tldInfo['rdap_servers'], true);
                    if (is_array($rdapServers) && !empty($rdapServers)) {
                        $result['rdap_url'] = rtrim($rdapServers[0], '/') . '/';
                    }
                }
                
                // Cache the result
                self::$tldCache[$tld] = [
                    'data' => $result,
                    'timestamp' => time()
                ];
                return $result;
            }

            // Fallback: Query IANA directly if not in our registry
            // This maintains backward compatibility and handles new TLDs
            $response = $this->queryWhois($tld, 'whois.iana.org');
            
            if (!$response) {
                self::$tldCache[$tld] = [
                    'data' => $result,
                    'timestamp' => time()
                ];
                return $result;
            }

            // Parse IANA response for WHOIS server
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Look for WHOIS server
                if (preg_match('/^whois:\s+(.+)$/i', $line, $matches)) {
                    $result['whois_server'] = trim($matches[1]);
                }
            }

            // Try to get RDAP URL from IANA's RDAP bootstrap service
            $rdapBootstrapUrl = "https://data.iana.org/rdap/dns.json";
            $bootstrapData = @file_get_contents($rdapBootstrapUrl, false, stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'Domain Monitor/1.0'
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true
                ]
            ]));

            if ($bootstrapData) {
                $bootstrap = json_decode($bootstrapData, true);
                if ($bootstrap && isset($bootstrap['services'])) {
                    // The services array contains [["tld1", "tld2"], ["url1", "url2"]]
                    foreach ($bootstrap['services'] as $service) {
                        if (isset($service[0]) && isset($service[1])) {
                            $tlds = $service[0];
                            $urls = $service[1];
                            
                            // Check if our TLD is in this service's TLD list
                            if (in_array($tld, $tlds) || in_array('.' . $tld, $tlds)) {
                                if (!empty($urls[0])) {
                                    $result['rdap_url'] = rtrim($urls[0], '/') . '/';
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            // Fallback: try fetching the HTML page from IANA
            if (!$result['rdap_url']) {
                $htmlUrl = "https://www.iana.org/domains/root/db/{$tld}.html";
                $html = @file_get_contents($htmlUrl, false, stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'user_agent' => 'Domain Monitor/1.0'
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true
                    ]
                ]));

                if ($html) {
                    // The RDAP server as the page names it
                    $rdapServers = TldRegistryService::parseIanaPage($html)['rdap_servers'];
                    if ($rdapServers !== []) {
                        $result['rdap_url'] = rtrim($rdapServers[0], '/') . '/';
                    }
                }
            }

            // DO NOT guess RDAP URLs - they must be from official sources
            // Guessing often creates invalid URLs that don't resolve in DNS

            // Cache the result
            self::$tldCache[$tld] = [
                'data' => $result,
                'timestamp' => time()
            ];

            return $result;
        } catch (Exception $e) {
            self::$tldCache[$tld] = [
                'data' => $result,
                'timestamp' => time()
            ];
            return $result;
        }
    }


    /**
     * Extract referral WHOIS server from response
     */
    private function extractReferralServer(string $whoisData): ?string
    {
        $lines = explode("\n", $whoisData);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for various referral patterns
            if (preg_match('/^Registrar WHOIS Server:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/^ReferralServer:\s*whois:\/\/(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/^refer:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/^whois server:\s*(.+)$/i', $line, $matches)) {
                $server = trim($matches[1]);
                // Skip if it's just 'whois.iana.org' (we already queried that)
                if ($server !== 'whois.iana.org') {
                    return $server;
                }
            }
        }

        return null;
    }

    /**
     * Query generic RDAP server for any domain
     */
    private function queryRDAPGeneric(string $domain, string $rdapBaseUrl): ?array
    {
        // Ensure URL ends with /
        if (substr($rdapBaseUrl, -1) !== '/') {
            $rdapBaseUrl .= '/';
        }
        
        // Construct full RDAP URL
        // RDAP standard format: {base_url}domain/{domain_name}
        // If the base URL doesn't already end with "domain/", add it
        if (!preg_match('/domain\/$/', $rdapBaseUrl)) {
            $rdapUrl = $rdapBaseUrl . 'domain/' . strtolower($domain);
        } else {
            $rdapUrl = $rdapBaseUrl . strtolower($domain);
        }
        
        // Use cURL to get RDAP data
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $rdapUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/rdap+json, application/json, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Debug logging for RDAP requests
        $logger = new \App\Services\Logger();
        $logger->debug("RDAP Request", [
            'url' => $rdapUrl,
            'http_code' => $httpCode,
            'response_length' => strlen($response)
        ]);
        
        // Handle rate limiting (HTTP 429)
        if ($httpCode === 429) {
            self::$lastErrorType = 'rate_limit';
            $logger->warning("RDAP rate limit exceeded", [
                'domain' => $domain,
                'url' => $rdapUrl,
                'http_code' => $httpCode
            ]);
            // Return null to indicate rate limit - caller should handle gracefully
            return null;
        }
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                $logger->debug("RDAP Success", [
                    'domain' => $domain,
                    'status' => $data['status'] ?? [],
                    'entities_count' => count($data['entities'] ?? [])
                ]);
            }
        }
        
        // Handle 404 responses as domain not found
        if ($httpCode === 404) {
            $data = null;
            if ($response) {
                $data = json_decode($response, true);
            }
            
            // Handle both JSON 404 responses and plain 404 responses
            if (($data && isset($data['errorCode']) && $data['errorCode'] == 404) || !$data) {
                // Return domain not found response
                $rdapHost = parse_url($rdapBaseUrl, PHP_URL_HOST);
                return [
                    'domain' => $domain,
                    'registrar' => 'Not Registered',
                    'registrar_url' => null,
                    'expiration_date' => null,
                    'updated_date' => null,
                    'creation_date' => null,
                    'abuse_email' => null,
                    'nameservers' => [],
                    'status' => ['AVAILABLE'],
                    'owner' => 'Unknown',
                    'privacy' => 'unknown',
                    'whois_server' => $rdapHost . ' (RDAP)',
                    'raw_data' => [
                        'states' => ['AVAILABLE'],
                        'nameServers' => [],
                    ]
                ];
            } elseif ($data && isset($data['status']) && is_array($data['status'])) {
                // Handle HTTP 404 with valid JSON response containing "free" status (like hosteroid.nl)
                foreach ($data['status'] as $status) {
                    if (stripos($status, 'free') !== false) {
                        $rdapHost = parse_url($rdapBaseUrl, PHP_URL_HOST);
                        return [
                            'domain' => $domain,
                            'registrar' => 'Not Registered',
                            'registrar_url' => null,
                            'expiration_date' => null,
                            'updated_date' => null,
                            'creation_date' => null,
                            'abuse_email' => null,
                            'nameservers' => [],
                            'status' => ['AVAILABLE'],
                            'owner' => 'Unknown',
                            'privacy' => 'unknown',
                            'whois_server' => $rdapHost . ' (RDAP)',
                            'raw_data' => [
                                'states' => ['AVAILABLE'],
                                'nameServers' => [],
                            ]
                        ];
                    }
                }
            }
        }
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            return null;
        }
        
        // Extract the RDAP host for display
        $rdapHost = parse_url($rdapBaseUrl, PHP_URL_HOST);
        
        $info = $this->parseRDAPData($domain, $data, $rdapHost);
        $registered = !in_array('AVAILABLE', $info['status'], true);

        // The registry may leave the registrant to the registrar's own RDAP server (.com, .net, .org)
        $registrarUrl = $registered && $info['privacy'] !== 'public' ? self::registrarRdapLink($data, $rdapUrl) : null;
        if ($registrarUrl !== null) {
            $registrarData = $this->queryRegistrarRdap($registrarUrl);
            if ($registrarData === null) {
                // Nothing said by the registry, and the registrar could not be asked
                $info['privacy'] ??= 'unknown';
            } else {
                $registrant = self::rdapRegistrant($registrarData);
                if ($registrant['privacy'] === 'public' || ($registrant['privacy'] !== null && $info['privacy'] !== 'hidden')) {
                    $info = self::withRegistrant($info, $registrant);
                }
            }
        }

        // An answer without a word about the registrant publishes none
        $info['privacy'] ??= $registered ? 'hidden' : 'unknown';

        return $info;
    }


    /**
     * Parse RDAP JSON data into our standard format
     */
    private function parseRDAPData(string $domain, array $rdapData, string $rdapHost = 'RDAP'): array
    {
        $info = [
            'domain' => $domain,
            'registrar' => null,
            'registrar_url' => null,
            'expiration_date' => null,
            'updated_date' => null,
            'creation_date' => null,
            'abuse_email' => null,
            'nameservers' => [],
            'status' => [],
            'owner' => 'Unknown',
            'privacy' => null,
            'whois_server' => $rdapHost . ' (RDAP)',
            'raw_data' => []
        ];
        
        // Parse events (dates)
        if (isset($rdapData['events']) && is_array($rdapData['events'])) {
            foreach ($rdapData['events'] as $event) {
                $action = $event['eventAction'] ?? '';
                $date = $event['eventDate'] ?? '';
                
                if (!empty($date)) {
                    $parsedDate = date('Y-m-d', strtotime($date));
                    
                    if ($action === 'registration') {
                        $info['creation_date'] = $parsedDate;
                    } elseif ($action === 'expiration') {
                        $info['expiration_date'] = $parsedDate;
                    } elseif ($action === 'last changed') {
                        $info['updated_date'] = $parsedDate;
                    }
                }
            }
        }
        
        // Parse status
        if (isset($rdapData['status']) && is_array($rdapData['status'])) {
            $info['status'] = $rdapData['status'];
            
            // Convert "free" status to "AVAILABLE" for consistency
            $info['status'] = array_map(function($status) {
                if (stripos($status, 'free') !== false) {
                    return 'AVAILABLE';
                }
                return $status;
            }, $info['status']);
        }
        
        // Parse entities (registrar, abuse contact)
        if (isset($rdapData['entities']) && is_array($rdapData['entities'])) {
            foreach ($rdapData['entities'] as $entity) {
                $roles = $entity['roles'] ?? [];
                
                // Registrar
                if (in_array('registrar', $roles)) {
                    // Get registrar name from vCard
                    if (isset($entity['vcardArray'][1])) {
                        $registrarOrg = null;
                        foreach ($entity['vcardArray'][1] as $vcardField) {
                            if (is_array($vcardField) && count($vcardField) >= 4) {
                                if ($vcardField[0] === 'fn') {
                                    $registrarName = $vcardField[3];
                                    // .eu RDAP returns "Name: Company Name" - strip "Name:" prefix
                                    if (preg_match('/^Name:\s*(.+)/i', $registrarName, $matches)) {
                                        $registrarName = trim($matches[1]);
                                    }
                                    $info['registrar'] = $registrarName;
                                } elseif ($vcardField[0] === 'org' && is_string($vcardField[3]) && trim($vcardField[3]) !== '') {
                                    $registrarOrg = trim($vcardField[3]);
                                } elseif ($vcardField[0] === 'url') {
                                    $info['registrar_url'] = $vcardField[3];
                                }
                            }
                        }
                        // Some registries give the registrar as an organisation, without a name (.ch, .li)
                        if (empty($info['registrar']) && $registrarOrg !== null) {
                            $info['registrar'] = $registrarOrg;
                        }
                    }
                    
                    // Check for abuse contact in nested entities
                    if (isset($entity['entities']) && is_array($entity['entities'])) {
                        foreach ($entity['entities'] as $subEntity) {
                            if (in_array('abuse', $subEntity['roles'] ?? [])) {
                                if (isset($subEntity['vcardArray'][1])) {
                                    foreach ($subEntity['vcardArray'][1] as $vcardField) {
                                        if (is_array($vcardField) && count($vcardField) >= 4) {
                                            if ($vcardField[0] === 'email') {
                                                $info['abuse_email'] = $vcardField[3];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Parse nameservers
        if (isset($rdapData['nameservers']) && is_array($rdapData['nameservers'])) {
            foreach ($rdapData['nameservers'] as $ns) {
                $nsName = $ns['ldhName'] ?? '';
                if (!empty($nsName)) {
                    // Remove trailing dot if present
                    $nsName = rtrim($nsName, '.');
                    $info['nameservers'][] = strtolower($nsName);
                }
            }
        }
        
        // What the answer shows of the registrant; null when it says nothing about one
        $info = self::withRegistrant($info, self::rdapRegistrant($rdapData));

        // Set default registrar if not found
        if ($info['registrar'] === null) {
            $info['registrar'] = 'Unknown';
        }
        
        $info['raw_data'] = [
            'states' => $info['status'],
            'nameServers' => $info['nameservers'],
        ];
        
        return $info;
    }

    /**
     * Whether a value stands where a registrant's name would be, saying that it is not published
     */
    private static function isHiddenWording(string $value): bool
    {
        return preg_match(self::HIDDEN_WORDING, trim($value)) === 1;
    }

    /**
     * What an RDAP answer shows of the registrant: 'public' with the name or organisation,
     * 'hidden', 'unknown' for a contact handle alone, or null when it says nothing about one
     */
    private static function rdapRegistrant(array $rdapData): array
    {
        $found = ['privacy' => null, 'name' => null, 'org' => null];
        $hasEntity = $hasCard = $hasHandle = $hiddenRemark = false;

        foreach ((array)($rdapData['entities'] ?? []) as $entity) {
            if (!is_array($entity) || !in_array('registrant', (array)($entity['roles'] ?? []), true)) {
                continue;
            }
            $hasEntity = true;
            $hasHandle = $hasHandle || (is_string($entity['handle'] ?? null) && trim($entity['handle']) !== '');

            foreach ((array)($entity['vcardArray'][1] ?? []) as $field) {
                if (!is_array($field) || count($field) < 4) {
                    continue;
                }
                $hasCard = true;
                // An organisation can come as a list: its name, then its units
                $value = is_array($field[3]) ? implode(' ', array_filter($field[3], 'is_string')) : $field[3];
                if (!is_string($value) || trim($value) === '' || self::isHiddenWording($value)) {
                    continue;
                }
                if ($field[0] === 'fn') {
                    $found['name'] ??= trim($value);
                } elseif ($field[0] === 'org') {
                    $found['org'] ??= trim($value);
                }
            }

            foreach ((array)($entity['remarks'] ?? []) as $remark) {
                $title = is_array($remark) && is_string($remark['title'] ?? null) ? $remark['title'] : '';
                $hiddenRemark = $hiddenRemark || self::isHiddenWording($title);
            }
        }

        if ($found['name'] !== null || $found['org'] !== null) {
            $found['privacy'] = 'public';
        } elseif ($hasCard || $hiddenRemark || self::redactsRegistrant($rdapData)) {
            $found['privacy'] = 'hidden';
        } elseif ($hasEntity) {
            // A handle alone leads to a name only through another query
            $found['privacy'] = $hasHandle ? 'unknown' : 'hidden';
        }

        return $found;
    }

    /**
     * Whether the answer's list of redacted fields (RFC 9537) names the registrant or its name
     */
    private static function redactsRegistrant(array $rdapData): bool
    {
        foreach ((array)($rdapData['redacted'] ?? []) as $entry) {
            $name = is_array($entry) && is_array($entry['name'] ?? null) ? $entry['name'] : [];
            $label = $name['description'] ?? $name['type'] ?? '';
            if (is_string($label) && preg_match('/^registrant(\s+(contact|name|organi[sz]ation))?$/i', trim($label))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registrar's RDAP address for the same domain, as the registry's answer links to it
     */
    private static function registrarRdapLink(array $rdapData, string $askedUrl): ?string
    {
        foreach ((array)($rdapData['links'] ?? []) as $link) {
            if (!is_array($link) || ($link['rel'] ?? null) !== 'related' || !is_string($link['href'] ?? null)
                || !is_string($link['type'] ?? null) || stripos($link['type'], 'rdap') === false) {
                continue;
            }

            $href = trim($link['href']);
            $host = parse_url($href, PHP_URL_HOST);
            // Only a public host name over HTTPS: the address comes from someone else's answer
            if (stripos($href, 'https://') !== 0 || !is_string($host) || strpos($host, '.') === false
                || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            // Some answers link to themselves
            if (strcasecmp(rtrim($href, '/'), rtrim($askedUrl, '/')) === 0) {
                continue;
            }

            return $href;
        }

        return null;
    }

    /**
     * Ask the registrar's RDAP server for the domain. A failure is not a failed lookup: it
     * only leaves the registrant unknown.
     */
    private function queryRegistrarRdap(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/rdap+json, application/json, */*',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = $httpCode === 200 && is_string($response) ? json_decode($response, true) : null;
        $usable = is_array($data) && !isset($data['errorCode']);

        $logger = new \App\Services\Logger();
        $logger->debug('Registrar RDAP request', [
            'url' => $url,
            'http_code' => $httpCode,
            'usable' => $usable
        ]);

        return $usable ? $data : null;
    }

    /**
     * Put what was read of the registrant into a lookup record
     */
    private static function withRegistrant(array $record, array $registrant): array
    {
        $record['privacy'] = $registrant['privacy'];

        if ($registrant['privacy'] === 'public') {
            $record['owner'] = $registrant['name'] ?? $registrant['org'];
            if ($registrant['org'] !== null) {
                $record['organization'] = $registrant['org'];
            }
        }

        return $record;
    }

    /**
     * What a WHOIS answer shows of the registrant: 'public' with the name or organisation,
     * 'hidden', or null when it cannot be told. Reads lines such as "Registrant Name: x" and
     * the lines under a heading such as "Registrant:", "Holder" or "Owner Contact".
     */
    private static function whoisRegistrant(string $whoisData): array
    {
        $found = ['privacy' => null, 'name' => null, 'org' => null];
        $hidden = false;
        $inBlock = false;   // under a registrant heading
        $blockLines = 0;    // lines read under it
        $gap = false;       // an empty line came between the heading and its lines
        $seen = [];         // kinds of field already read under it

        foreach (explode("\n", str_replace("\r", '', $whoisData)) as $line) {
            $line = trim($line);

            if ($line === '') {
                // An empty line ends the block, unless it comes straight after the heading
                $inBlock = $inBlock && $blockLines === 0;
                $gap = $inBlock;
                continue;
            }
            if ($line[0] === '%' || $line[0] === '#') {
                continue;
            }

            // "[Registrant] Example Inc." reads as "Registrant: Example Inc."
            if (preg_match('/^(?:[a-z]\.\s+)?\[(\S[^\]]*)\]\s+(\S.*)$/iu', $line, $matches)) {
                $line = $matches[1] . ': ' . $matches[2];
            }

            // A heading: the word alone on its line, as "Registrant:", "[Holder]" or "** Registrant:"
            if (preg_match('/^(?:domain\s+)?(?:registrant|owner|holder|titular)(?:\s+(?:contact|details|information|info|data))*$/i', trim($line, " \t*[]:"))) {
                $inBlock = true;
                $blockLines = 0;
                $gap = false;
                $seen = [];
                continue;
            }

            // "Name: value", also written "Name.........: value"
            $key = null;
            $value = $line;
            if (preg_match('/^([^:]*?)[.\s]*:\s*(.*)$/', $line, $matches)) {
                $key = strtolower(trim($matches[1]));
                $value = trim($matches[2]);
            }

            $kind = null;
            $fromBlock = true;
            if ($key === null) {
                // Straight under a heading, the first line is the name
                $kind = $inBlock && $blockLines === 0 && !$gap ? 'name' : null;
            } elseif (preg_match('/\b(?:registrant|owner|holder|titular)\b/', $key)) {
                // What the field holds is told by the words that are left
                $rest = trim(preg_replace('/\b(?:registrant|owner|holder|titular|domain|contact|registry)\b|[^a-z]+/', ' ', $key));
                $fromBlock = false;
                $kind = match (true) {
                    $rest === '' => 'either',
                    $rest === 'name' => 'name',
                    in_array($rest, ['org', 'organization', 'organisation', 'company'], true) => 'org',
                    $rest === 'c' => 'handle',
                    default => null,
                };
            } elseif ($inBlock && !preg_match('/\b(?:id|no|nr|number|code|type|domain|servers?|hosts?|user|account|login|zone)\b/', $key)) {
                // "org id" and "name server" are neither
                if (preg_match('/\b(?:org|organi[sz]ation|company)\b/', $key)) {
                    $kind = 'org';
                } elseif (preg_match('/\bname\b/', $key)) {
                    $kind = 'name';
                }
            }

            // Under a heading only the first of each kind counts: a later one may belong to the next contact
            if ($kind !== null && $fromBlock) {
                $kind = isset($seen[$kind]) ? null : $kind;
                if ($kind !== null) {
                    $seen[$kind] = true;
                }
            }

            if ($inBlock) {
                $blockLines++;
            }

            if ($kind === null) {
                // Another heading ends the block
                if ($key !== null && $value === '') {
                    $inBlock = false;
                }
                continue;
            }

            if ($value === '') {
                // A name field left empty
                $hidden = $hidden || $kind === 'name';
            } elseif (self::isHiddenWording($value)) {
                $hidden = true;
            } elseif ($kind === 'org') {
                $found['org'] ??= $value;
            } elseif ($kind === 'name' || ($kind === 'either' && !self::looksLikeHandle($value))) {
                $found['name'] ??= $value;
            }
        }

        if ($found['name'] !== null || $found['org'] !== null) {
            $found['privacy'] = 'public';
        } elseif ($hidden) {
            $found['privacy'] = 'hidden';
        }

        return $found;
    }

    /**
     * Whether a value is a contact handle ("NG8867695-NICAT", "ADMIN-LSNIC") and not a name
     */
    private static function looksLikeHandle(string $value): bool
    {
        return !preg_match('/\s/', $value)
            && (preg_match('/\d/', $value) || preg_match('/^[A-Z0-9]+(?:[-_][A-Z0-9]+)+$/', $value));
    }

    /**
     * Keep the registrant privacy found by the last check when this one could not tell,
     * as long as the registrar is the same
     */
    public function keepKnownPrivacy(array $existingDomain, array $whoisData): array
    {
        if (($whoisData['privacy'] ?? 'unknown') !== 'unknown') {
            return $whoisData;
        }

        $previous = json_decode($existingDomain['whois_data'] ?? '', true);
        if (!is_array($previous) || !in_array($previous['privacy'] ?? null, ['hidden', 'public'], true)
            || ($previous['registrar'] ?? null) !== ($whoisData['registrar'] ?? null)) {
            return $whoisData;
        }

        $whoisData['privacy'] = $previous['privacy'];
        if ($previous['privacy'] === 'public') {
            foreach (['owner', 'organization'] as $field) {
                if (isset($previous[$field])) {
                    $whoisData[$field] = $previous[$field];
                }
            }
        }

        return $whoisData;
    }

    /**
     * Query WHOIS server
     */
    private function queryWhois(string $domain, string $server, int $port = 43): ?string
    {
        $timeout = 10;

        // Try to connect to WHOIS server
        $fp = @fsockopen($server, $port, $errno, $errstr, $timeout);

        if (!$fp) {
            $this->lastConnectionError = [
                'server' => $server,
                'port' => $port,
                'error' => $errstr !== '' ? $errstr : "error {$errno}",
            ];
            $logger = new \App\Services\Logger();
            $logger->warning("WHOIS connection failed", [
                'server' => $server,
                'port' => $port,
                'error' => $errstr,
                'errno' => $errno
            ]);
            return null;
        }

        // Send query
        fputs($fp, $domain . "\r\n");

        // Get response
        $response = '';
        while (!feof($fp)) {
            $response .= fgets($fp, 128);
        }

        fclose($fp);

        return self::toUtf8($response);
    }

    /**
     * WHOIS has no charset negotiation and some registries (e.g. whois.registro.br) answer in Latin-1.
     * Convert anything that isn't valid UTF-8, so it can be parsed, displayed and stored as JSON.
     */
    public static function toUtf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Check if WHOIS response indicates rate limiting
     */
    private function isRateLimitError(string $whoisData): bool
    {
        $responseLength = strlen($whoisData);
        
        // Rate limit errors are typically short error messages (usually <200 chars), not full domain data
        // If response is very long (>500 chars), it's almost certainly valid domain data, not an error
        // This is the most reliable check to avoid false positives
        if ($responseLength > 500) {
            return false;
        }
        
        // Even for shorter responses, if it contains domain data indicators, it's likely valid
        $whoisDataLower = strtolower(trim($whoisData));
        
        // Check for domain data indicators first (if present, it's not a rate limit error)
        $domainDataIndicators = [
            'domain name:',
            'registrar:',
            'creation date:',
            'expiration date:',
            'updated date:',
            'nameserver:',
            'registry domain id:',
            'registrar whois server:',
            'registrar url:'
        ];
        
        $hasDomainData = false;
        foreach ($domainDataIndicators as $indicator) {
            if (stripos($whoisDataLower, $indicator) !== false) {
                $hasDomainData = true;
                break;
            }
        }
        
        // If it has domain data indicators, it's definitely not a rate limit error
        if ($hasDomainData) {
            return false;
        }
        
        // For short responses without domain data, check for specific rate limit error patterns
        // These are typically short, specific error messages
        $rateLimitPatterns = [
            // Exact error messages (most common formats)
            '/^error:\s*ratelimit/i',
            '/^error:\s*rate[\s\-_]?limit/i',
            '/^ratelimit\s+exceeded/i',
            '/^rate[\s\-_]?limit\s+exceeded/i',
            '/^rate[\s\-_]?limit\s+error/i',
            '/error:\s*ratelimit\s+exceeded/i',
            '/error:\s*rate[\s\-_]?limit\s+exceeded/i',
            
            // Other rate limit error formats
            '/too many requests/i',
            '/quota exceeded/i',
            '/^limit exceeded/i',
            
            // Rate limit in error context (at start of response)
            '/^error.*rate[\s\-_]?limit/i',
            '/^rate[\s\-_]?limit.*error/i',
        ];
        
        // Check for exact patterns
        foreach ($rateLimitPatterns as $pattern) {
            if (preg_match($pattern, $whoisDataLower)) {
                return true;
            }
        }
        
        // Additional check: if response contains both "rate" and "limit" in close proximity
        // Only for very short responses (<100 chars) to avoid false positives
        if ($responseLength < 100 && preg_match('/rate.{0,20}limit|limit.{0,20}rate/i', $whoisDataLower)) {
            return true;
        }
        
        return false;
    }

    /**
     * Whether a line of a WHOIS answer, or a status in it, says that nothing is registered under
     * the name. Every registry words it its own way ("No Data Found", "No match for domain",
     * "Object does not exist", "The domain has not been registered", "is available for
     * registration"), so the wording is looked for anywhere in the line.
     */
    private static function saysNotRegistered(string $line): bool
    {
        return (bool)preg_match(
            '/\b(?:no|not|nothing)\b[^.:;]{0,40}?\bfound\b'
            . '|\bno match'
            . '|\b(?:does|do) not exist\b'
            . '|\bnot (?:been )?registe?red\b'
            . '|\bis available for (?:registration|purchase)\b'
            . '|\bno (?:entries|data|records?|objects?|such domain)\b/i',
            str_replace('_', ' ', $line)
        );
    }

    /**
     * Whether a line of a WHOIS answer says that too many queries were made or that the registry
     * can't answer for now ("Maximum query rate reached", "Quota exceeded"). A sentence about
     * its terms ("Access is rate limited") is not one.
     */
    private static function saysQueryLimit(string $line): bool
    {
        return (bool)preg_match(
            '/\b(?:limit|quota|rate|queries|requests|connections)\b[^.]{0,40}\b(?:exceeded|reached)\b'
            . '|\bexceeded\b[^.]{0,40}\b(?:limit|quota|rate|queries|requests|connections)\b'
            . '|\btoo many\b|\btry again later\b|\btemporarily\b/i',
            $line
        );
    }

    /**
     * The first line of a WHOIS answer that fits, without the marks in front of it ("%", ">>>").
     * Longer lines are the registry's terms and are passed over.
     *
     * @param callable(string): bool $fits
     */
    private static function findLine(string $answer, callable $fits): ?string
    {
        foreach (explode("\n", $answer) as $line) {
            $line = trim($line, " \t\r%#>*<");
            if ($line !== '' && strlen($line) <= 100 && $fits($line)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * What to tell the user about an answer that holds nothing of a registration and does not say
     * the name is free: the registry's own words, e.g. a refusal
     */
    private static function unreadableNote(string $server, string $answer): string
    {
        // Lines marked ">>>" are messages; an answer without any says it in its plain lines.
        // Comments, the name asked about and the time of the registry's last update say nothing.
        $marked = [];
        $plain = [];
        foreach (explode("\n", $answer) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '%' || $line[0] === '#') {
                continue;
            }

            $isMarked = str_starts_with($line, '>>>');
            $line = trim(preg_replace('/\s+/', ' ', $line), ' >*<');
            if (!preg_match('/[a-z]{2,} [a-z]{2,}/i', $line) || preg_match('/^(?:domain(?: name)?:|last update)/i', $line)) {
                continue;
            }

            if ($isMarked) {
                $marked[] = $line;
            } else {
                $plain[] = $line;
            }
        }

        $said = $marked ?: $plain;
        if ($said === []) {
            return "{$server} answered without anything about the domain.";
        }

        // A short first line leads up to the reason in the next one ("Error code: 01044")
        $text = strlen($said[0]) < 60 && isset($said[1]) ? $said[0] . ' ' . $said[1] : $said[0];

        return "{$server} answered: \"" . mb_substr($text, 0, 200) . '"';
    }

    /**
     * What to tell the user when IANA's answer names no WHOIS server to ask
     */
    private static function noServerNote(string $tld, string $ianaAnswer): string
    {
        return preg_match('/returned 0 objects/i', $ianaAnswer)
            ? "IANA does not list the TLD .{$tld}."
            : "No WHOIS or RDAP server is known for .{$tld}: IANA lists none.";
    }

    /**
     * A lookup result for a domain nothing could be found out about, with the reason
     */
    private static function recordWithoutData(string $domain, string $server, string $note): array
    {
        return [
            'domain' => $domain,
            'registrar' => 'Unknown',
            'registrar_url' => null,
            'expiration_date' => null,
            'updated_date' => null,
            'creation_date' => null,
            'abuse_email' => null,
            'nameservers' => [],
            'status' => [],
            'owner' => 'Unknown',
            'privacy' => 'unknown',
            'whois_server' => $server,
            'raw_data' => [
                'states' => [],
                'nameServers' => [],
            ],
            'lookup_note' => $note,
        ];
    }

    /**
     * Parse WHOIS data
     */
    private function parseWhoisData(string $domain, string $whoisData, string $whoisServer = 'Unknown'): ?array
    {
        // Check for rate limit errors first
        if ($this->isRateLimitError($whoisData)) {
            self::$lastErrorType = 'rate_limit';
            $logger = new \App\Services\Logger();
            $logger->warning("WHOIS rate limit exceeded", [
                'domain' => $domain,
                'server' => $whoisServer,
                'response_preview' => substr($whoisData, 0, 200)
            ]);
            // Return null to indicate rate limit - caller should handle gracefully
            return null;
        }
        
        // Registries end their lines in LF or in CR LF: the patterns below are written for LF
        $whoisData = str_replace("\r", '', $whoisData);

        $lines = explode("\n", $whoisData);
        $data = [
            'domain' => $domain,
            'registrar' => null,
            'registrar_url' => null,
            'expiration_date' => null,
            'updated_date' => null,
            'creation_date' => null,
            'abuse_email' => null,
            'nameservers' => [],
            'status' => [],
            'owner' => 'Unknown',
            'privacy' => 'unknown',
            'whois_server' => $whoisServer,
            'raw_data' => []
        ];
        
        // Check if domain is not found/available
        $whoisDataLower = strtolower($whoisData);
        // Exact line match (original patterns)
        if (preg_match('/^(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower) ||
            preg_match('/^status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower) ||
            preg_match('/^domain status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower)) {
            $data['status'][] = 'AVAILABLE';
            $data['registrar'] = 'Not Registered';
            return $data;
        }
        // Broader patterns for formats that don't match exact line (e.g. .rs "%ERROR:103: Domain is not registered", .io "Domain not found.", .co "The queried object does not exist: DOMAIN NOT FOUND")
        if (preg_match('/domain\s+is\s+not\s+registered|domain\s+not\s+found\.?(\s|$)|queried\s+object\s+does\s+not\s+exist|%error:\d+:\s*domain/i', $whoisDataLower)) {
            $data['status'][] = 'AVAILABLE';
            $data['registrar'] = 'Not Registered';
            return $data;
        }
        
        // Special handling for .eu domains that are available
        // EURid returns "Status: AVAILABLE" in a specific format
        if (preg_match('/status:\s*available/i', $whoisDataLower)) {
            $data['status'][] = 'AVAILABLE';
            $data['registrar'] = 'Not Registered';
            return $data;
        }
        
        $registrarFound = false;
        $currentSection = null;
        $nameServerListStarted = false;

        foreach ($lines as $index => $line) {
            $line = trim($line);

            // An empty line ends a list of name servers: .gg and .je go on with their terms of use
            if ($line === '' && $nameServerListStarted) {
                $currentSection = null;
                $nameServerListStarted = false;
            }

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '%' || $line[0] === '#') {
                continue;
            }

            // Layouts without a colon are read as "Field: value" from here on
            if (preg_match('/^(?:[a-z]\.\s+)?\[(\S[^\]]*)\]\s+(\S.*)$/iu', $line, $matches)) {
                // "[Name Server]   ns1.example.jp", for some kinds of name with a letter in front
                // ("p. [Name Server]   ns1.example.jp"), as .jp answers. A bracketed name alone on
                // its line is a section heading in other answers (.lv "[Registrar]") and stays as it is.
                // A time zone in brackets after a time is left off, so the date is saved as written.
                $line = (self::FIELD_NAMES[$matches[1]] ?? $matches[1]) . ': ' . preg_replace('/\s*\([A-Z]{2,5}\)$/', '', $matches[2]);

                // The day the registration ends can come with the state: "Connected (2027/03/31)"
                if (preg_match('/^(?:State|Status): (Connected|Registered)\s*\((\d{4}\/\d{2}\/\d{2})\)$/i', $line, $matches)) {
                    $line = 'Status: ' . $matches[1];
                    $data['expiration_date'] = $this->parseDate($matches[2]);
                }
            } elseif (preg_match('/^Record (created|expires) on\s+(\S.*)$/i', $line, $matches)) {
                // "Record created on 2008-05-28 17:21:15", "Record expires on 2029-05-28", as .mo answers
                $line = 'Record ' . strtolower($matches[1]) . ': ' . $matches[2];
            }

            // Check for section headers (UK format - lines ending with colon, no value)
            if (preg_match('/^([^:]+):\s*$/', $line, $matches)) {
                $currentSection = strtolower(trim($matches[1]));
                $nameServerListStarted = false;
                
                // For UK domains: Registrar section - next line has the actual registrar
                if ($currentSection === 'registrar' && !$registrarFound && isset($lines[$index + 1])) {
                    $nextLine = trim($lines[$index + 1]);
                    // A field of its own is not the name: .at leaves "registrar:" empty for some
                    // names and goes on with "registrant: XX123-NICAT". "Name: ..." is, as .eu answers.
                    if (!empty($nextLine) && !preg_match('/^(?!name\s*:)[^:]+:(?:\s|$)/i', $nextLine)) {
                        // Extract registrar name (remove [Tag = XXX] part)
                        $registrarName = preg_replace('/\s*\[Tag\s*=\s*[^\]]+\]/i', '', $nextLine);
                        $registrarName = trim($registrarName);
                        // .eu format: Strip "Name:" prefix if present
                        if (preg_match('/^Name:\s*(.+)/i', $registrarName, $matches)) {
                            $registrarName = trim($matches[1]);
                        }
                        if (!empty($registrarName)) {
                            $data['registrar'] = $registrarName;
                            $registrarFound = true;
                        }
                    }
                }
                continue;
            }

            // For multi-line sections (UK format, and .mo's "Domain name servers:"), check if we're in a specific section
            if ($currentSection === 'name servers' || $currentSection === 'domain name servers') {
                // Extract nameserver (format: "ns1.example.com    192.168.1.1")
                if (!preg_match('/^(This|--|\d+\.)/', $line)) {
                    $ns = preg_split('/\s+/', $line)[0]; // Get first part (nameserver)
                    $nameServerListStarted = $nameServerListStarted || strpos($ns, '.') !== false;
                    if (!empty($ns) && strpos($ns, '.') !== false && !in_array(strtolower($ns), $data['nameservers'])) {
                        $data['nameservers'][] = strtolower($ns);
                    }
                }
            }

            // Parse key-value pairs
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim(strtolower($key));
                // "Registrar:..........Name": dots that line the values up are not part of them
                $value = trim(preg_replace('/^\.{2,}(?!\.)/', '', trim($value)));

                // For UK format - check for URL in registrar section
                if ($key === 'url' && $currentSection === 'registrar' && !empty($value)) {
                    $data['registrar_url'] = $value;
                }

                // Expiration date (.bo: "Fecha de vencimiento")
                if (preg_match('/(expir|expiry|expire|paid-till|renewal|registry.*expir|^valid\s*until$|vencimiento)/i', $key) && !empty($value)) {
                    $data['expiration_date'] = $this->parseDate($value);
                }

                // Updated date (UK format: "Last updated")
                if (preg_match('/(updated date|last updated|updated)/i', $key) && !empty($value)) {
                    $data['updated_date'] = $this->parseDate($value);
                }

                // Creation date (UK format: "Registered on", .bo: "Fecha de registro")
                if (preg_match('/(creat|registered|registered on|creation|^fecha de registro$)/i', $key) && !empty($value)) {
                    $data['creation_date'] = $this->parseDate($value);
                }

                // Registrar (only take the first valid one found) - for standard format
                if (!$registrarFound && preg_match('/^registrar(?!.*url|.*whois|.*iana|.*phone|.*email|.*fax|.*abuse|.*id|.*contact|.*date|.*expir)/i', $key) && !empty($value)) {
                    // Skip if it looks like a phone number, email, or ID
                    if (!preg_match('/^[\+\d\.\s\(\)-]+$/', $value) && 
                        !preg_match('/@/', $value) && 
                        !preg_match('/^\d+$/', $value) &&
                        strlen($value) > 3) {
                        
                        // .eu format: If value starts with "Name:", extract just the name part
                        if (preg_match('/^Name:\s*(.+)/i', $value, $matches)) {
                            $data['registrar'] = trim($matches[1]);
                            $registrarFound = true;
                        } else {
                            $data['registrar'] = $value;
                            $registrarFound = true;
                        }
                    }
                }
                
                // .eu specific registrar format: "Name: Registrar Name" (as separate line)
                if (!$registrarFound && $key === 'name' && $currentSection === 'registrar' && !empty($value)) {
                    $data['registrar'] = $value;
                    $registrarFound = true;
                }

                // Nameservers (standard format)
                if (preg_match('/(name server|nserver|nameserver)/i', $key) && !empty($value)) {
                    $ns = preg_replace('/\s+.*$/', '', $value); // Remove IP addresses
                    if (!empty($ns) && !in_array($ns, $data['nameservers'])) {
                        $data['nameservers'][] = strtolower($ns);
                    }
                }

                // Status (UK format: "Registration status")
                if (preg_match('/(status|state|registration status)/i', $key) && !empty($value)) {
                    // Filter out invalid status values and extract just the status name
                    $cleanValue = trim($value);
                    if (!empty($cleanValue) && 
                        !preg_match('/^(NA|REDACTED|N\/A)$/i', $cleanValue) &&
                        !preg_match('/^\/\//', $cleanValue) &&
                        !preg_match('/^https?:\/\//', $cleanValue) &&
                        strlen($cleanValue) > 2) {
                        
                        // Extract just the status name, removing URLs and references
                        $statusName = preg_replace('/\s+https?:\/\/[^\s]+.*$/', '', $cleanValue);
                        $statusName = preg_replace('/\s+[a-z]+:\/\/[^\s]+.*$/', '', $statusName);
                        $statusName = trim($statusName);
                        
                        if (!empty($statusName) && !in_array($statusName, $data['status'])) {
                            $data['status'][] = $statusName;
                        }
                    }
                }

                // Registrar URL (standard format)
                if (preg_match('/^registrar url/i', $key) && !empty($value)) {
                    $data['registrar_url'] = $value;
                }

                // WHOIS Server
                if (preg_match('/registrar whois server/i', $key) && !empty($value)) {
                    $data['whois_server'] = $value;
                }

                // Abuse Email
                if (preg_match('/abuse.*email/i', $key) && !empty($value)) {
                    $data['abuse_email'] = $value;
                }
                
                // Owner/Registrant
                if (preg_match('/(registrant|owner)/i', $key) && !preg_match('/(email|phone|fax)/i', $key) && !empty($value)) {
                    if ($data['owner'] === 'Unknown') {
                        $data['owner'] = $value;
                    }
                }
            }
        }

        // An answer with nothing of a registration in it says that the name is free, that too
        // many queries were made, or something this parser cannot read
        if (!$registrarFound && $data['status'] === [] && $data['nameservers'] === []
            && $data['expiration_date'] === null && $data['creation_date'] === null) {
            if (self::findLine($whoisData, fn(string $line): bool => self::saysNotRegistered($line)) !== null) {
                $data['status'][] = 'AVAILABLE';
                $data['registrar'] = 'Not Registered';
                return $data;
            }

            $limit = self::findLine($whoisData, fn(string $line): bool => self::saysQueryLimit($line));
            if ($limit !== null) {
                self::$lastErrorType = 'rate_limit';
                self::$lastErrorMessage = "{$whoisServer} is not answering queries for now: \"{$limit}\". Try again later.";
                $logger = new \App\Services\Logger();
                $logger->warning('WHOIS query limit reached', [
                    'domain' => $domain,
                    'server' => $whoisServer,
                    'answer' => $limit
                ]);
                return null;
            }

            $data['lookup_note'] = self::unreadableNote($whoisServer, $whoisData);
        }

        // If no registrar found, set default
        if ($data['registrar'] === null) {
            $data['registrar'] = 'Unknown';
        }
        
        // What the answer shows of the registrant
        $registrant = self::whoisRegistrant($whoisData);
        if ($registrant['privacy'] !== null) {
            $data = self::withRegistrant($data, $registrant);
        }

        $data['raw_data'] = [
            'states' => $data['status'],
            'nameServers' => $data['nameservers'],
        ];

        return $data;
    }

    /**
     * Parse date from various formats
     */
    private function parseDate(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        // Remove common prefixes/suffixes
        $dateString = preg_replace('/^(before|after):/i', '', $dateString);
        $dateString = trim($dateString);

        // ISO-8601 format (e.g. ZARC: 2026-10-15T12:00:00Z or 2026-10-15T12:00:00+00:00)
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/i', $dateString, $m)) {
            return $m[1];
        }

        // Handle DD/MM/YYYY format (European format used by many WHOIS servers like .pt, .es, .fr, etc.)
        // Pattern: DD/MM/YYYY or DD/MM/YYYY HH:MM:SS
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $dateString, $matches)) {
            $day = (int)$matches[1];
            $month = (int)$matches[2];
            $year = (int)$matches[3];
            $timePart = trim($matches[4]);
            
            // If day > 12, it's definitely DD/MM/YYYY format
            // If month > 12, it's definitely MM/DD/YYYY format (invalid day, so swap)
            // If both <= 12, assume DD/MM/YYYY (European format) as it's more common globally for WHOIS
            if ($day > 12 || ($day <= 12 && $month <= 12)) {
                // Treat as DD/MM/YYYY - convert to YYYY-MM-DD format
                $dateString = sprintf('%04d-%02d-%02d', $year, $month, $day);
                if (!empty($timePart)) {
                    $dateString .= ' ' . $timePart;
                }
            }
            // If month > 12, strtotime will fail or we let it try MM/DD/YYYY naturally
        }

        // Try to parse the date
        $timestamp = strtotime($dateString);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Calculate days until domain expiration (calendar days, 0 on the day it expires)
     */
    public function daysUntilExpiration(?string $expirationDate): ?int
    {
        return \App\Helpers\DomainHelper::daysUntilExpiration($expirationDate);
    }

    /**
     * Whether a WHOIS or RDAP status says the domain is not registered.
     *
     * The words are looked for anywhere in the status, as registries wrap them in sentences
     * (.pk: "Not Registered, and may be available if valid"). A status that says the opposite
     * holds the same word, so those are ruled out first: DNS Belgium answers "NOT AVAILABLE"
     * for every registered .be domain. Other wordings of "nothing found" count as well, as
     * "No Object Found" does.
     */
    private static function isAvailableStatus(string $status): bool
    {
        if (preg_match('/\b(?:not|un)\s*available\b|\bnot\s+free\b/i', $status)) {
            return false;
        }

        return stripos($status, 'AVAILABLE') !== false
            || stripos($status, 'FREE') !== false
            || stripos($status, 'NO MATCH') !== false
            || stripos($status, 'NOT FOUND') !== false
            || self::saysNotRegistered($status);
    }

    /**
     * Get domain status based on expiration and WHOIS status
     *
     * @param string|null $expirationDate The domain expiration date
     * @param array $statusArray WHOIS/RDAP status flags
     * @param array $whoisData Full WHOIS data (optional, for additional checks)
     */
    public function getDomainStatus(?string $expirationDate, array $statusArray = [], array $whoisData = []): string
    {
        // Check if domain is available (not registered)
        foreach ($statusArray as $status) {
            if (is_string($status) && self::isAvailableStatus($status)) {
                return 'available';
            }
        }

        // Check for pending delete status (EPP: pendingDelete)
        // Must check before active/registered indicators since a domain can have both
        foreach ($statusArray as $status) {
            if (stripos($status, 'pendingDelete') !== false || 
                stripos($status, 'pending delete') !== false ||
                stripos($status, 'pending_delete') !== false ||
                stripos($status, 'PENDING-DELETE') !== false) {
                return 'pending_delete';
            }
        }

        // Check for redemption period status (EPP: redemptionPeriod)
        // Must check before active/registered indicators
        foreach ($statusArray as $status) {
            if (stripos($status, 'redemptionPeriod') !== false || 
                stripos($status, 'redemption period') !== false ||
                stripos($status, 'redemption_period') !== false ||
                stripos($status, 'REDEMPTION-PERIOD') !== false ||
                stripos($status, 'pendingRestore') !== false) {
                return 'redemption_period';
            }
        }

        // If domain has "active" status but no expiration date, consider it active
        // This handles TLDs like .nl that don't provide expiration dates via RDAP
        foreach ($statusArray as $status) {
            if (stripos($status, 'active') !== false) {
                return 'active';
            }
        }

        // Check for other positive status indicators (domain is registered).
        // "connect" is DENIC's status for a registered .de domain, "Connected" JPRS's for a name like co.jp.
        $registeredIndicators = ['ok', 'registered', 'client', 'server', 'connect'];
        foreach ($statusArray as $status) {
            foreach ($registeredIndicators as $indicator) {
                if (stripos($status, $indicator) !== false) {
                    // Domain has a registered status, check expiration
                    if ($expirationDate === null) {
                        // Has registered status but no expiration date (like .nl domains)
                        return 'active';
                    }
                    break 2; // Exit both loops
                }
            }
        }

        // Check if domain has nameservers (strong indicator it's registered)
        // This handles TLDs like .eu that don't provide status or expiration dates
        if (!empty($whoisData['nameservers']) && count($whoisData['nameservers']) > 0) {
            // Domain has nameservers, so it's registered and active
            return 'active';
        }

        // Check if domain has a registrar that's not "Unknown" or "Not Registered"
        // Another indicator the domain is registered
        if (!empty($whoisData['registrar']) && 
            $whoisData['registrar'] !== 'Unknown' && 
            $whoisData['registrar'] !== 'Not Registered') {
            // Has a valid registrar, likely registered
            if ($expirationDate === null) {
                return 'active';
            }
        }

        // If we have an expiration date, use it to determine status
        if ($expirationDate !== null) {
            $days = $this->daysUntilExpiration($expirationDate);

            if ($days === null) {
                return 'error';
            }

            // Expired from the expiration date itself
            if ($days <= 0) {
                return 'expired';
            }

            if ($days <= 30) {
                return 'expiring_soon';
            }

            return 'active';
        }

        // No expiration date and no clear status indicators
        // Fallback: registrar "Not Registered" means domain is available (e.g. parseWhoisData set it but status was lost in merge)
        if (!empty($whoisData['registrar']) && $whoisData['registrar'] === 'Not Registered') {
            return 'available';
        }

        // Only a registered domain has a registration date, and for .hu that date is all the answer
        // holds. Today's date doesn't count: a value that is only a time of day is read as today.
        $created = $whoisData['creation_date'] ?? null;
        if (is_string($created) && preg_match('/^(?:19[89]\d|2\d{3})-\d{2}-\d{2}$/', $created) && $created < date('Y-m-d')) {
            return 'active';
        }

        // Return error to avoid incorrectly marking registered domains as available
        return 'error';
    }

    /**
     * The domain's status as its last successful lookup left it. A failed lookup saves "error"
     * over the status of a domain that wasn't active, so the saved WHOIS data says what it was:
     * a lookup that works again then doesn't announce the redemption period a second time, or
     * take a dropped domain registered again for a renewal.
     *
     * "available" is read from the saved WHOIS data again as well. Versions up to 1.1.6 took DNS
     * Belgium's "NOT AVAILABLE" for available, so every registered .be domain was saved that way:
     * the check that corrects one then doesn't announce it as newly registered.
     *
     * @param array $domain Domain row as it was before this lookup
     */
    public function getLastKnownStatus(array $domain): string
    {
        $status = (string)($domain['status'] ?? '');
        $whois = json_decode($domain['whois_data'] ?? '', true);
        if (($status !== 'error' && $status !== 'available') || !is_array($whois) || $whois === []) {
            return $status;
        }

        return $this->getDomainStatus($domain['expiration_date'] ?? null, (array)($whois['status'] ?? []), $whois);
    }

    /**
     * Check fresh WHOIS data for a renewal: the same registration now expires at least a month later
     *
     * @param array $domain Domain row as it was before this lookup
     * @param array $whoisData Result of getDomainInfo()
     * @param string $status Status worked out from the new data (getDomainStatus())
     * @return array{old_expiration: string, new_expiration: string, auto_renew_period: bool}|null
     */
    public function detectRenewal(array $domain, array $whoisData, string $status): ?array
    {
        $oldExpiration = $domain['expiration_date'] ?? null;
        $newExpiration = $whoisData['expiration_date'] ?? null;

        if (!$oldExpiration || !$newExpiration || !in_array($status, ['active', 'expiring_soon'], true)) {
            return null;
        }

        // A domain that was free or being deleted can only come back as a new registration
        if (in_array($this->getLastKnownStatus($domain), ['available', 'pending_delete'], true)) {
            return null;
        }

        $extendedBy = (int)round((strtotime($newExpiration) - strtotime($oldExpiration)) / 86400);
        if ($extendedBy < self::RENEWAL_MIN_DAYS || strtotime($newExpiration) < time()) {
            return null;
        }

        // A different creation date means it was deleted and registered again, and so does one
        // after the old expiration date (the saved data of a free domain has no creation date)
        $oldWhois = json_decode($domain['whois_data'] ?? '', true);
        $oldCreated = is_array($oldWhois) ? ($oldWhois['creation_date'] ?? null) : null;
        $newCreated = $whoisData['creation_date'] ?? null;
        if ($oldCreated && $newCreated && $oldCreated !== $newCreated) {
            return null;
        }
        $newCreatedTs = $newCreated ? strtotime((string)$newCreated) : false;
        if ($newCreatedTs !== false && $newCreatedTs > strtotime($oldExpiration)) {
            return null;
        }

        // gTLD registries renew an expired domain on their own; the registrar can still delete it
        $autoRenewPeriod = false;
        foreach ((array)($whoisData['status'] ?? []) as $eppStatus) {
            if (preg_match('/auto[\s_-]*renew[\s_-]*period/i', (string)$eppStatus)) {
                $autoRenewPeriod = true;
                break;
            }
        }

        return [
            'old_expiration' => $oldExpiration,
            'new_expiration' => $newExpiration,
            'auto_renew_period' => $autoRenewPeriod,
        ];
    }

    /**
     * Test domain status detection with a specific domain
     * This method is useful for debugging and testing
     */
    public function testDomainStatus(string $domain): array
    {
        $info = $this->getDomainInfo($domain);
        
        if (!$info) {
            return [
                'domain' => $domain,
                'status' => 'error',
                'message' => 'Failed to retrieve domain information'
            ];
        }

        $status = $this->getDomainStatus($info['expiration_date'], $info['status'], $info);
        
        return [
            'domain' => $domain,
            'status' => $status,
            'info' => $info,
            'message' => 'Domain status determined successfully'
        ];
    }
}
