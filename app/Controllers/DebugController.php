<?php

namespace App\Controllers;

use Core\Controller;
use App\Services\WhoisService;

class DebugController extends Controller
{
    /**
     * Show raw WHOIS data for a domain
     */
    public function whois()
    {
        $domain = $_GET['domain'] ?? '';

        if (empty($domain)) {
            $this->view('debug/whois', [
                'domain' => '',
                'title' => 'WHOIS Debug Tool'
            ]);
            return;
        }

        // Log debug tool usage
        $logger = new \App\Services\Logger('debug');
        $logger->info('WHOIS debug tool accessed', [
            'domain' => $domain,
            'user_id' => \Core\Auth::id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Get TLD
        $parts = explode('.', $domain);
        $tld = $parts[count($parts) - 1];
        
        // Use reflection to access the WhoisService's discovery methods
        $whoisService = new WhoisService();
        
        // Use reflection to call private discoverTldServers method
        $reflection = new \ReflectionClass($whoisService);
        $discoverMethod = $reflection->getMethod('discoverTldServers');
        $discoverMethod->setAccessible(true);
        
        // Handle double TLDs
        $doubleTld = null;
        if (count($parts) >= 3) {
            $doubleTld = $parts[count($parts) - 2] . '.' . $tld;
        }
        
        // Try double TLD first, then single TLD
        $discoveryDebug = [];
        $discoveryDebug[] = "=== IANA DISCOVERY PROCESS ===";
        $discoveryDebug[] = "";
        $discoveryDebug[] = "Step 1: Querying IANA WHOIS (whois.iana.org) for TLD information";
        $discoveryDebug[] = "Step 2: Querying IANA RDAP Bootstrap (https://data.iana.org/rdap/dns.json)";
        $discoveryDebug[] = "Step 3: Fallback to IANA HTML page if needed";
        $discoveryDebug[] = "";
        
        if ($doubleTld) {
            $discoveryDebug[] = "Trying double TLD: {$doubleTld}";
            $servers = $discoverMethod->invoke($whoisService, $doubleTld);
            $discoveryDebug[] = "  -> RDAP: " . ($servers['rdap_url'] ?? 'Not found');
            $discoveryDebug[] = "  -> WHOIS: " . ($servers['whois_server'] ?? 'Not found');
            
            if (!$servers['rdap_url'] && !$servers['whois_server']) {
                $discoveryDebug[] = "";
                $discoveryDebug[] = "Double TLD failed, trying single TLD: {$tld}";
                $servers = $discoverMethod->invoke($whoisService, $tld);
                $discoveryDebug[] = "  -> RDAP: " . ($servers['rdap_url'] ?? 'Not found');
                $discoveryDebug[] = "  -> WHOIS: " . ($servers['whois_server'] ?? 'Not found');
            }
        } else {
            $discoveryDebug[] = "Trying single TLD: {$tld}";
            $servers = $discoverMethod->invoke($whoisService, $tld);
            $discoveryDebug[] = "  -> RDAP: " . ($servers['rdap_url'] ?? 'Not found');
            $discoveryDebug[] = "  -> WHOIS: " . ($servers['whois_server'] ?? 'Not found');
        }
        
        $rdapUrl = $servers['rdap_url'];
        $whoisServer = $servers['whois_server'] ?? 'whois.iana.org';
        
        $discoveryDebug[] = "";
        $discoveryDebug[] = "=== FINAL RESULTS ===";
        $discoveryDebug[] = "RDAP URL: " . ($rdapUrl ?? 'Not available - will use WHOIS fallback');
        $discoveryDebug[] = "WHOIS Server: {$whoisServer}";
        $discoveryDebug[] = "";
        
        if (!$rdapUrl) {
            $discoveryDebug[] = "NOTE: No RDAP server found in IANA sources. Will use traditional WHOIS.";
        }
        
        // Get raw response - try RDAP first, then WHOIS
        $response = '';
        $parsedData = [];
        $server = $whoisServer;
        $rdapSucceeded = false;
        
        // Add discovery debug info
        $response .= "=== TLD DISCOVERY DEBUG ===\n\n";
        foreach ($discoveryDebug as $debug) {
            $response .= $debug . "\n";
        }
        $response .= "\n";
        
        // Try RDAP first if available
        if ($rdapUrl) {
            $server = parse_url($rdapUrl, PHP_URL_HOST) . ' (RDAP)';
            
            // Construct full RDAP URL
            // RDAP standard format: {base_url}domain/{domain_name}
            if (!preg_match('/domain\/$/', $rdapUrl)) {
                $fullRdapUrl = rtrim($rdapUrl, '/') . '/domain/' . strtolower($domain);
            } else {
                $fullRdapUrl = rtrim($rdapUrl, '/') . '/' . strtolower($domain);
            }
            
            // Query RDAP
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullRdapUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/rdap+json, application/json, */*',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]);
            
            $rdapResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            
            // Debug: Log the actual cURL request details
            $logger->debug('RDAP cURL request details', [
                'url' => $fullRdapUrl,
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'response_length' => strlen($rdapResponse),
                'total_time' => $curlInfo['total_time'] ?? 'unknown',
                'primary_ip' => $curlInfo['primary_ip'] ?? 'unknown',
                'response_preview' => substr($rdapResponse, 0, 200) . (strlen($rdapResponse) > 200 ? '...' : '')
            ]);
            
            if (($httpCode === 200 || $httpCode === 404) && $rdapResponse) {
                // Pretty print JSON
                $rdapData = json_decode($rdapResponse, true);
                
                // Check if RDAP returned an error in the JSON or if domain is available
                $isDomainAvailable = false;
                if ($rdapData && isset($rdapData['errorCode'])) {
                    $rdapSucceeded = true; // HTTP succeeded, but domain not found
                    $response .= "\n=== RDAP QUERY SUCCESS (Domain Not Found) ===\n\n";
                    $response .= "RDAP URL: {$fullRdapUrl}\n";
                    $response .= "HTTP Status: {$httpCode}\n";
                    $response .= "RDAP Error Code: {$rdapData['errorCode']}\n";
                    $response .= "Title: " . ($rdapData['title'] ?? 'N/A') . "\n";
                    $response .= "Description: " . (isset($rdapData['description']) ? implode(', ', (array)$rdapData['description']) : 'N/A') . "\n\n";
                    
                    if ($rdapData['errorCode'] == 404) {
                        $response .= "✓ Domain is AVAILABLE (not registered)\n\n";
                        $parsedData[] = ['key' => 'Status', 'value' => 'AVAILABLE'];
                        $parsedData[] = ['key' => 'Registrar', 'value' => 'Not Registered'];
                        $isDomainAvailable = true;
                    }
                    
                    $response .= "--- RDAP JSON RESPONSE ---\n\n";
                    $response .= json_encode($rdapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } elseif ($rdapData && isset($rdapData['status']) && is_array($rdapData['status'])) {
                    // Check if domain status indicates it's available
                    foreach ($rdapData['status'] as $status) {
                        if (stripos($status, 'free') !== false || stripos($status, 'available') !== false) {
                            $rdapSucceeded = true;
                            $response .= "\n=== RDAP QUERY SUCCESS (Domain Available) ===\n\n";
                            $response .= "RDAP URL: {$fullRdapUrl}\n";
                            $response .= "HTTP Status: {$httpCode}\n";
                            $response .= "Domain Status: " . implode(', ', $rdapData['status']) . "\n\n";
                            $response .= "✓ Domain is AVAILABLE (not registered)\n\n";
                            $parsedData[] = ['key' => 'Status', 'value' => 'AVAILABLE'];
                            $parsedData[] = ['key' => 'Registrar', 'value' => 'Not Registered'];
                            $isDomainAvailable = true;
                            
                            $response .= "--- RDAP JSON RESPONSE ---\n\n";
                            $response .= json_encode($rdapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            break;
                        }
                    }
                } elseif ($httpCode === 404 && $rdapData) {
                    // Handle HTTP 404 with valid JSON response (like hosteroid.nl)
                    $rdapSucceeded = true;
                    $response .= "\n=== RDAP QUERY SUCCESS (HTTP 404 with JSON) ===\n\n";
                    $response .= "RDAP URL: {$fullRdapUrl}\n";
                    $response .= "HTTP Status: {$httpCode}\n";
                    $response .= "Note: HTTP 404 but received valid JSON response\n\n";
                    
                    // Check if it contains "free" status
                    if (isset($rdapData['status']) && is_array($rdapData['status'])) {
                        foreach ($rdapData['status'] as $status) {
                            if (stripos($status, 'free') !== false) {
                                $response .= "✓ Domain is AVAILABLE (not registered)\n\n";
                                $parsedData[] = ['key' => 'Status', 'value' => 'AVAILABLE'];
                                $parsedData[] = ['key' => 'Registrar', 'value' => 'Not Registered'];
                                $isDomainAvailable = true;
                                break;
                            }
                        }
                    }
                    
                    $response .= "--- RDAP JSON RESPONSE ---\n\n";
                    $response .= json_encode($rdapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
                
                if (!$isDomainAvailable) {
                    $rdapSucceeded = true;
                    $response .= "\n=== RDAP QUERY SUCCESS ===\n\n";
                    $response .= "RDAP URL: {$fullRdapUrl}\n";
                    $response .= "HTTP Status: {$httpCode}\n\n";
                    $response .= "--- RDAP JSON RESPONSE ---\n\n";
                    
                    if ($rdapData) {
                        $response .= json_encode($rdapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        
                        // Parse some key fields for the table
                        if (isset($rdapData['entities'])) {
                            foreach ($rdapData['entities'] as $entity) {
                                if (isset($entity['vcardArray'][1])) {
                                    foreach ($entity['vcardArray'][1] as $field) {
                                        if (is_array($field) && count($field) >= 4) {
                                            $parsedData[] = [
                                                'key' => $field[0],
                                                'value' => is_array($field[3]) ? implode(', ', $field[3]) : $field[3]
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                        
                        if (isset($rdapData['events'])) {
                            foreach ($rdapData['events'] as $event) {
                                $parsedData[] = [
                                    'key' => ucfirst($event['eventAction'] ?? 'event'),
                                    'value' => $event['eventDate'] ?? 'N/A'
                                ];
                            }
                        }
                    } else {
                        $response .= $rdapResponse;
                    }
                }
            } elseif ($httpCode === 404 && $rdapResponse) {
                // Handle 404 responses as domain not found
                $rdapData = json_decode($rdapResponse, true);
                if ($rdapData && isset($rdapData['errorCode']) && $rdapData['errorCode'] == 404) {
                    $rdapSucceeded = true; // Treat as successful domain not found
                    $response .= "\n=== RDAP QUERY SUCCESS (Domain Not Found) ===\n\n";
                    $response .= "RDAP URL: {$fullRdapUrl}\n";
                    $response .= "HTTP Status: {$httpCode}\n";
                    $response .= "RDAP Error Code: {$rdapData['errorCode']}\n";
                    $response .= "Title: " . ($rdapData['title'] ?? 'N/A') . "\n";
                    $response .= "Description: " . (isset($rdapData['description']) ? implode(', ', (array)$rdapData['description']) : 'N/A') . "\n\n";
                    
                    $response .= "✓ Domain is AVAILABLE (not registered)\n\n";
                    $parsedData[] = ['key' => 'Status', 'value' => 'AVAILABLE'];
                    $parsedData[] = ['key' => 'Registrar', 'value' => 'Not Registered'];
                    
                    $response .= "--- RDAP JSON RESPONSE ---\n\n";
                    $response .= json_encode($rdapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    $response .= "\n=== RDAP QUERY FAILED ===\n\n";
                    $response .= "RDAP URL: {$fullRdapUrl}\n";
                    $response .= "HTTP Status: {$httpCode}\n";
                    $response .= "\nError: Could not retrieve RDAP data\n\n";
                }
            } else {
                $response .= "\n=== RDAP QUERY FAILED ===\n\n";
                $response .= "RDAP URL: {$fullRdapUrl}\n";
                $response .= "HTTP Status: {$httpCode}\n";
                
                if ($curlError) {
                    $response .= "cURL Error: {$curlError}\n";
                }
                
                // Show detailed cURL info
                $response .= "\ncURL Debug Info:\n";
                $response .= "  - Total Time: " . ($curlInfo['total_time'] ?? 'N/A') . "s\n";
                $response .= "  - Name Lookup Time: " . ($curlInfo['namelookup_time'] ?? 'N/A') . "s\n";
                $response .= "  - Connect Time: " . ($curlInfo['connect_time'] ?? 'N/A') . "s\n";
                $response .= "  - Primary IP: " . ($curlInfo['primary_ip'] ?? 'N/A') . "\n";
                
                if ($httpCode === 0) {
                    $response .= "\nNote: HTTP Status 0 usually means:\n";
                    $response .= "  - SSL certificate verification failed\n";
                    $response .= "  - Connection timeout\n";
                    $response .= "  - DNS resolution failed\n";
                    $response .= "  - URL is malformed\n";
                }
                
                $response .= "\nError: Could not retrieve RDAP data\n\n";
            }
        }
        
        // If RDAP failed or not available, query WHOIS
        if (!$rdapSucceeded && $whoisServer) {
            if ($rdapUrl) {
                $response .= "\n\n=== WHOIS FALLBACK (RDAP Failed) ===\n\n";
            } else {
                $response = "=== WHOIS QUERY ===\n\n";
                $server = $whoisServer;
            }
            
            $response .= "WHOIS Server: {$whoisServer}\n\n";
            $response .= "--- WHOIS TEXT RESPONSE ---\n\n";
            
            $fp = @fsockopen($whoisServer, 43, $errno, $errstr, 10);
            
            if ($fp) {
                fputs($fp, $domain . "\r\n");
                $whoisResponse = '';
                while (!feof($fp)) {
                    $whoisResponse .= fgets($fp, 128);
                }
                fclose($fp);
                
                $response .= $whoisResponse;
                
                // Check if domain is not found/available (using improved pattern)
                $whoisResponseLower = strtolower($whoisResponse);
                if (preg_match('/^(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered)$/m', $whoisResponseLower) ||
                    preg_match('/^status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered)$/m', $whoisResponseLower) ||
                    preg_match('/^domain status:\s*(not found|no match|no entries found|no data found|domain not found|no such domain|available for registration|does not exist|queried object does not exist|is free|not registered)$/m', $whoisResponseLower)) {
                    $response .= "\n\n=== DOMAIN STATUS DETECTED ===\n";
                    $response .= "✓ Domain is AVAILABLE (not registered)\n";
                    $parsedData[] = ['key' => 'Status', 'value' => 'AVAILABLE'];
                    $parsedData[] = ['key' => 'Registrar', 'value' => 'Not Registered'];
                } else {
                    // Parse key-value pairs from WHOIS
                    $lines = explode("\n", $whoisResponse);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || $line[0] === '%' || $line[0] === '#') {
                            continue;
                        }
                        if (strpos($line, ':') !== false) {
                            list($key, $value) = explode(':', $line, 2);
                            $parsedData[] = [
                                'key' => trim($key),
                                'value' => trim($value)
                            ];
                        }
                    }
                }
            } else {
                $response .= "Error: Could not connect to WHOIS server: $errstr ($errno)";
            }
        }
        
        // Get parsed info using WhoisService (this will use the actual logic with WHOIS fallback)
        $info = $whoisService->getDomainInfo($domain);

        // If we got info from WhoisService but debug tool didn't show RDAP success, 
        // it means WhoisService used WHOIS fallback for expiration date
        $whoisFallbackUsed = false;
        if ($info && !$rdapSucceeded) {
            $whoisFallbackUsed = true;
        } elseif ($info && $rdapSucceeded && empty($info['expiration_date'])) {
            // RDAP succeeded but no expiration date, WhoisService should have tried WHOIS fallback
            $whoisFallbackUsed = true;
        }

        // Add note about WHOIS fallback if it was used
        if ($whoisFallbackUsed) {
            $response .= "\n\n=== WHOIS FALLBACK USED BY WHOISSERVICE ===\n\n";
            $response .= "Note: The WhoisService automatically used WHOIS fallback to get missing data (like expiration dates).\n";
            $response .= "This is the actual data that will be saved to the database.\n\n";
        }

        // Log debug results
        $logger->info('WHOIS debug completed', [
            'domain' => $domain,
            'tld' => $tld,
            'server' => $server,
            'rdap_succeeded' => $rdapSucceeded,
            'whois_fallback_used' => $whoisFallbackUsed,
            'parsed_status' => $info['status'] ?? 'unknown',
            'parsed_registrar' => $info['registrar'] ?? 'unknown',
            'parsed_expiration' => $info['expiration_date'] ?? 'unknown',
            'user_id' => \Core\Auth::id()
        ]);

        $this->view('debug/whois', [
            'domain' => $domain,
            'server' => $server,
            'tld' => $tld,
            'response' => $response,
            'parsedData' => $parsedData,
            'info' => $info,
            'title' => 'WHOIS Debug - ' . $domain
        ]);
    }
}

