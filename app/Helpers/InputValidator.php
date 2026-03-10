<?php

namespace App\Helpers;

/**
 * Input Validator
 * 
 * Centralized input validation helper for consistent validation across the application
 */
class InputValidator
{
    /**
     * Validate domain name format
     *
     * @param string $domain Domain name to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateDomain(string $domain): bool
    {
        if (strlen($domain) > 253 || strlen($domain) < 3) {
            return false;
        }

        return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain);
    }

    /**
     * Sanitize raw domain input — strips protocol, www prefix, trailing dots/slashes, paths.
     *
     * @return string Cleaned, lowercased domain (may still be invalid)
     */
    public static function sanitizeDomainInput(string $input): string
    {
        $input = strtolower(trim($input));

        $input = preg_replace('#^https?://#', '', $input);
        $input = preg_replace('#/.*$#', '', $input);
        $input = rtrim($input, '.');
        $input = trim($input);

        if (str_starts_with($input, 'www.')) {
            $stripped = substr($input, 4);
            if (substr_count($stripped, '.') >= 1) {
                $input = $stripped;
            }
        }

        return $input;
    }

    /**
     * Validate that a domain is a registrable root domain (not a subdomain).
     * Uses the tld_registry table to identify multi-level TLDs like .co.uk.
     *
     * @return array{valid: bool, domain: string, error: string|null}
     */
    public static function validateRootDomain(string $domain): array
    {
        $domain = self::sanitizeDomainInput($domain);

        if (empty($domain)) {
            return ['valid' => false, 'domain' => '', 'error' => 'Domain name is required'];
        }

        if (!self::validateDomain($domain)) {
            return ['valid' => false, 'domain' => $domain, 'error' => "Invalid domain format: $domain"];
        }

        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return ['valid' => false, 'domain' => $domain, 'error' => "Invalid domain: $domain"];
        }

        $tldModel = new \App\Models\TldRegistry();

        $matchedTld = null;
        for ($i = 1; $i < count($parts); $i++) {
            $candidate = '.' . implode('.', array_slice($parts, $i));
            $tld = $tldModel->findByTld($candidate);
            if ($tld) {
                $matchedTld = $candidate;
                $labelCount = $i;
                break;
            }
        }

        if (!$matchedTld) {
            $matchedTld = '.' . $parts[count($parts) - 1];
            $labelCount = count($parts) - 1;
        }

        if ($labelCount !== 1) {
            $rootDomain = $parts[$labelCount - 1] . $matchedTld;
            return [
                'valid' => false,
                'domain' => $domain,
                'error' => "\"$domain\" looks like a subdomain. Did you mean the root domain \"$rootDomain\"?"
            ];
        }

