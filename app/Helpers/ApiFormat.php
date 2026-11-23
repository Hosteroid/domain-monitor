<?php

namespace App\Helpers;

/**
 * Values as the API gives them: dates as YYYY-MM-DD, times as ISO 8601 with an offset.
 */
class ApiFormat
{
    /**
     * A unix time, in the app's time zone
     */
    public static function unixTime(int|string|null $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || (int)$timestamp <= 0) {
            return null;
        }

        return date(DATE_ATOM, (int)$timestamp);
    }

    /**
     * A time PHP saved as "Y-m-d H:i:s". Those are written with date(), so they are wall clock
     * time of the app's time zone, whatever zone the database runs in.
     */
    public static function localTime(?string $value): ?string
    {
        if (empty($value) || str_starts_with($value, '0000')) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            substr($value, 0, 19),
            new \DateTimeZone(date_default_timezone_get())
        );

        return $time ? $time->format(DATE_ATOM) : null;
    }

    /**
     * A domain, from a row that has group_name and tags: Domain::getAllWithGroups() or
     * getWithTagsAndGroups(). The fields are listed one by one, so nothing else a row holds
     * (the lookup record, billing, the owner, the group's channels) can slip out.
     *
     * @param int      $threshold Days before expiry from which an active domain counts as expiring soon
     * @param int|null $createdAt Unix time the domain was added, from Domain::createdTimestamps()
     */
    public static function domain(array $row, int $threshold, ?int $createdAt): array
    {
        $shown = DomainHelper::formatForDisplay($row);
        $lookup = DomainHelper::lookupFields($row);
        $daysLeft = $shown['daysLeft'];

        // As the status filter of the list reads it: active and inside the reminder window is expiring soon
        $status = (string)$shown['displayStatus'];
        if ($status === 'active' && $daysLeft !== null && $daysLeft > 0 && $daysLeft <= $threshold) {
            $status = 'expiring_soon';
        }

        return [
            'id' => (int)$row['id'],
            'domain' => (string)$row['domain_name'],
            'status' => $status,
            'is_active' => !empty($row['is_active']),
            'expiration_date' => self::date($row['expiration_date'] ?? null),
            'days_left' => $daysLeft,
            'manual_expiration' => !empty($shown['isManualExpiration']),
            'registrar' => ($row['registrar'] ?? null) ?: null,
            'creation_date' => self::looseDate($lookup['creation_date']),
            'nameservers' => $lookup['nameservers'],
            'group' => !empty($row['notification_group_id'])
                ? ['id' => (int)$row['notification_group_id'], 'name' => (string)($row['group_name'] ?? '')]
                : null,
            'tags' => array_values(array_unique(array_filter(explode(',', (string)($row['tags'] ?? '')), 'strlen'))),
            'dns_monitoring' => !empty($row['dns_monitoring_enabled']),
            'ssl_monitoring' => !empty($row['ssl_monitoring_enabled']),
            'last_checked' => self::localTime($row['last_checked'] ?? null),
            'created_at' => self::unixTime($createdAt),
        ];
    }

    /**
     * A domain with everything the single-domain answer adds
     *
     * @param array $certificates Its certificate rows, through SslHelper::formatMultiple()
     */
    public static function domainDetail(array $row, int $threshold, ?int $createdAt, array $certificates): array
    {
        $saved = is_string($row['whois_data'] ?? null) ? json_decode($row['whois_data'], true) : null;
        $saved = is_array($saved) ? $saved : [];
        $text = static fn($value): ?string => is_string($value) && trim($value) !== '' ? $value : null;

        return self::domain($row, $threshold, $createdAt) + [
            'registrar_url' => $text($row['registrar_url'] ?? null),
            'updated_date' => self::date($row['updated_date'] ?? null),
            'abuse_email' => $text($row['abuse_email'] ?? null),
            'whois_server' => $text($saved['whois_server'] ?? null),
            'privacy' => DomainHelper::lookupFields($row)['privacy'],
            'registry_statuses' => DomainHelper::parseWhoisStatuses(
                array_values(array_filter((array)($saved['status'] ?? []), 'is_string'))
            ),
            'lookup_note' => $text($saved['lookup_note'] ?? null),
            'notes' => $text($row['notes'] ?? null),
            'dns_last_checked' => self::localTime($row['dns_last_checked'] ?? null),
            'ssl_last_checked' => self::localTime($row['ssl_last_checked'] ?? null),
            'certificates' => array_map([self::class, 'certificate'], array_values($certificates)),
        ];
    }

    /**
     * A DNS record
     */
    public static function dnsRecord(array $row): array
    {
        return [
            'type' => (string)$row['record_type'],
            'host' => (string)$row['host'],
            'value' => (string)$row['value'],
            'ttl' => isset($row['ttl']) ? (int)$row['ttl'] : null,
            'priority' => isset($row['priority']) ? (int)$row['priority'] : null,
            'source' => (string)($row['source'] ?? 'discovered'),
            'first_seen' => self::localTime($row['first_seen_at'] ?? null),
            'last_seen' => self::localTime($row['last_seen_at'] ?? null),
        ];
    }

    /**
     * A date a registry wrote in its own way, such as 1997-04-24T04:00:00Z or 24-Apr-1997
     */
    public static function looseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return self::date($value) ?? ($time !== false ? date('Y-m-d', $time) : null);
    }

    /**
     * A certificate, from a row that went through SslHelper::formatForDisplay()
     */
    public static function certificate(array $row): array
    {
        // The list query reads the issuer's organisation itself, a plain row still has it in raw_data
        $organization = $row['issuer_organization'] ?? null;
        if ($organization === null && is_string($row['raw_data'] ?? null)) {
            $raw = json_decode($row['raw_data'], true);
            $organization = is_array($raw) && is_string($raw['issuer']['O'] ?? null) ? $raw['issuer']['O'] : null;
        }

        return [
            'hostname' => (string)$row['hostname'],
            'port' => (int)$row['port'],
            'status' => (string)$row['status'],
            'valid_from' => self::localTime($row['valid_from'] ?? null),
            'valid_to' => self::localTime($row['valid_to'] ?? null),
            'days_left' => isset($row['days_left']) ? (int)$row['days_left'] : null,
            'issuer' => ($organization ?: ($row['issuer_name'] ?? null)) ?: null,
            'last_checked' => self::localTime($row['last_checked'] ?? null),
            'last_error' => ($row['last_error'] ?? null) ?: null,
        ];
    }

    /**
     * A date, without any time that follows it. Cutting the text keeps the day as saved: going
     * through strtotime() could move it across midnight.
     */
    public static function date(?string $value): ?string
    {
        $date = substr((string)$value, 0, 10);

        return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) && !str_starts_with($date, '0000') ? $date : null;
    }
}
