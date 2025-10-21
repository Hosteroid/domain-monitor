<?php

namespace App\Services;

use Exception;
use App\Models\TldRegistry;

class WhoisService
{
    // Cache for discovered TLD servers to avoid repeated IANA queries
    private static array $tldCache = [];
    private TldRegistry $tldModel;

    public function __construct()
    {
        $this->tldModel = new TldRegistry();
    }

    /**
     * Get domain information via WHOIS or RDAP
     */
    public function getDomainInfo(string $domain): ?array
    {
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
                return null;
            }

            // Check if we got a referral to another WHOIS server
            $referralServer = $this->extractReferralServer($whoisData);
            if ($referralServer && $referralServer !== $whoisServer) {
                // Query the referred server
                $whoisData = $this->queryWhois($domain, $referralServer);
                if (!$whoisData) {
                    return null;
                }
            }

            // Parse the response
            $info = $this->parseWhoisData($domain, $whoisData, $referralServer ?? $whoisServer);

            return $info;

        } catch (Exception $e) {
            error_log("WHOIS lookup failed for $domain: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Discover RDAP and WHOIS servers for a TLD using TLD registry data
     * Returns array with 'rdap_url' and 'whois_server' keys
     */
    private function discoverTldServers(string $tld): array
    {
        // Check cache first
        if (isset(self::$tldCache[$tld])) {
            return self::$tldCache[$tld];
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
                self::$tldCache[$tld] = $result;
                return $result;
            }

            // Fallback: Query IANA directly if not in our registry
            // This maintains backward compatibility and handles new TLDs
            $response = $this->queryWhois($tld, 'whois.iana.org');
            
            if (!$response) {
                self::$tldCache[$tld] = $result;
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
            self::$tldCache[$tld] = $result;

            return $result;
        } catch (Exception $e) {
            self::$tldCache[$tld] = $result;
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
            'Accept: application/rdap+json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Handle 404 responses as domain not found
        if ($httpCode === 404 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['errorCode']) && $data['errorCode'] == 404) {
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
                            if ($vcardField[0] === 'fn') {
                                $info['registrar'] = $vcardField[3];
                            } elseif ($vcardField[0] === 'url') {
                                $info['registrar_url'] = $vcardField[3];
                            }
                        }
                    }
                    
                    // Check for abuse contact in nested entities
                    if (isset($entity['entities']) && is_array($entity['entities'])) {
                        foreach ($entity['entities'] as $subEntity) {
                            if (in_array('abuse', $subEntity['roles'] ?? [])) {
                                if (isset($subEntity['vcardArray'][1])) {
                                    foreach ($subEntity['vcardArray'][1] as $vcardField) {
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
            error_log("WHOIS connection failed to $server: $errstr ($errno)");
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
     * Parse WHOIS data
     */
    private function parseWhoisData(string $domain, string $whoisData, string $whoisServer = 'Unknown'): array
    {
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
        if (preg_match('/not found|no match|no entries found|no data found|domain not found|no such domain|not registered|available for registration|does not exist|queried object does not exist/i', $whoisDataLower)) {
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
                if (preg_match('/(expir|expiry|expire|paid-till|renewal)/i', $key) && !empty($value)) {
                    $data['expiration_date'] = $this->parseDate($value);
                }

                // Updated date (UK format: "Last updated")
                if (preg_match('/(updated date|last updated)/i', $key) && !empty($value)) {
                    $data['updated_date'] = $this->parseDate($value);
                }

                // Creation date (UK format: "Registered on")
                if (preg_match('/(creat|registered|registered on)/i', $key) && !empty($value)) {
                    $data['creation_date'] = $this->parseDate($value);
                }

                // Registrar (only take the first valid one found) - for standard format
                if (!$registrarFound && preg_match('/^registrar(?!.*url|.*whois|.*iana|.*phone|.*email|.*fax|.*abuse|.*id|.*contact)/i', $key) && !empty($value)) {
                    // Skip if it looks like a phone number, email, or ID
                    if (!preg_match('/^[\+\d\.\s\(\)-]+$/', $value) && 
                        !preg_match('/@/', $value) && 
                        !preg_match('/^\d+$/', $value) &&
                        strlen($value) > 3) {
                        $data['registrar'] = $value;
                        $registrarFound = true;
                    }
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
                    if (!in_array($value, $data['status'])) {
                        $data['status'][] = $value;
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
     */
    public function getDomainStatus(?string $expirationDate, array $statusArray = []): string
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

        // Also check if expiration date is null and no status indicates it's registered
        if ($expirationDate === null && empty($statusArray)) {
            return 'available';
        }

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

        $status = $this->getDomainStatus($info['expiration_date'], $info['status']);
        
        return [
            'domain' => $domain,
            'status' => $status,
            'info' => $info,
            'message' => 'Domain status determined successfully'
        ];
    }
}
