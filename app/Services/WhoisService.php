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
    private TldRegistry $tldModel;
    
    // Track last error type for rate limit detection
    private static ?string $lastErrorType = null;
    
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
                            if (stripos($status, 'AVAILABLE') !== false) {
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

            // Fallback to WHOIS if RDAP not available or failed
            if (!$whoisServer) {
                $whoisServer = 'whois.iana.org';
            }

            // Get WHOIS data
            $whoisData = $this->queryWhois($domain, $whoisServer);

            if (!$whoisData) {
                self::$lastErrorType = 'no_data';
                $logger = new \App\Services\Logger();
                $logger->warning('No WHOIS data received', [
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
            
            // Special handling for .pro TLD - it doesn't have a WHOIS server in IANA
            if ($tld === 'pro' && !$result['whois_server']) {
                $result['whois_server'] = 'whois.afilias.net';
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
                    // Extract RDAP Server from HTML
                    // Pattern: <b>RDAP Server:</b>  https://rdap.example.com/
                    if (preg_match('/<b>RDAP Server:<\/b>\s*<a[^>]*>(https?:\/\/[^<]+)<\/a>/i', $html, $matches)) {
                        $result['rdap_url'] = rtrim(trim($matches[1]), '/') . '/';
                    } elseif (preg_match('/<b>RDAP Server:<\/b>\s+(https?:\/\/\S+)/i', $html, $matches)) {
                        $result['rdap_url'] = rtrim(trim($matches[1]), '/') . '/';
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
        
        return $this->parseRDAPData($domain, $data, $rdapHost);
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
                        foreach ($entity['vcardArray'][1] as $vcardField) {
                            if (is_array($vcardField) && count($vcardField) >= 4) {
                                if ($vcardField[0] === 'fn') {
                                    $registrarName = $vcardField[3];
                                    // .eu RDAP returns "Name: Company Name" - strip "Name:" prefix
                                    if (preg_match('/^Name:\s*(.+)/i', $registrarName, $matches)) {
                                        $registrarName = trim($matches[1]);
                                    }
                                    $info['registrar'] = $registrarName;
                                } elseif ($vcardField[0] === 'url') {
                                    $info['registrar_url'] = $vcardField[3];
                                }
                            }
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
     * Query WHOIS server
     */
    private function queryWhois(string $domain, string $server, int $port = 43): ?string
    {
        $timeout = 10;

        // Try to connect to WHOIS server
        $fp = @fsockopen($server, $port, $errno, $errstr, $timeout);

        if (!$fp) {
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

        return $response;
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
            'whois_server' => $whoisServer,
            'raw_data' => []
        ];
        
        // Check if domain is not found/available
        $whoisDataLower = strtolower($whoisData);
        // More specific patterns to avoid false positives
        if (preg_match('/^(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower) ||
            preg_match('/^status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower) ||
            preg_match('/^domain status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered|available)$/m', $whoisDataLower)) {
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

        foreach ($lines as $index => $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '%' || $line[0] === '#') {
                continue;
            }

            // Check for section headers (UK format - lines ending with colon, no value)
            if (preg_match('/^([^:]+):\s*$/', $line, $matches)) {
                $currentSection = strtolower(trim($matches[1]));
                
                // For UK domains: Registrar section - next line has the actual registrar
                if ($currentSection === 'registrar' && !$registrarFound && isset($lines[$index + 1])) {
                    $nextLine = trim($lines[$index + 1]);
                    if (!empty($nextLine)) {
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

            // For multi-line sections (UK format), check if we're in a specific section
            if ($currentSection === 'name servers') {
                // Extract nameserver (format: "ns1.example.com    192.168.1.1")
                if (!preg_match('/^(This|--|\d+\.)/', $line)) {
                    $ns = preg_split('/\s+/', $line)[0]; // Get first part (nameserver)
                    if (!empty($ns) && strpos($ns, '.') !== false && !in_array(strtolower($ns), $data['nameservers'])) {
                        $data['nameservers'][] = strtolower($ns);
                    }
                }
            }

            // Parse key-value pairs
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim(strtolower($key));
                $value = trim($value);

                // For UK format - check for URL in registrar section
                if ($key === 'url' && $currentSection === 'registrar' && !empty($value)) {
                    $data['registrar_url'] = $value;
                }

                // Expiration date
                if (preg_match('/(expir|expiry|expire|paid-till|renewal|registry.*expir)/i', $key) && !empty($value)) {
                    $data['expiration_date'] = $this->parseDate($value);
                }

                // Updated date (UK format: "Last updated")
                if (preg_match('/(updated date|last updated|updated)/i', $key) && !empty($value)) {
                    $data['updated_date'] = $this->parseDate($value);
                }

                // Creation date (UK format: "Registered on")
                if (preg_match('/(creat|registered|registered on|creation)/i', $key) && !empty($value)) {
                    $data['creation_date'] = $this->parseDate($value);
                }

                // Registrar (only take the first valid one found) - for standard format
                if (!$registrarFound && preg_match('/^registrar(?!.*url|.*whois|.*iana|.*phone|.*email|.*fax|.*abuse|.*id|.*contact)/i', $key) && !empty($value)) {
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

        // If no registrar found, set default
        if ($data['registrar'] === null) {
            $data['registrar'] = 'Unknown';
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
     * Calculate days until domain expiration
     */
    public function daysUntilExpiration(?string $expirationDate): ?int
    {
        if (!$expirationDate) {
            return null;
        }

        $expiration = strtotime($expirationDate);
        $now = time();
        $diff = $expiration - $now;

        return (int)floor($diff / 86400); // 86400 seconds in a day
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
            if (stripos($status, 'AVAILABLE') !== false || 
                stripos($status, 'FREE') !== false ||
                stripos($status, 'NO MATCH') !== false ||
                stripos($status, 'NOT FOUND') !== false) {
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

        // Check for other positive status indicators (domain is registered)
        $registeredIndicators = ['ok', 'registered', 'client', 'server'];
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

            if ($days < 0) {
                return 'expired';
            }

            if ($days <= 30) {
                return 'expiring_soon';
            }

            return 'active';
        }

        // No expiration date and no clear status indicators
        // This should only happen for newly added domains or error cases
        // Return error to avoid incorrectly marking registered domains as available
        return 'error';
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
