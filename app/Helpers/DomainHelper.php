<?php

namespace App\Helpers;

class DomainHelper
{
    /**
     * Format domain data for display
     * Adds computed fields: daysLeft, expiryClass, displayStatus, statusClass, statusIcon
     */
    public static function formatForDisplay(array $domain): array
    {
        // Calculate days until expiry
        $domain['daysLeft'] = !empty($domain['expiration_date']) 
            ? floor((strtotime($domain['expiration_date']) - time()) / 86400) 
            : null;
        
        // Determine expiry class for styling
        $domain['expiryClass'] = self::getExpiryClass($domain['daysLeft']);
        
        // Recalculate domain status if needed (backward compatibility)
        $domain['displayStatus'] = self::determineStatus($domain);
        
        // Get status badge styling
        $statusBadge = self::getStatusBadge($domain['displayStatus'], $domain['daysLeft']);
        $domain['statusClass'] = $statusBadge['class'];
        $domain['statusText'] = $statusBadge['text'];
        $domain['statusIcon'] = $statusBadge['icon'];
        
        // Determine expiry color for labels
        $domain['expiryColor'] = self::getExpiryColor($domain['daysLeft']);
        
        // Check if expiration date is manual (not from WHOIS/RDAP)
        $domain['isManualExpiration'] = self::isManualExpiration($domain);
        
        return $domain;
    }
    
    /**
     * Check if expiration date is manually set (not from WHOIS/RDAP)
     */
    private static function isManualExpiration(array $domain): bool
    {
        // If no expiration date, it's not manual
        if (empty($domain['expiration_date'])) {
            return false;
        }
        
        // Parse WHOIS data to check if it contains expiration date
        $whoisData = json_decode($domain['whois_data'] ?? '{}', true);
        
        // If WHOIS data doesn't have expiration date, it's likely manual
        if (empty($whoisData['expiration_date'])) {
            return true;
        }
        
        // If WHOIS expiration date is different from stored expiration date, it's manual
        if ($whoisData['expiration_date'] !== $domain['expiration_date']) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Determine domain status from WHOIS data
     * Uses WhoisService for consistent status detection across the application
     */
    private static function determineStatus(array $domain): string
    {
        $status = $domain['status'] ?? '';
        
        // If status is already set and valid, use it
        if (!empty($status) && $status !== 'error') {
            return $status;
        }
        
        // Parse WHOIS data
        $whoisData = json_decode($domain['whois_data'] ?? '{}', true);
        
        // Use WhoisService for consistent status detection
        // This ensures .eu/.nl domains and others are handled correctly
        $whoisService = new \App\Services\WhoisService();
        $expirationDate = $domain['expiration_date'] ?? null;
        $statusArray = $whoisData['status'] ?? [];
        
        return $whoisService->getDomainStatus($expirationDate, $statusArray, $whoisData);
    }
    
    /**
     * Get CSS class for expiry date styling
     */
    private static function getExpiryClass(?int $daysLeft): string
    {
        if ($daysLeft === null) return '';
        
        if ($daysLeft < 0) return 'text-red-600 font-semibold';
        if ($daysLeft <= 30) return 'text-orange-600 font-semibold';
        if ($daysLeft <= 90) return 'text-yellow-600';
        
        return '';
    }
    
    /**
     * Get color name for expiry
     */
    private static function getExpiryColor(?int $daysLeft): string
    {
        if ($daysLeft === null) return 'gray';
        
        if ($daysLeft < 0) return 'red';
        if ($daysLeft <= 30) return 'orange';
        if ($daysLeft <= 90) return 'yellow';
        
        return 'green';
    }
    
    /**
     * Get status badge properties (class, text, icon)
     */
    private static function getStatusBadge(string $status, ?int $daysLeft): array
    {
        // Check for expiring soon override
        if ($daysLeft !== null && $daysLeft <= 30 && $daysLeft >= 0) {
            return [
                'class' => 'bg-orange-100 text-orange-700 border-orange-200',
                'text' => 'Expiring Soon',
                'icon' => 'fa-exclamation-triangle'
            ];
        }
        
        return match($status) {
            'available' => [
                'class' => 'bg-blue-100 text-blue-700 border-blue-200',
                'text' => 'Available',
                'icon' => 'fa-info-circle'
            ],
            'active' => [
                'class' => 'bg-green-100 text-green-700 border-green-200',
                'text' => 'Active',
                'icon' => 'fa-check-circle'
            ],
            'expired' => [
                'class' => 'bg-red-100 text-red-700 border-red-200',
                'text' => 'Expired',
                'icon' => 'fa-times-circle'
            ],
            'error' => [
                'class' => 'bg-gray-100 text-gray-700 border-gray-200',
                'text' => 'Error',
                'icon' => 'fa-exclamation-circle'
            ],
            default => [
                'class' => 'bg-gray-100 text-gray-700 border-gray-200',
                'text' => ucfirst($status),
                'icon' => 'fa-question-circle'
            ]
        };
    }
    
    /**
     * Format multiple domains for display
     */
    public static function formatMultiple(array $domains): array
    {
        return array_map([self::class, 'formatForDisplay'], $domains);
    }
    
    /**
     * Parse and clean WHOIS status array
     */
    public static function parseWhoisStatuses(array $statusArray): array
    {
        $validStatuses = [];
        
        foreach ($statusArray as $status) {
            $cleanStatus = trim($status);
            
            // Skip if it's just a URL or starts with http/https or //
            if (empty($cleanStatus) || 
                strpos($cleanStatus, 'http') === 0 || 
                strpos($cleanStatus, '//') === 0 ||
                strpos($cleanStatus, 'www.') === 0) {
                continue;
            }
            
            $validStatuses[] = $cleanStatus;
        }
        
        return $validStatuses;
    }
    
    /**
     * Convert status to readable format
     * Handles camelCase, underscores, etc.
     */
    public static function formatStatusText(string $status): string
    {
        // Convert camelCase to readable format (e.g., "clientTransferProhibited" -> "client Transfer Prohibited")
        $readable = preg_replace('/([a-z])([A-Z])/', '$1 $2', $status);
        
        // Convert underscores to spaces and capitalize words
        $readable = str_replace('_', ' ', $readable);
        $readable = ucwords(strtolower($readable));
        
        return $readable;
    }
    
    /**
     * Get active channel count from domain channels
     */
    public static function getActiveChannelCount(array $channels): int
    {
        return count(array_filter($channels, fn($ch) => $ch['is_active']));
    }
}

