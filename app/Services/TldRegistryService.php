<?php

namespace App\Services;

use App\Models\TldRegistry;
use App\Models\TldImportLog;
use App\Services\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TldRegistryService
{
    private Client $httpClient;
    private TldRegistry $tldModel;
    private TldImportLog $importLogModel;
    private Logger $logger;

    // IANA URLs
    private const IANA_RDAP_URL = 'https://data.iana.org/rdap/dns.json';
    private const IANA_TLD_BASE_URL = 'https://www.iana.org/domains/root/db/';
    private const IANA_TLD_LIST_URL = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
    private const IANA_RDAP_DOMAIN_URL = 'https://rdap.iana.org/domain/';

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 15, // Reduced for faster processing
            'connect_timeout' => 5, // Reduced for faster processing
            'verify' => true, // Enable SSL verification
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https']
            ],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Cache-Control' => 'max-age=0'
            ]
        ]);
        $this->tldModel = new TldRegistry();
        $this->importLogModel = new TldImportLog();
        $this->logger = new Logger('tld_import');
    }

    /**
     * Get HTTP client configured for JSON requests
     */
    private function getJsonClient(): Client
    {
        return new Client([
            'timeout' => 15, // Reduced for faster processing
            'connect_timeout' => 5, // Reduced for faster processing
            'verify' => true,
            'allow_redirects' => [
                'max' => 3,
                'strict' => true,
                'referer' => false,
                'protocols' => ['https']
            ],
            'headers' => [
                'User-Agent' => 'DomainMonitor/1.0 (TLD Registry Bot; compatible with IANA RDAP)',
                'Accept' => 'application/json, application/rdap+json, */*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',  // Removed 'br' (brotli) - not supported on CloudLinux 8
                'Connection' => 'keep-alive',
                'Cache-Control' => 'no-cache'
            ],
            'http_errors' => false, // Don't throw exceptions on HTTP error codes
            'retry' => [
                'max' => 2, // Reduced retries for speed
                'delay' => 500, // 0.5 second delay between retries (reduced)
                'multiplier' => 1.5
            ]
        ]);
    }

    /**
     * Get HTTP client configured for HTML requests
     */
    private function getHtmlClient(): Client
    {
        return new Client([
            'timeout' => 8, // Further reduced for faster processing
            'connect_timeout' => 3, // Further reduced for faster processing
            'verify' => true,
            'allow_redirects' => [
                'max' => 3, // Reduced redirects
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https']
            ],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',  // Removed 'br' (brotli) - not supported on CloudLinux 8
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Cache-Control' => 'max-age=0'
            ],
            'http_errors' => false, // Don't throw exceptions on HTTP error codes
            'retry' => [
                'max' => 0, // No retries for HTML to avoid timeouts
                'delay' => 0,
                'multiplier' => 1
            ]
        ]);
    }

    /**
     * Import TLD list from IANA
     */
    public function importTldList(): array
    {
        $logId = $this->importLogModel->startImport('tld_list');
        $stats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

        try {
            // Fetch TLD list from IANA
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get(self::IANA_TLD_LIST_URL);
            
            // Check response status
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to fetch TLD list: HTTP ' . $response->getStatusCode());
            }
            
            $content = $response->getBody()->getContents();
            
            // Parse the content to extract version and TLDs
            $lines = explode("\n", $content);
            $version = null;
            $lastUpdated = null;
            $tlds = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip empty lines
                if (empty($line)) {
                    continue;
                }
                
                // Extract version and timestamp from header
                if (strpos($line, '# Version') === 0) {
                    if (preg_match('/# Version (\d+), Last Updated (.+)/', $line, $matches)) {
                        $version = $matches[1];
                        $lastUpdatedRaw = $matches[2];
                        
                        // Convert the timestamp to a proper format
                        try {
                            $lastUpdated = date('Y-m-d H:i:s', strtotime($lastUpdatedRaw));
                            if ($lastUpdated === '1970-01-01 00:00:00') {
                                // If strtotime fails, keep the raw value
                                $lastUpdated = $lastUpdatedRaw;
                            }
                        } catch (\Exception $e) {
                            $lastUpdated = $lastUpdatedRaw;
                        }
                    }
                    continue;
                }
                
                // Skip comment lines
                if (strpos($line, '#') === 0) {
                    continue;
                }
                
                // Add TLD to list (ensure it starts with dot)
                $tld = '.' . strtolower($line);
                $tlds[] = $tld;
            }
            
            if (empty($tlds)) {
                throw new \Exception('No TLDs found in the response');
            }
            
            $stats['total_tlds'] = count($tlds);
            
            // Normalize last updated date to UTC format
            $normalizedLastUpdated = $this->normalizeDate($lastUpdated);
            
            // Update log with version and timestamp
            $this->importLogModel->update($logId, [
                'iana_publication_date' => $normalizedLastUpdated,
                'version' => $version
            ]);
            
            // Process each TLD
            foreach ($tlds as $tld) {
                try {
                    $result = $this->processTldEntry($tld);
                    
                    if ($result['is_new']) {
                        $stats['new_tlds']++;
                    } else {
                        $stats['updated_tlds']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed_tlds']++;
                    $this->logger->error("Failed to process TLD $tld: " . $e->getMessage());
                }
            }

            $this->importLogModel->completeImport($logId, $stats);
            
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, $stats, 'failed', $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Import RDAP data from IANA
     */
    public function importRdapData(): array
    {
        $logId = $this->importLogModel->startImport('rdap');
        $stats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

        try {
            // Fetch RDAP data from IANA using JSON client
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get(self::IANA_RDAP_URL);
            
            // Check response status
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to fetch RDAP data: HTTP ' . $response->getStatusCode());
            }
            
            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data || !isset($data['services'])) {
                throw new \Exception('Invalid RDAP data format or empty response');
            }

            $publicationDate = $data['publication'] ?? null;
            $services = $data['services'] ?? [];

            // Normalize publication date to UTC format before saving
            $normalizedPublicationDate = $this->normalizeDate($publicationDate);

            // Update log with publication date
            $this->importLogModel->update($logId, ['iana_publication_date' => $normalizedPublicationDate]);

            foreach ($services as $service) {
                $tlds = $service[0] ?? []; // TLD patterns
                $rdapServers = $service[1] ?? []; // RDAP servers

                foreach ($tlds as $tld) {
                    $stats['total_tlds']++;
                    
                    try {
                        $result = $this->processTldRdapData($tld, $rdapServers, $normalizedPublicationDate);
                        
                        if ($result['is_new']) {
                            $stats['new_tlds']++;
                        } else {
                            $stats['updated_tlds']++;
                        }
                    } catch (\Exception $e) {
                        $stats['failed_tlds']++;
                        $this->logger->error("Failed to process TLD $tld: " . $e->getMessage());
                    }
                }
            }

            $this->importLogModel->completeImport($logId, $stats);
            
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, $stats, 'failed', $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Import WHOIS data for TLDs missing WHOIS servers or needing updates
     */
    public function importWhoisDataForMissingTlds(): array
    {
        $logId = $this->importLogModel->startImport('whois');
        $stats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

        try {
            // Get TLDs that need WHOIS data (missing WHOIS server or old data)
            $tldsNeedingWhois = $this->getTldsNeedingWhoisData();
            
            foreach ($tldsNeedingWhois as $index => $tld) {
                $stats['total_tlds']++;
                
                try {
                    $result = $this->fetchWhoisDataFromRdap($tld['tld']);
                    
                    if ($result) {
                        $this->tldModel->update($tld['id'], $result);
                        $stats['updated_tlds']++;
                    } else {
                        $stats['failed_tlds']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed_tlds']++;
                    $this->logger->error("Failed to fetch WHOIS data for TLD {$tld['tld']}: " . $e->getMessage());
                }
                
                // Add delay between requests to be respectful to IANA servers
                if ($index < count($tldsNeedingWhois) - 1) {
                    usleep(500000); // 0.5 second delay
                }
            }

            $this->importLogModel->completeImport($logId, $stats);
            
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, $stats, 'failed', $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Get TLDs that need WHOIS data (missing or outdated)
     */
    private function getTldsNeedingWhoisData(int $limit = 100, int $startFromId = 0): array
    {
        // Process ALL TLDs systematically (A to Z, or ID 1 to last ID)
        // This ensures we get complete data for every TLD, even if some don't have WHOIS/RDAP data
        $sql = "SELECT * FROM tld_registry 
                WHERE is_active = 1 
                AND id > " . intval($startFromId) . "
                ORDER BY 
                    CASE 
                        WHEN (whois_server IS NULL OR whois_server = '') AND (registry_url IS NULL OR registry_url = '') THEN 0
                        WHEN whois_server IS NULL OR whois_server = '' THEN 1
                        WHEN registry_url IS NULL OR registry_url = '' THEN 2
                        WHEN registration_date IS NULL OR record_last_updated IS NULL THEN 3
                        ELSE 4
                    END,
                    id ASC
                LIMIT " . intval($limit);
        
        // Use the model's database connection through a public method
        return $this->tldModel->query($sql);
    }

    /**
     * Get the highest ID of processed TLDs for this import session
     */
    private function getLastProcessedTldId(int $logId): int
    {
        // Get the last processed TLD ID from the import log details
        $log = $this->importLogModel->find($logId);
        $details = $log['details'] ? json_decode($log['details'], true) : [];
        
        $lastId = $details['last_processed_id'] ?? 0;
        
        $this->logger->debug("Retrieved last_processed_id from database", [
            'log_id' => $logId,
            'last_processed_id' => $lastId,
            'details_raw' => $log['details'] ?? 'NULL',
            'details_parsed_keys' => array_keys($details)
        ]);
        
        return $lastId;
    }

    /**
     * Set the last processed TLD ID for this import session
     */
    private function setLastProcessedTldId(int $logId, int $lastId): void
    {
        $log = $this->importLogModel->find($logId);
        $details = $log['details'] ? json_decode($log['details'], true) : [];
        $details['last_processed_id'] = $lastId;
        
        $this->logger->debug("Updating last_processed_id", [
            'log_id' => $logId,
            'last_id' => $lastId,
            'full_details' => $details
        ]);
        
        // Fix: Pass details in the data array directly to avoid empty array issue
        $updateResult = $this->importLogModel->update($logId, ['details' => json_encode($details)]);
        
        // Verify the update worked
        if (!$updateResult) {
            $this->logger->error("Failed to update last_processed_id in database!", [
                'log_id' => $logId,
                'last_id' => $lastId
            ]);
        } else {
            // Verify by reading it back
            $verifyLog = $this->importLogModel->find($logId);
            $verifyDetails = $verifyLog['details'] ? json_decode($verifyLog['details'], true) : [];
            $verifiedId = $verifyDetails['last_processed_id'] ?? 0;
            
            if ($verifiedId !== $lastId) {
                $this->logger->critical("Database verification FAILED! last_processed_id mismatch", [
                    'expected' => $lastId,
                    'actual' => $verifiedId,
                    'log_id' => $logId
                ]);
            } else {
                $this->logger->debug("Database update verified successfully", [
                    'log_id' => $logId,
                    'verified_id' => $verifiedId
                ]);
            }
        }
    }

    /**
     * Fetch registry URL from IANA RDAP API
     */
    private function fetchRegistryUrlFromRdap(string $tld): ?string
    {
        $tldForUrl = ltrim($tld, '.');
        $url = self::IANA_RDAP_DOMAIN_URL . $tldForUrl;

        try {
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get($url);
            
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (isset($data['links']) && is_array($data['links'])) {
                foreach ($data['links'] as $link) {
                    if (isset($link['rel']) && $link['rel'] === 'related' && 
                        isset($link['title']) && $link['title'] === 'Registration URL') {
                        return $link['href'] ?? null;
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch RDAP registry URL for TLD $tld: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Get count of TLDs that need WHOIS data
     */
    public function getTldsNeedingWhoisCount(?int $logId = null): int
    {
        if ($logId) {
            // For a specific import session, count TLDs that haven't been processed yet
            $lastProcessedId = $this->getLastProcessedTldId($logId);
            $sql = "SELECT COUNT(*) as count FROM tld_registry WHERE is_active = 1 AND id > " . intval($lastProcessedId);
        } else {
            // Count ALL active TLDs since we process them all systematically
            $sql = "SELECT COUNT(*) as count FROM tld_registry WHERE is_active = 1";
        }
        
        $result = $this->tldModel->query($sql);
        return $result[0]['count'] ?? 0;
    }

    /**
     * Start progressive import for any type
     */
    public function startProgressiveImport(string $importType): array
    {
        $logId = $this->importLogModel->startImport($importType);
        
        switch ($importType) {
            case 'tld_list':
                $total = $this->getTotalTldsFromIana();
                $message = "Started TLD list import";
                break;
                
            case 'rdap':
                $total = $this->tldModel->getStatistics()['total'];
                $message = "Started RDAP import for {$total} TLDs";
                break;
                
            case 'whois':
                $total = $this->getTldsNeedingWhoisCount();
                if ($total === 0) {
                    return [
                        'status' => 'complete',
                        'message' => 'All TLDs already have WHOIS data',
                        'total' => 0,
                        'processed' => 0,
                        'remaining' => 0
                    ];
                }
                $message = "Started WHOIS import for {$total} TLDs";
                break;
                
            case 'check_updates':
                $total = 2; // TLD list + RDAP
                $message = "Started update check";
                break;
                
            case 'complete_workflow':
                $total = 4; // TLD list + RDAP + WHOIS + Registry URLs
                $message = "Started complete TLD import workflow";
                break;
                
            default:
                throw new \Exception("Unknown import type: {$importType}");
        }
        
        return [
            'status' => 'started',
            'log_id' => $logId,
            'import_type' => $importType,
            'total' => $total,
            'processed' => 0,
            'remaining' => $total,
            'message' => $message
        ];
    }

    /**
     * Get total TLDs from IANA (for TLD list import)
     */
    private function getTotalTldsFromIana(): int
    {
        try {
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get(self::IANA_TLD_LIST_URL);
            
            if ($response->getStatusCode() !== 200) {
                return 0;
            }
            
            $content = $response->getBody()->getContents();
            $lines = explode("\n", $content);
            $count = 0;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '#') !== 0) {
                    $count++;
                }
            }
            
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Process next batch of imports (universal)
     */
    public function processNextBatch(int $logId): array
    {
        // Get import type from log
        $log = $this->importLogModel->find($logId);
        if (!$log) {
            return ['status' => 'error', 'message' => 'Import log not found'];
        }
        
        $importType = $log['import_type'];
        
        switch ($importType) {
            case 'tld_list':
                return $this->processTldListBatch($logId);
            case 'rdap':
                return $this->processRdapBatch($logId);
            case 'whois':
                return $this->processWhoisBatch($logId);
            case 'check_updates':
                return $this->processCheckUpdatesBatch($logId);
            case 'complete_workflow':
                return $this->processCompleteWorkflowBatch($logId);
            default:
                return ['status' => 'error', 'message' => 'Unknown import type'];
        }
    }

    /**
     * Process TLD list batch
     */
    private function processTldListBatch(int $logId): array
    {
        // Get current progress from log
        $log = $this->importLogModel->find($logId);
        $currentProgress = $log['details'] ? json_decode($log['details'], true) : ['processed' => 0, 'failed' => 0];
        
        try {
            // Process TLD list in one go (it's already fast)
            $stats = $this->importTldList();
            
            // Update progress
            $currentProgress['processed'] += $stats['new_tlds'] + $stats['updated_tlds'];
            $currentProgress['failed'] += $stats['failed_tlds'];
            
            $this->importLogModel->completeImport($logId, $stats, 'completed', null, $currentProgress);
            
            return [
                'status' => 'complete',
                'log_id' => $logId,
                'total' => $stats['total_tlds'],
                'processed' => $currentProgress['processed'],
                'failed' => $currentProgress['failed'],
                'remaining' => 0,
                'message' => 'TLD list import completed'
            ];
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => 0,
                'new_tlds' => 0,
                'updated_tlds' => 0,
                'failed_tlds' => 1
            ], 'failed', $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'TLD list import failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process RDAP batch
     */
    private function processRdapBatch(int $logId): array
    {
        // Get current progress from log
        $log = $this->importLogModel->find($logId);
        $currentProgress = $log['details'] ? json_decode($log['details'], true) : ['processed' => 0, 'failed' => 0, 'total' => 0];
        
        // If this is the first batch, get total count
        if ($currentProgress['total'] == 0) {
            $currentProgress['total'] = $this->tldModel->getStatistics()['total'];
        }
        
        try {
            // Process RDAP data in one go (it's already fast)
            $stats = $this->importRdapData();
            
            // Update progress
            $currentProgress['processed'] += $stats['updated_tlds'];
            $currentProgress['failed'] += $stats['failed_tlds'];
            
            $this->importLogModel->completeImport($logId, $stats, 'completed', null, $currentProgress);
            
            return [
                'status' => 'complete',
                'log_id' => $logId,
                'total' => $currentProgress['total'],
                'processed' => $currentProgress['processed'],
                'failed' => $currentProgress['failed'],
                'remaining' => 0,
                'message' => 'RDAP import completed'
            ];
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => 0,
                'new_tlds' => 0,
                'updated_tlds' => 0,
                'failed_tlds' => 1
            ], 'failed', $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'RDAP import failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process WHOIS batch
     */
    private function processWhoisBatch(int $logId): array
    {
        $batchStartTime = microtime(true);
        $this->logger->startOperation("WHOIS Batch Processing", ['log_id' => $logId]);
        
        // Get current progress from log
        $log = $this->importLogModel->find($logId);
        $currentProgress = $log['details'] ? json_decode($log['details'], true) : ['processed' => 0, 'failed' => 0, 'total' => 0];
        
        $this->logger->info("Current progress retrieved", $currentProgress);
        
        // If this is the first batch, get total count
        if ($currentProgress['total'] == 0) {
            $currentProgress['total'] = $this->getTldsNeedingWhoisCount();
            $this->logger->info("First batch - Total TLDs to process: {$currentProgress['total']}");
        }
        
        // Get the last processed TLD ID to continue from where we left off
        $lastProcessedId = $this->getLastProcessedTldId($logId);
        $this->logger->info("Resuming from last processed ID: {$lastProcessedId}");
        
        // Get next batch of TLDs (increased to 50 for faster processing)
        $tldsNeedingWhois = $this->getTldsNeedingWhoisData(50, $lastProcessedId);
        $this->logger->info("Retrieved batch of " . count($tldsNeedingWhois) . " TLDs for processing");
        
        if (empty($tldsNeedingWhois)) {
            $this->logger->info("No more TLDs to process - Import complete!");
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => $currentProgress['total'],
                'new_tlds' => 0,
                'updated_tlds' => $currentProgress['processed'],
                'failed_tlds' => $currentProgress['failed']
            ], 'completed', null, $currentProgress);
            
            $this->logger->endOperation("WHOIS Batch Processing", [
                'status' => 'complete',
                'total_processed' => $currentProgress['processed'],
                'total_failed' => $currentProgress['failed']
            ]);
            
            return [
                'status' => 'complete',
                'log_id' => $logId,
                'total' => $currentProgress['total'],
                'processed' => $currentProgress['processed'],
                'failed' => $currentProgress['failed'],
                'remaining' => 0,
                'message' => 'All TLDs processed successfully (ID 1 to last ID)'
            ];
        }

        $batchStats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

                $lastProcessedIdInBatch = 0;
                
                foreach ($tldsNeedingWhois as $index => $tld) {
                    $tldStartTime = microtime(true);
                    $batchStats['total_tlds']++;
                    $tldNumber = $index + 1;
                    $totalInBatch = count($tldsNeedingWhois);
                    
                    $this->logger->info("Processing TLD [{$tldNumber}/{$totalInBatch}]: {$tld['tld']} (ID: {$tld['id']})");
                    
                try {
                    $result = $this->fetchWhoisDataFromRdap($tld['tld']);
                    $fetchTime = round((microtime(true) - $tldStartTime) * 1000, 2);
                    
                    if ($result) {
                        $updateStartTime = microtime(true);
                        $this->tldModel->update($tld['id'], $result);
                        $updateTime = round((microtime(true) - $updateStartTime) * 1000, 2);
                        
                        $batchStats['updated_tlds']++;
                        $currentProgress['processed']++;
                        
                        // Log what data we found (or didn't find)
                        $foundData = [];
                        if (isset($result['whois_server'])) $foundData[] = 'WHOIS server';
                        if (isset($result['registry_url'])) $foundData[] = 'registry URL';
                        if (isset($result['registration_date'])) $foundData[] = 'registration date';
                        if (isset($result['record_last_updated'])) $foundData[] = 'last updated date';
                        
                        if (empty($foundData)) {
                            $this->logger->warning("TLD {$tld['tld']}: No data available from IANA", [
                                'fetch_time_ms' => $fetchTime,
                                'update_time_ms' => $updateTime
                            ]);
                        } else {
                            $this->logger->info("TLD {$tld['tld']}: SUCCESS - Found " . implode(', ', $foundData), [
                                'fetch_time_ms' => $fetchTime,
                                'update_time_ms' => $updateTime,
                                'data_fields' => count($foundData)
                            ]);
                        }
                    } else {
                        // Even if no data found, update the record to mark it as processed
                        $this->tldModel->update($tld['id'], ['updated_at' => date('Y-m-d H:i:s')]);
                        $batchStats['updated_tlds']++;
                        $currentProgress['processed']++;
                        $this->logger->warning("TLD {$tld['tld']}: No data returned, marked as processed", [
                            'fetch_time_ms' => $fetchTime
                        ]);
                    }
                    
                    // Track the highest ID processed in this batch
                    $lastProcessedIdInBatch = max($lastProcessedIdInBatch, $tld['id']);
                    
                } catch (\Exception $e) {
                    $batchStats['failed_tlds']++;
                    $currentProgress['failed']++;
                    $this->logger->error("TLD {$tld['tld']}: FAILED - " . $e->getMessage(), [
                        'exception_type' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    
                    // Still track the ID even if it failed
                    $lastProcessedIdInBatch = max($lastProcessedIdInBatch, $tld['id']);
                }
                
                // Add minimal delay between requests (reduced for speed)
                if ($index < count($tldsNeedingWhois) - 1) {
                    usleep(25000); // 0.025 second delay for even faster processing
                }
            }
        
        $batchTime = round(microtime(true) - $batchStartTime, 2);
        
        // Update the last processed ID in currentProgress (CRITICAL - must be saved!)
        if ($lastProcessedIdInBatch > 0) {
            $currentProgress['last_processed_id'] = $lastProcessedIdInBatch;
            
            $this->logger->info("Updated last processed ID", [
                'previous_id' => $lastProcessedId,
                'new_id' => $lastProcessedIdInBatch,
                'jump' => $lastProcessedIdInBatch - $lastProcessedId
            ]);
        }

        $remainingCount = $this->getTldsNeedingWhoisCount($logId);
        
        $this->logger->info("Batch statistics", [
            'batch_time_seconds' => $batchTime,
            'tlds_in_batch' => count($tldsNeedingWhois),
            'successful' => $batchStats['updated_tlds'],
            'failed' => $batchStats['failed_tlds'],
            'avg_time_per_tld' => count($tldsNeedingWhois) > 0 ? round($batchTime / count($tldsNeedingWhois), 2) : 0,
            'remaining' => $remainingCount
        ]);
        
        if ($remainingCount === 0) {
            $this->logger->info("Import complete - No more TLDs remaining");
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => $currentProgress['total'],
                'new_tlds' => 0,
                'updated_tlds' => $currentProgress['processed'],
                'failed_tlds' => $currentProgress['failed']
            ], 'completed', null, $currentProgress);
            $status = 'complete';
            $message = 'All TLDs processed successfully';
            
            $this->logger->endOperation("WHOIS Batch Processing", [
                'status' => 'complete',
                'total_processed' => $currentProgress['processed'],
                'total_failed' => $currentProgress['failed'],
                'total_time_seconds' => $batchTime
            ]);
        } else {
            $this->logger->info("Batch complete, more TLDs remaining", [
                'processed_in_batch' => count($tldsNeedingWhois),
                'remaining' => $remainingCount,
                'completion_percentage' => round((($currentProgress['total'] - $remainingCount) / $currentProgress['total']) * 100, 2)
            ]);
            
            $this->importLogModel->update($logId, [
                'total_tlds' => $currentProgress['total'],
                'updated_tlds' => $currentProgress['processed'],
                'failed_tlds' => $currentProgress['failed']
            ], null, null, $currentProgress);
            $status = 'in_progress';
            $message = "Processed batch of " . count($tldsNeedingWhois) . " TLDs, {$remainingCount} remaining";
        }

        return [
            'status' => $status,
            'log_id' => $logId,
            'total' => $currentProgress['total'],
            'processed' => $currentProgress['processed'],
            'failed' => $currentProgress['failed'],
            'remaining' => $remainingCount,
            'message' => $message
        ];
    }

    /**
     * Process check updates batch
     */
    private function processCheckUpdatesBatch(int $logId): array
    {
        try {
            $updateInfo = $this->checkForUpdates();
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => 0,
                'new_tlds' => 0,
                'updated_tlds' => 0,
                'failed_tlds' => 0
            ]);
            
            // Build detailed message
            $messages = [];
            
            if ($updateInfo['tld_list']['needs_update'] ?? false) {
                $current = $updateInfo['tld_list']['current_version'] ?? 'Unknown';
                $last = $updateInfo['tld_list']['last_version'] ?? 'None';
                $messages[] = "TLD List: New version available (current: $current, previous: $last)";
            }
            
            if ($updateInfo['rdap']['needs_update'] ?? false) {
                $current = $updateInfo['rdap']['current_publication'] ?? 'Unknown';
                $last = $updateInfo['rdap']['last_publication'] ?? 'None';
                $messages[] = "RDAP Data: New publication available (current: $current, previous: $last)";
            }
            
            if ($updateInfo['overall_needs_update']) {
                $message = "🔔 Updates Available! " . implode(' • ', $messages) . " Click 'Import TLDs' to update your database.";
            } else {
                $tldVersion = $updateInfo['tld_list']['current_version'] ?? 'N/A';
                $rdapDate = $updateInfo['rdap']['current_publication'] ?? 'N/A';
                $message = "✅ TLD Registry is Up to Date! (TLD List version: $tldVersion, RDAP publication: $rdapDate)";
            }
            
            return [
                'status' => 'complete',
                'log_id' => $logId,
                'total' => 2,
                'processed' => 2,
                'failed' => 0,
                'remaining' => 0,
                'message' => $message,
                'update_info' => $updateInfo
            ];
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => 0,
                'new_tlds' => 0,
                'updated_tlds' => 0,
                'failed_tlds' => 1
            ], 'failed', $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'Update check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process complete workflow batch (TLD list → RDAP → WHOIS → Registry URLs)
     */
    private function processCompleteWorkflowBatch(int $logId): array
    {
        $workflowStartTime = microtime(true);
        $this->logger->startOperation("Complete Workflow Batch", ['log_id' => $logId]);
        
        // Get current progress from log
        $log = $this->importLogModel->find($logId);
        $currentProgress = $log['details'] ? json_decode($log['details'], true) : [
            'current_step' => 0,
            'total_steps' => 3,
            'step_names' => ['Import TLD List', 'Import RDAP Servers', 'Import WHOIS & Registry Data'],
            'step_progress' => [0, 0, 0],
            'overall_processed' => 0,
            'overall_failed' => 0
        ];

        $this->logger->info("Workflow progress", [
            'current_step' => $currentProgress['current_step'],
            'total_steps' => $currentProgress['total_steps'],
            'step_name' => $currentProgress['step_names'][$currentProgress['current_step']] ?? 'Unknown'
        ]);

        try {
            // Step 1: Import TLD List
            if ($currentProgress['current_step'] == 0) {
                $stats = $this->importTldList();
                $currentProgress['step_progress'][0] = $stats['new_tlds'] + $stats['updated_tlds'];
                $currentProgress['overall_processed'] += $currentProgress['step_progress'][0];
                $currentProgress['overall_failed'] += $stats['failed_tlds'];
                $currentProgress['current_step'] = 1;
                
                $this->importLogModel->update($logId, [], 'running', null, $currentProgress);
                
                return [
                    'status' => 'in_progress',
                    'log_id' => $logId,
                    'total' => $currentProgress['total_steps'],
                    'processed' => $currentProgress['current_step'],
                    'failed' => $currentProgress['overall_failed'],
                    'remaining' => $currentProgress['total_steps'] - $currentProgress['current_step'],
                    'message' => "Step 1/3: {$currentProgress['step_names'][0]} completed. {$currentProgress['step_progress'][0]} TLDs processed."
                ];
            }

            // Step 2: Import RDAP Servers
            if ($currentProgress['current_step'] == 1) {
                $stats = $this->importRdapData();
                $currentProgress['step_progress'][1] = $stats['updated_tlds'];
                $currentProgress['overall_processed'] += $currentProgress['step_progress'][1];
                $currentProgress['overall_failed'] += $stats['failed_tlds'];
                $currentProgress['current_step'] = 2;
                
                $this->importLogModel->update($logId, [], 'running', null, $currentProgress);
                
                return [
                    'status' => 'in_progress',
                    'log_id' => $logId,
                    'total' => $currentProgress['total_steps'],
                    'processed' => $currentProgress['current_step'],
                    'failed' => $currentProgress['overall_failed'],
                    'remaining' => $currentProgress['total_steps'] - $currentProgress['current_step'],
                    'message' => "Step 2/3: {$currentProgress['step_names'][1]} completed. {$currentProgress['step_progress'][1]} TLDs updated."
                ];
            }

            // Step 3: Import WHOIS Data (in batches)
            if ($currentProgress['current_step'] == 2) {
                $this->logger->info("Step 3: Starting WHOIS batch processing");
                
                // Get the last processed TLD ID to continue from where we left off
                $lastProcessedId = $this->getLastProcessedTldId($logId);
                $this->logger->info("Resuming from last processed ID: {$lastProcessedId}");
                
                // Get next batch of TLDs needing WHOIS data (increased batch size)
                $tldsNeedingWhois = $this->getTldsNeedingWhoisData(50, $lastProcessedId);
                $this->logger->info("Retrieved " . count($tldsNeedingWhois) . " TLDs for WHOIS processing");
                
                if (empty($tldsNeedingWhois)) {
                    // No more TLDs to process, complete the workflow
                    $this->logger->info("Workflow complete - No more TLDs to process");
                    $this->importLogModel->completeImport($logId, [
                        'total_tlds' => $currentProgress['overall_processed'],
                        'new_tlds' => $currentProgress['step_progress'][0],
                        'updated_tlds' => $currentProgress['step_progress'][1] + $currentProgress['step_progress'][2],
                        'failed_tlds' => $currentProgress['overall_failed']
                    ], 'completed', null, $currentProgress);
                    
                    $this->logger->endOperation("Complete Workflow Batch", [
                        'status' => 'complete',
                        'total_processed' => $currentProgress['overall_processed'],
                        'total_failed' => $currentProgress['overall_failed']
                    ]);
                    
                    return [
                        'status' => 'complete',
                        'log_id' => $logId,
                        'total' => $currentProgress['total_steps'],
                        'processed' => $currentProgress['total_steps'],
                        'failed' => $currentProgress['overall_failed'],
                        'remaining' => 0,
                        'message' => "Complete workflow finished! All TLDs processed (ID 1 to last ID)."
                    ];
                }

                // Process WHOIS batch
                $batchProcessed = 0;
                $batchFailed = 0;
                $stepStartTime = microtime(true);
                
                $lastProcessedIdInBatch = 0;
                
                $this->logger->info("Starting to process batch of " . count($tldsNeedingWhois) . " TLDs");
                
                foreach ($tldsNeedingWhois as $index => $tld) {
                    $tldStartTime = microtime(true);
                    $tldNumber = $index + 1;
                    $totalInBatch = count($tldsNeedingWhois);
                    
                    $this->logger->info("Processing TLD [{$tldNumber}/{$totalInBatch}]: {$tld['tld']} (ID: {$tld['id']})");
                    
                    try {
                        $result = $this->fetchWhoisDataFromRdap($tld['tld']);
                        $fetchTime = round((microtime(true) - $tldStartTime) * 1000, 2);
                        
                        if ($result) {
                            $updateStartTime = microtime(true);
                            $this->tldModel->update($tld['id'], $result);
                            $updateTime = round((microtime(true) - $updateStartTime) * 1000, 2);
                            $batchProcessed++;
                            
                            // Log what data we found (or didn't find)
                            $foundData = [];
                            if (isset($result['whois_server'])) $foundData[] = 'WHOIS server';
                            if (isset($result['registry_url'])) $foundData[] = 'registry URL';
                            if (isset($result['registration_date'])) $foundData[] = 'registration date';
                            if (isset($result['record_last_updated'])) $foundData[] = 'last updated date';
                            
                            if (empty($foundData)) {
                                $this->logger->warning("TLD {$tld['tld']}: No data available", [
                                    'fetch_time_ms' => $fetchTime,
                                    'update_time_ms' => $updateTime
                                ]);
                            } else {
                                $this->logger->info("TLD {$tld['tld']}: SUCCESS - " . implode(', ', $foundData), [
                                    'fetch_time_ms' => $fetchTime,
                                    'update_time_ms' => $updateTime
                                ]);
                            }
                        } else {
                            // Even if no data found, update the record to mark it as processed
                            $this->tldModel->update($tld['id'], ['updated_at' => date('Y-m-d H:i:s')]);
                            $batchProcessed++;
                            $this->logger->warning("TLD {$tld['tld']}: No data returned, marked as processed", [
                                'fetch_time_ms' => $fetchTime
                            ]);
                        }
                        
                        // Track the highest ID processed in this batch
                        $lastProcessedIdInBatch = max($lastProcessedIdInBatch, $tld['id']);
                        
                    } catch (\Exception $e) {
                        $batchFailed++;
                        $this->logger->error("TLD {$tld['tld']}: FAILED - " . $e->getMessage(), [
                            'exception_type' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]);
                        
                        // Still track the ID even if it failed
                        $lastProcessedIdInBatch = max($lastProcessedIdInBatch, $tld['id']);
                    }
                    
                    // Add minimal delay between requests (reduced for speed)
                    if ($index < count($tldsNeedingWhois) - 1) {
                        usleep(25000); // 0.025 second delay for even faster processing
                    }
                }
                
                $stepTime = round(microtime(true) - $stepStartTime, 2);
                
                // Update progress counters
                $currentProgress['step_progress'][2] += $batchProcessed;
                $currentProgress['overall_processed'] += $batchProcessed;
                $currentProgress['overall_failed'] += $batchFailed;
                
                // Update the last processed ID in currentProgress (CRITICAL - must be saved!)
                if ($lastProcessedIdInBatch > 0) {
                    $currentProgress['last_processed_id'] = $lastProcessedIdInBatch;
                    
                    $this->logger->info("Updated last processed ID", [
                        'previous_id' => $lastProcessedId,
                        'new_id' => $lastProcessedIdInBatch,
                        'jump' => $lastProcessedIdInBatch - $lastProcessedId
                    ]);
                }
                
                $this->logger->info("Step 3 batch statistics", [
                    'batch_time_seconds' => $stepTime,
                    'processed' => $batchProcessed,
                    'failed' => $batchFailed,
                    'avg_time_per_tld' => $batchProcessed > 0 ? round($stepTime / $batchProcessed, 2) : 0
                ]);
                
                // Update the import log with all progress including last_processed_id
                $this->importLogModel->update($logId, [], 'running', null, $currentProgress);
                
                $remainingWhois = $this->getTldsNeedingWhoisCount($logId);
                $this->logger->info("Remaining TLDs to process: {$remainingWhois}");
                
                if ($remainingWhois > 0) {
                    // Still TLDs to process - return in_progress status
                    $completionPercent = round((($currentProgress['step_progress'][2]) / ($currentProgress['step_progress'][2] + $remainingWhois)) * 100, 2);
                    $totalTldsInStep3 = $currentProgress['step_progress'][2] + $remainingWhois;
                    
                    $this->logger->info("Step 3 in progress", [
                        'completion_percentage' => $completionPercent,
                        'processed_so_far' => $currentProgress['step_progress'][2],
                        'remaining' => $remainingWhois,
                        'batch_processed' => $batchProcessed
                    ]);
                    
                    return [
                        'status' => 'in_progress',
                        'log_id' => $logId,
                        'total' => $totalTldsInStep3, // Total TLDs to process in step 3
                        'processed' => $currentProgress['step_progress'][2], // TLDs processed so far in step 3
                        'failed' => $currentProgress['overall_failed'],
                        'remaining' => $remainingWhois, // Fixed: Use actual remaining TLDs, not remaining steps
                        'message' => "Step 3/3: {$currentProgress['step_names'][2]} - Processed {$currentProgress['step_progress'][2]} of {$totalTldsInStep3} TLDs ({$completionPercent}% complete, {$remainingWhois} remaining)"
                    ];
                } else {
                    // No more TLDs - complete the workflow!
                    $this->logger->info("Step 3 complete - All TLDs processed!");
                    
                    $this->importLogModel->completeImport($logId, [
                        'total_tlds' => $currentProgress['overall_processed'],
                        'new_tlds' => $currentProgress['step_progress'][0],
                        'updated_tlds' => $currentProgress['step_progress'][1] + $currentProgress['step_progress'][2],
                        'failed_tlds' => $currentProgress['overall_failed']
                    ], 'completed', null, $currentProgress);
                    
                    $this->logger->endOperation("Complete Workflow Batch", [
                        'status' => 'complete',
                        'total_processed' => $currentProgress['overall_processed'],
                        'total_failed' => $currentProgress['overall_failed']
                    ]);
                    
                    return [
                        'status' => 'complete',
                        'log_id' => $logId,
                        'total' => $currentProgress['step_progress'][2],
                        'processed' => $currentProgress['step_progress'][2],
                        'failed' => $currentProgress['overall_failed'],
                        'remaining' => 0,
                        'message' => "Complete workflow finished! All {$currentProgress['step_progress'][2]} TLDs processed successfully."
                    ];
                }
            }

        } catch (\Exception $e) {
            $this->logger->critical("Complete workflow failed", [
                'error' => $e->getMessage(),
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->importLogModel->completeImport($logId, [
                'total_tlds' => 0,
                'new_tlds' => 0,
                'updated_tlds' => 0,
                'failed_tlds' => 1
            ], 'failed', $e->getMessage());
            
            $this->logger->endOperation("Complete Workflow Batch", [
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'error',
                'message' => 'Complete workflow failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Import registry URLs for TLDs missing them
     */
    private function importRegistryUrls(): array
    {
        $stats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

        // Get TLDs missing registry URLs
        $sql = "SELECT * FROM tld_registry 
                WHERE is_active = 1 
                AND (registry_url IS NULL OR registry_url = '')
                LIMIT 50";
        
        $tlds = $this->tldModel->query($sql);
        
        foreach ($tlds as $tld) {
            $stats['total_tlds']++;
            
            try {
                // Try to fetch registry URL from RDAP API
                $registryUrl = $this->fetchRegistryUrlFromRdap($tld['tld']);
                
                if ($registryUrl) {
                    $this->tldModel->update($tld['id'], [
                        'registry_url' => $registryUrl,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $stats['updated_tlds']++;
                } else {
                    $stats['failed_tlds']++;
                }
            } catch (\Exception $e) {
                $stats['failed_tlds']++;
                $this->logger->error("Failed to fetch registry URL for TLD {$tld['tld']}: " . $e->getMessage());
            }
            
            // Add small delay
            usleep(100000); // 0.1 second delay
        }

        return $stats;
    }

    /**
     * Process a single TLD entry from the TLD list
     */
    private function processTldEntry(string $tld): array
    {
        // Ensure TLD starts with dot
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        $data = [
            'tld' => $tld,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Check if TLD already exists
        $existing = $this->tldModel->getByTld($tld);
        $isNew = !$existing;

        if ($existing) {
            // Update existing record (just update the timestamp)
            $this->tldModel->update($existing['id'], [
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            // Create new record
            $this->tldModel->create($data);
        }

        return ['is_new' => $isNew];
    }

    /**
     * Process RDAP data for a single TLD
     */
    private function processTldRdapData(string $tld, array $rdapServers, ?string $publicationDate): array
    {
        // Ensure TLD starts with dot
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        $data = [
            'tld' => $tld,
            'rdap_servers' => json_encode($rdapServers),
            'iana_publication_date' => $publicationDate,
            'iana_last_updated' => date('Y-m-d H:i:s'),
            'is_active' => 1
        ];

        // Check if TLD already exists
        $existing = $this->tldModel->getByTld($tld);
        $isNew = !$existing;

        if ($existing) {
            // Update existing record
            $this->tldModel->update($existing['id'], $data);
        } else {
            // Create new record
            $this->tldModel->create($data);
        }

        return ['is_new' => $isNew];
    }

    /**
     * Fetch WHOIS and registry data using hybrid approach: RDAP API first, HTML fallback
     */
    private function fetchWhoisDataFromRdap(string $tld): ?array
    {
        $tldForUrl = ltrim($tld, '.');
        $rdapUrl = self::IANA_RDAP_DOMAIN_URL . $tldForUrl;

        try {
            // Step 1: Try RDAP API first (fast, structured data)
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get($rdapUrl);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->error("Failed to fetch RDAP data for TLD $tld: HTTP " . $response->getStatusCode());
                return null;
            }
            
            $responseBody = $response->getBody()->getContents();
            
            // Check if response is HTML instead of JSON (common when servers are down)
            if (strpos($responseBody, '<html') !== false || strpos($responseBody, '<!DOCTYPE') !== false) {
                $this->logger->warning("Received HTML instead of JSON for TLD $tld - server may be down");
                return null;
            }
            
            $data = json_decode($responseBody, true);
            
            if (!$data) {
                $this->logger->error("Invalid JSON response for TLD $tld: " . substr($responseBody, 0, 200));
                return null;
            }

            $result = [
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Extract WHOIS server from RDAP data (port43 field)
            if (isset($data['port43']) && !empty($data['port43'])) {
                $result['whois_server'] = $data['port43'];
            }

            // Extract registry URL from links array
            if (isset($data['links']) && is_array($data['links'])) {
                foreach ($data['links'] as $link) {
                    if (isset($link['rel']) && $link['rel'] === 'related' && 
                        isset($link['title']) && $link['title'] === 'Registration URL' &&
                        isset($link['href'])) {
                        $result['registry_url'] = $link['href'];
                        break;
                    }
                }
            }

            // Extract dates from events array
            if (isset($data['events']) && is_array($data['events'])) {
                foreach ($data['events'] as $event) {
                    if (isset($event['eventAction']) && isset($event['eventDate'])) {
                        switch ($event['eventAction']) {
                            case 'registration':
                                $result['registration_date'] = $this->normalizeDate($event['eventDate']);
                                break;
                            case 'last changed':
                                $result['record_last_updated'] = $this->normalizeDate($event['eventDate']);
                                break;
                        }
                    }
                }
            }

            // Step 2: If WHOIS server is missing, try HTML fallback
            if (!isset($result['whois_server']) || empty($result['whois_server'])) {
                $htmlData = $this->fetchWhoisDataFromHtml($tld);
                if ($htmlData) {
                    // Merge HTML data, prioritizing RDAP data but filling gaps with HTML data
                    if (isset($htmlData['whois_server']) && !empty($htmlData['whois_server'])) {
                        $result['whois_server'] = $htmlData['whois_server'];
                    }
                    if (!isset($result['registry_url']) && isset($htmlData['registry_url'])) {
                        $result['registry_url'] = $htmlData['registry_url'];
                    }
                    if (!isset($result['registration_date']) && isset($htmlData['registration_date'])) {
                        $result['registration_date'] = $htmlData['registration_date'];
                    }
                    if (!isset($result['record_last_updated']) && isset($htmlData['record_last_updated'])) {
                        $result['record_last_updated'] = $htmlData['record_last_updated'];
                    }
                }
            }

            // Always return the result, even if some fields are missing
            // This ensures we update the TLD record with whatever data we found
            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch RDAP data for TLD $tld: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fallback: Fetch WHOIS data from IANA HTML page
     */
    private function fetchWhoisDataFromHtml(string $tld): ?array
    {
        $tldForUrl = ltrim($tld, '.');
        $url = self::IANA_TLD_BASE_URL . $tldForUrl . '.html';

        try {
            $htmlClient = $this->getHtmlClient();
            $response = $htmlClient->get($url);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->error("HTML fetch failed for TLD $tld: HTTP " . $response->getStatusCode());
                return null;
            }
            
            $html = $response->getBody()->getContents();
            
            if (empty($html) || strlen($html) < 100) {
                $this->logger->warning("HTML content too short for TLD $tld: " . strlen($html) . " bytes");
                return null;
            }

            $result = [];

            // Parse HTML to extract WHOIS server and other data
            $whoisServer = $this->extractWhoisServer($html);
            $lastUpdated = $this->extractLastUpdated($html);
            $registryUrl = $this->extractRegistryUrl($html);
            $registrationDate = $this->extractRegistrationDate($html);

            if ($whoisServer) {
                $result['whois_server'] = $whoisServer;
            }
            if ($lastUpdated) {
                $result['record_last_updated'] = $this->normalizeDate($lastUpdated);
            }
            if ($registryUrl) {
                $result['registry_url'] = $registryUrl;
            }
            if ($registrationDate) {
                $result['registration_date'] = $this->normalizeDate($registrationDate);
            }

            return !empty($result) ? $result : null;

        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch HTML data for TLD $tld: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract WHOIS server from IANA HTML
     */
    private function extractWhoisServer(string $html): ?string
    {
        // Look for WHOIS Server pattern with HTML tags
        if (preg_match('/<b>WHOIS Server:<\/b>\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        
        // Fallback: Look for WHOIS Server pattern without HTML tags
        if (preg_match('/WHOIS Server:\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * Extract last updated date from IANA HTML
     */
    private function extractLastUpdated(string $html): ?string
    {
        // Look for "Record last updated" pattern
        if (preg_match('/Record last updated\s+(\d{4}-\d{2}-\d{2})/i', $html, $matches)) {
            return $matches[1] . ' 00:00:00';
        }
        return null;
    }

    /**
     * Extract registry URL from IANA HTML
     */
    private function extractRegistryUrl(string $html): ?string
    {
        // Look for "URL for registration services" pattern with <a> tag
        if (preg_match('/<b>URL for registration services:<\/b>\s*<a[^>]*href="([^"]+)"[^>]*>/i', $html, $matches)) {
            return trim($matches[1]);
        }
        
        // Look for "URL for registration services" pattern with HTML tags
        if (preg_match('/<b>URL for registration services:<\/b>\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        
        // Fallback: Look for "URL for registration services" pattern without HTML tags
        if (preg_match('/URL for registration services:\s*([^\s<]+)/i', $html, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * Extract registration date from IANA HTML
     */
    private function extractRegistrationDate(string $html): ?string
    {
        // Look for "Registration date" pattern
        if (preg_match('/Registration date\s+(\d{4}-\d{2}-\d{2})/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Check for IANA updates in both TLD list and RDAP data
     */
    public function checkForUpdates(): array
    {
        $updates = [
            'tld_list' => ['needs_update' => false, 'current_version' => null, 'last_version' => null],
            'rdap' => ['needs_update' => false, 'current_publication' => null, 'last_publication' => null],
            'overall_needs_update' => false,
            'errors' => []
        ];

        try {
            // Check TLD list for updates
            $tldListUpdate = $this->checkTldListUpdates();
            $updates['tld_list'] = $tldListUpdate;
            
            // Check RDAP data for updates
            $rdapUpdate = $this->checkRdapUpdates();
            $updates['rdap'] = $rdapUpdate;
            
            // Determine if any updates are needed
            $updates['overall_needs_update'] = $tldListUpdate['needs_update'] || $rdapUpdate['needs_update'];
            
        } catch (\Exception $e) {
            $updates['errors'][] = $e->getMessage();
        }

        return $updates;
    }

    /**
     * Check for TLD list updates
     */
    private function checkTldListUpdates(): array
    {
        try {
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get(self::IANA_TLD_LIST_URL);
            
            if ($response->getStatusCode() !== 200) {
                return [
                    'needs_update' => false,
                    'current_version' => null,
                    'last_version' => null,
                    'error' => 'Failed to fetch TLD list: HTTP ' . $response->getStatusCode()
                ];
            }
            
            $content = $response->getBody()->getContents();
            $lines = explode("\n", $content);
            
            $currentVersion = null;
            $currentLastUpdated = null;
            
            // Extract version and timestamp from header
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '# Version') === 0) {
                    if (preg_match('/# Version (\d+), Last Updated (.+)/', $line, $matches)) {
                        $currentVersion = $matches[1];
                        $currentLastUpdatedRaw = $matches[2];
                        
                        // Convert the timestamp to a proper format
                        try {
                            $currentLastUpdated = date('Y-m-d H:i:s', strtotime($currentLastUpdatedRaw));
                            if ($currentLastUpdated === '1970-01-01 00:00:00') {
                                // If strtotime fails, keep the raw value
                                $currentLastUpdated = $currentLastUpdatedRaw;
                            }
                        } catch (\Exception $e) {
                            $currentLastUpdated = $currentLastUpdatedRaw;
                        }
                    }
                    break;
                }
            }
            
            // Get last TLD list import
            $lastTldImport = $this->importLogModel->query(
                "SELECT version, iana_publication_date FROM tld_import_logs 
                 WHERE import_type = 'tld_list' AND status = 'completed' 
                 ORDER BY started_at DESC LIMIT 1"
            );
            
            $lastVersion = $lastTldImport[0]['version'] ?? null;
            $lastPublication = $lastTldImport[0]['iana_publication_date'] ?? null;
            
            $needsUpdate = ($currentVersion !== $lastVersion) || ($currentLastUpdated !== $lastPublication);
            
            return [
                'needs_update' => $needsUpdate,
                'current_version' => $currentVersion,
                'current_last_updated' => $currentLastUpdated,
                'last_version' => $lastVersion,
                'last_publication' => $lastPublication
            ];
            
        } catch (\Exception $e) {
            return [
                'needs_update' => false,
                'current_version' => null,
                'last_version' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check for RDAP data updates
     */
    private function checkRdapUpdates(): array
    {
        try {
            $jsonClient = $this->getJsonClient();
            $response = $jsonClient->get(self::IANA_RDAP_URL);
            
            if ($response->getStatusCode() !== 200) {
                return [
                    'needs_update' => false,
                    'current_publication' => null,
                    'last_publication' => null,
                    'error' => 'Failed to fetch RDAP data: HTTP ' . $response->getStatusCode()
                ];
            }
            
            $data = json_decode($response->getBody()->getContents(), true);
            $currentPublication = $data['publication'] ?? null;
            
            // Get last RDAP import using database directly
            $db = \Core\Database::getConnection();
            $stmt = $db->prepare(
                "SELECT iana_publication_date FROM tld_import_logs 
                 WHERE import_type = 'rdap' AND status = 'completed' 
                 ORDER BY started_at DESC LIMIT 1"
            );
            $stmt->execute();
            $lastRdapImport = $stmt->fetch();
            
            $lastPublication = $lastRdapImport['iana_publication_date'] ?? null;
            
            // Normalize date formats for comparison (ISO 8601 vs MySQL datetime)
            $currentNormalized = $this->normalizeDate($currentPublication);
            $lastNormalized = $this->normalizeDate($lastPublication);
            
            $needsUpdate = ($currentNormalized !== $lastNormalized) && ($currentNormalized !== null);
            
            return [
                'needs_update' => $needsUpdate,
                'current_publication' => $currentNormalized ?: $currentPublication,
                'last_publication' => $lastNormalized ?: $lastPublication
            ];
            
        } catch (\Exception $e) {
            return [
                'needs_update' => false,
                'current_publication' => null,
                'last_publication' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get TLD registry information for a domain
     */
    public function getTldInfo(string $domain): ?array
    {
        // Extract TLD from domain
        $tld = $this->extractTldFromDomain($domain);
        if (!$tld) {
            return null;
        }

        return $this->tldModel->getByTld($tld);
    }

    /**
     * Extract TLD from domain name
     */
    private function extractTldFromDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        
        // Remove protocol if present
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        
        // Remove www if present
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Remove path if present
        $domain = explode('/', $domain)[0];
        
        // Split by dots and get the last part (TLD)
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return null;
        }
        
        // For domains like example.co.uk, we want .co.uk
        if (count($parts) > 2) {
            // Check if it's a known multi-part TLD
            $lastTwo = '.' . $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            $lastOne = '.' . $parts[count($parts) - 1];
            
            // Try to find the TLD in our registry
            $tldInfo = $this->tldModel->getByTld($lastTwo);
            if ($tldInfo) {
                return $lastTwo;
            }
            
            return $lastOne;
        }
        
        return '.' . $parts[count($parts) - 1];
    }

    /**
     * Get RDAP servers for a TLD
     */
    public function getRdapServers(string $tld): array
    {
        $tldInfo = $this->tldModel->getByTld($tld);
        if (!$tldInfo || empty($tldInfo['rdap_servers'])) {
            return [];
        }

        $servers = json_decode($tldInfo['rdap_servers'], true);
        return is_array($servers) ? $servers : [];
    }

    /**
     * Get WHOIS server for a TLD
     */
    public function getWhoisServer(string $tld): ?string
    {
        $tldInfo = $this->tldModel->getByTld($tld);
        return $tldInfo['whois_server'] ?? null;
    }

    /**
     * Import WHOIS data for specific TLDs that are known to be missing from RDAP
     */
    public function importWhoisForSpecificTlds(array $tlds): array
    {
        $logId = $this->importLogModel->startImport('whois');
        $stats = [
            'total_tlds' => 0,
            'new_tlds' => 0,
            'updated_tlds' => 0,
            'failed_tlds' => 0
        ];

        try {
            foreach ($tlds as $index => $tld) {
                $stats['total_tlds']++;
                
                try {
                    // Ensure TLD starts with dot
                    if (!str_starts_with($tld, '.')) {
                        $tld = '.' . $tld;
                    }

                    // Check if TLD exists in our registry
                    $existing = $this->tldModel->getByTld($tld);
                    
                    if (!$existing) {
                        // Create new TLD entry first
                        $this->tldModel->create([
                            'tld' => $tld,
                            'is_active' => 1,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        $existing = $this->tldModel->getByTld($tld);
                        $stats['new_tlds']++;
                    }

                    // Fetch WHOIS data
                    $result = $this->fetchWhoisDataFromRdap($tld);
                    
                    if ($result && $existing) {
                        $this->tldModel->update($existing['id'], $result);
                        $stats['updated_tlds']++;
                    } else {
                        $stats['failed_tlds']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed_tlds']++;
                    $this->logger->error("Failed to fetch WHOIS data for TLD $tld: " . $e->getMessage());
                }
                
                // Add delay between requests to be respectful to IANA servers
                if ($index < count($tlds) - 1) {
                    usleep(500000); // 0.5 second delay
                }
            }

            $this->importLogModel->completeImport($logId, $stats);
            
        } catch (\Exception $e) {
            $this->importLogModel->completeImport($logId, $stats, 'failed', $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Normalize date string for comparison
     * Converts both ISO 8601 and MySQL datetime to same format (UTC)
     *
     * @param string|null $date Date string to normalize
     * @return string|null Normalized date in UTC (Y-m-d H:i:s) or null
     */
    private function normalizeDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            // If date has timezone info (ISO 8601 with Z or +00:00), parse it correctly
            // Otherwise assume it's UTC (for dates from database)
            if (strpos($date, 'T') !== false || strpos($date, 'Z') !== false || strpos($date, '+') !== false) {
                // ISO 8601 format with timezone - parse as-is
                $dateTime = new \DateTime($date);
            } else {
                // Plain datetime (from database) - explicitly parse as UTC
                $dateTime = new \DateTime($date, new \DateTimeZone('UTC'));
            }
            
            // Convert to UTC to ensure consistent comparison
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
            
            // Return in MySQL datetime format (Y-m-d H:i:s) in UTC
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // Fallback to null if date parsing fails
            return null;
        }
    }
}