        return ['valid' => true, 'domain' => $domain, 'error' => null];
    }

    /**
     * Validate text field length
     *
     * @param string $value Value to validate
     * @param int $max Maximum length
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function validateLength(string $value, int $max, string $fieldName = 'Field'): ?string
    {
        if (strlen($value) > $max) {
            return "$fieldName is too long (maximum $max characters)";
        }
        return null;
    }

    /**
     * Validate string is within min and max length
     *
     * @param string $value Value to validate
     * @param int $min Minimum length
     * @param int $max Maximum length
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function validateLengthRange(string $value, int $min, int $max, string $fieldName = 'Field'): ?string
    {
        $len = strlen($value);
        
        if ($len < $min) {
            return "$fieldName is too short (minimum $min characters)";
        }
        
        if ($len > $max) {
            return "$fieldName is too long (maximum $max characters)";
        }
        
        return null;
    }

    /**
     * Sanitize text input (remove control characters)
     *
     * @param string $text Text to sanitize
     * @return string Sanitized text
     */
    public static function sanitizeText(string $text): string
    {
        // Remove control characters (except tabs and newlines for text areas)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return trim($text);
    }

    /**
     * Validate array size is within limits
     *
     * @param array $array Array to validate
     * @param int $max Maximum number of elements
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function validateArraySize(array $array, int $max, string $fieldName = 'Selection'): ?string
    {
        if (count($array) > $max) {
            return "$fieldName exceeds maximum of $max items";
        }
        return null;
    }

    /**
     * Sanitize search query
     *
     * @param string $query Search query
     * @param int $maxLength Maximum length (default 100)
     * @return string Sanitized query
     */
    public static function sanitizeSearch(string $query, int $maxLength = 100): string
    {
        // Remove control characters
        $query = self::sanitizeText($query);
        
        // Limit length
        if (strlen($query) > $maxLength) {
            $query = substr($query, 0, $maxLength);
        }
        
        return $query;
    }

    /**
     * Validate username format
     *
     * @param string $username Username to validate
     * @param int $minLength Minimum length (default 3)
     * @param int $maxLength Maximum length (default 50)
     * @return string|null Error message or null if valid
     */
    public static function validateUsername(string $username, int $minLength = 3, int $maxLength = 50): ?string
    {
        if (strlen($username) < $minLength) {
            return "Username must be at least $minLength characters";
        }
        
        if (strlen($username) > $maxLength) {
            return "Username must not exceed $maxLength characters";
        }
        
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return 'Username can only contain letters, numbers, and underscores';
        }
        
        return null;
    }

    /**
     * Validate URL format
     *
     * @param string $url URL to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate numeric value is within range
     *
     * @param mixed $value Value to validate
     * @param int|float $min Minimum value
     * @param int|float $max Maximum value
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function validateRange($value, $min, $max, string $fieldName = 'Value'): ?string
    {
        if (!is_numeric($value)) {
            return "$fieldName must be a number";
        }
        
        $numValue = is_float($min) || is_float($max) ? floatval($value) : intval($value);
        
        if ($numValue < $min || $numValue > $max) {
            return "$fieldName must be between $min and $max";
        }
        
        return null;
    }

    /**
     * Validate value is in allowed list (whitelist)
     *
     * @param mixed $value Value to validate
     * @param array $allowed Allowed values
     * @param string $fieldName Field name for error message
     * @return string|null Error message or null if valid
     */
    public static function validateInList($value, array $allowed, string $fieldName = 'Value'): ?string
    {
        if (!in_array($value, $allowed, true)) {
            return "$fieldName has an invalid value";
        }
        return null;
    }

    /**
     * Validate and sanitize tags
     *
     * @param string $tagsString Comma-separated tags
     * @param int $maxTags Maximum number of tags allowed (default 10)
     * @param int $maxLength Maximum length per tag (default 50)
     * @return array Array with 'valid' (bool), 'tags' (string), and 'error' (string|null)
     */
    public static function validateTags(string $tagsString, int $maxTags = 10, int $maxLength = 50): array
    {
        if (empty($tagsString)) {
            return ['valid' => true, 'tags' => '', 'error' => null];
        }

        // Split tags and clean them
        $tags = array_filter(array_map('trim', explode(',', $tagsString)));
        
        // Check tag count
        if (count($tags) > $maxTags) {
            return ['valid' => false, 'tags' => '', 'error' => "Maximum $maxTags tags allowed"];
        }

        // Validate each tag
        $validatedTags = [];
        foreach ($tags as $tag) {
            $tag = strtolower($tag);
            
            // Check length
            if (strlen($tag) > $maxLength) {
                return ['valid' => false, 'tags' => '', 'error' => "Tag '$tag' is too long (maximum $maxLength characters)"];
            }
            
            // Check format (alphanumeric and hyphens only)
            if (!preg_match('/^[a-z0-9-]+$/', $tag)) {
                return ['valid' => false, 'tags' => '', 'error' => "Tag '$tag' contains invalid characters (use only letters, numbers, and hyphens)"];
            }
            
            // Avoid duplicates
            if (!in_array($tag, $validatedTags)) {
                $validatedTags[] = $tag;
            }
        }

        return ['valid' => true, 'tags' => implode(',', $validatedTags), 'error' => null];
    }
}


