<?php

namespace App\Services;

use App\Helpers\InputValidator;
use App\Models\Setting;
use App\Models\SslCertificate;

class SslService
{
    private const DEFAULT_PORT = 443;
    private const CONNECT_TIMEOUT = 15;
    private const DEFAULT_EXPIRING_SOON_DAYS = 14;

    // errno values for refused, timed out, host/network unreachable and reset (Linux, macOS, Windows)
    private const NETWORK_ERRNOS = [111, 61, 10061, 110, 60, 10060, 113, 65, 10065, 101, 51, 10051, 104, 54, 10054];
    private const NETWORK_ERROR_PATTERN = '/refused|timed out|timeout|did not properly respond|getaddrinfo|no such host|name or service not known|nodename nor servname|unreachable|no route to host|connection reset|reset by peer|forcibly closed/i';

    private Logger $logger;
    private int $expiringSoonDays;

    public function __construct()
    {
        $this->logger = new Logger('ssl');

        try {
            $this->expiringSoonDays = (new Setting())->getSslExpiringSoonDays();
        } catch (\Exception $e) {
            $this->expiringSoonDays = self::DEFAULT_EXPIRING_SOON_DAYS;
        }
    }

    /**
     * Normalize a user-supplied SSL host into a monitored hostname for the domain.
     */
    public function normalizeHostname(string $input, string $baseDomain): ?string
    {
        $target = $this->parseMonitorTarget($input, $baseDomain);
        return $target['hostname'] ?? null;
    }

    /**
     * Parse a user-supplied SSL monitoring target into hostname + port.
     *
     * @return array{hostname:string,port:int}|null
     */
    public function parseMonitorTarget(string $input, string $baseDomain): ?array
    {
        $baseDomain = strtolower(trim($baseDomain));
        $input = strtolower(trim($input));
        $port = self::DEFAULT_PORT;

        if ($input === '') {
            return null;
        }

        if (str_contains($input, '://') || preg_match('/[\/\\\\\s?#]/', $input)) {
            return null;
        }

        $colonPos = strrpos($input, ':');
        if ($colonPos !== false) {
            $portText = substr($input, $colonPos + 1);
            if ($portText === '' || !ctype_digit($portText)) {
                return null;
            }

            $port = (int)$portText;
            if ($port < 1 || $port > 65535) {
                return null;
            }

            $input = substr($input, 0, $colonPos);
            if ($input === '') {
                return null;
            }
        }

        $hostname = $this->normalizeMonitorHostname($input, $baseDomain);
        if ($hostname === null) {
            return null;
        }

        return [
            'hostname' => $hostname,
            'port' => $port,
        ];
    }

    /**
     * Check an endpoint and save the result, keeping the expiry reminder bookkeeping. Says whether
     * the check should alert: a status change, or a first check that already has a problem (an
     * expiring certificate is announced by its reminder days instead, unless the last check
     * failed), and which reminder day is due. The same certificate leaving the expiring window
     * because the reminder days were lowered has not recovered, so that change doesn't alert.
     * The SSL cron and refreshes started by a user both check through here, so each reminder
     * day goes out once per certificate whoever runs the check.
     *
     * @param int[] $reminderDays Setting::getSslNotificationDays(), largest first
     * @return array{id:int,snapshot:array,previous_status:?string,status_changed:bool,status_alert:bool,reminder_days:?int}
     */
    public function checkEndpoint(int $domainId, string $hostname, int $port, array $reminderDays): array
    {
        $sslModel = new SslCertificate();
        $existing = $sslModel->findByDomainAndHost($domainId, $hostname, $port);
        $previousStatus = $existing['status'] ?? null;

        $snapshot = $this->fetchCertificateSnapshot($hostname, $port);
        $id = $sslModel->saveSnapshot($domainId, $hostname, $snapshot, $port);

        // Until the update adds the reminder columns there is nowhere to remember reminders, so an
        // expiring certificate is announced by its status change, as before
        $remindersSupported = $sslModel->hasColumn('last_reminder_valid_to');
        $reminderDue = $remindersSupported ? $this->resolveReminder($snapshot, $existing, $reminderDays) : null;
        if ($reminderDue !== null) {
            $sslModel->update($id, ['last_reminder_days' => $reminderDue, 'last_reminder_valid_to' => $snapshot['valid_to']]);
        }

        $status = $snapshot['status'];
        $statusChanged = $previousStatus !== null
            ? $previousStatus !== $status
            : in_array($status, ['expiring', 'expired', 'invalid'], true);
        $sameCertificate = $snapshot['valid_to'] !== null && ($existing['valid_to'] ?? null) === $snapshot['valid_to'];
        $leftExpiringWindow = $previousStatus === 'expiring' && $status === 'valid' && $sameCertificate;
        $announcedByReminders = $status === 'expiring' && $remindersSupported
            && !in_array($previousStatus, ['invalid', 'expired'], true);

        return [
            'id' => $id,
            'snapshot' => $snapshot,
            'previous_status' => $previousStatus,
            'status_changed' => $statusChanged,
            'status_alert' => $statusChanged && !$leftExpiringWindow && !$announcedByReminders,
            'reminder_days' => $reminderDue,
        ];
    }

    /**
     * Work out the expiry reminder due for a certificate, or null. Each reminder day is sent once
     * as the days left reach it, so a missed check still sends the next one. The reminder day
     * sent is kept with the expiry of the certificate it was sent for: a check that failed or
     * read another status in between doesn't start it over, a renewed certificate does.
     *
     * @param int[] $reminderDays Largest first
     */
    public function resolveReminder(array $snapshot, ?array $existing, array $reminderDays): ?int
    {
        $daysLeft = $snapshot['days_remaining'];
        if ($snapshot['status'] !== 'expiring' || $daysLeft === null) {
            return null;
        }

        $sameCertificate = $existing !== null && ($existing['last_reminder_valid_to'] ?? null) === $snapshot['valid_to'];
        $lastReminder = $sameCertificate && isset($existing['last_reminder_days'])
            ? (int)$existing['last_reminder_days']
            : null;

        // Smallest reminder day the certificate has reached
        $reached = null;
        foreach ($reminderDays as $days) {
            if ($daysLeft <= $days) {
                $reached = $days;
            }
        }

        if ($reached === null || ($lastReminder !== null && $reached >= $lastReminder)) {
            return null;
        }

        return $reached;
    }

    /**
     * Fetch and parse certificate details for a hostname.
     *
     * @return array{status:string,is_trusted:bool,is_self_signed:bool,valid_from:?string,valid_to:?string,days_remaining:?int,issuer_name:?string,subject_name:?string,serial_number:?string,signature_algorithm:?string,key_bits:?int,key_type:?string,certificate_version:?string,san_list:array,last_checked:string,last_error:?string,raw_data:array}
     */
    public function fetchCertificateSnapshot(string $hostname, int $port = self::DEFAULT_PORT): array
    {
        $hostname = strtolower(trim($hostname));
        $now = date('Y-m-d H:i:s');

        $primary = $this->connect($hostname, $port, true);
        $verified = $primary['success'];
        $connection = $primary;

        // Connect again without verification to read a certificate that failed it; not when the
        // host could not be reached at all, which would only fail (or time out) a second time
        if (!$primary['success'] && !$this->isNetworkFailure($primary, $hostname)) {
            $fallback = $this->connect($hostname, $port, false);
            if ($fallback['success']) {
                $connection = $fallback;
            }
        }

        if (empty($connection['certificate'])) {
            $error = $this->describeConnectionError((string)($primary['error'] ?? ''), $hostname, $port);

            $this->logger->warning('SSL certificate fetch failed', [
                'hostname' => $hostname,
                'port' => $port,
                'error' => $error,
                'detail' => $primary['error'] ?? null,
            ]);

            return [
                'status' => 'invalid',
                'is_trusted' => false,
                'is_self_signed' => false,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'subject_name' => null,
                'serial_number' => null,
                'signature_algorithm' => null,
                'key_bits' => null,
                'key_type' => null,
                'certificate_version' => null,
                'san_list' => [],
                'last_checked' => $now,
                'last_error' => $error,
                'raw_data' => [
                    'hostname' => $hostname,
                    'port' => $port,
                    'verified_attempt_error' => $primary['error'] ?? null,
                ],
            ];
        }

        $parsed = @openssl_x509_parse($connection['certificate']);
        if (!is_array($parsed)) {
            return [
                'status' => 'invalid',
                'is_trusted' => false,
                'is_self_signed' => false,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'issuer_name' => null,
                'subject_name' => null,
                'serial_number' => null,
                'signature_algorithm' => null,
                'key_bits' => null,
                'key_type' => null,
                'certificate_version' => null,
                'san_list' => [],
                'last_checked' => $now,
                'last_error' => 'Could not parse certificate',
                'raw_data' => [
                    'hostname' => $hostname,
                    'port' => $port,
                    'verified_attempt_error' => $primary['error'] ?? null,
                ],
            ];
        }

        $publicKeyDetails = $this->getPublicKeyDetails($connection['certificate']);
        $validFromTs = isset($parsed['validFrom_time_t']) ? (int)$parsed['validFrom_time_t'] : null;
        $validToTs = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : null;
        $daysRemaining = $validToTs !== null ? (int)floor(($validToTs - time()) / 86400) : null;
        $subjectName = $this->formatDistinguishedName($parsed['subject'] ?? []);
        $issuerName = $this->formatDistinguishedName($parsed['issuer'] ?? []);
        $isSelfSigned = $subjectName !== '' && $subjectName === $issuerName;
        $sanList = $this->extractSanList($parsed);
        $status = $this->determineStatus($verified, $daysRemaining);
        $error = $status === 'invalid'
            ? $this->describeVerificationFailure(
                $hostname,
                $sanList,
                $subjectName,
                $issuerName,
                $isSelfSigned,
                $connection['chain'] ?? [],
                (string)($primary['error'] ?? '')
            )
            : null;

        $snapshot = [
            'status' => $status,
            'is_trusted' => $verified,
            'is_self_signed' => $isSelfSigned,
            'valid_from' => $validFromTs ? date('Y-m-d H:i:s', $validFromTs) : null,
            'valid_to' => $validToTs ? date('Y-m-d H:i:s', $validToTs) : null,
            'days_remaining' => $daysRemaining,
            'issuer_name' => $issuerName ?: null,
            'subject_name' => $subjectName ?: null,
            'serial_number' => $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? null),
            'signature_algorithm' => $parsed['signatureTypeLN'] ?? ($parsed['signatureTypeSN'] ?? null),
            'key_bits' => $publicKeyDetails['bits'],
            'key_type' => $publicKeyDetails['type'],
            'certificate_version' => isset($parsed['version']) ? 'v' . ((int)$parsed['version'] + 1) : null,
            'san_list' => $sanList,
            'last_checked' => $now,
            'last_error' => $error,
            'raw_data' => [
                'hostname' => $hostname,
                'port' => $port,
                'subject' => $parsed['subject'] ?? [],
                'issuer' => $parsed['issuer'] ?? [],
                'extensions' => $parsed['extensions'] ?? [],
                'verified_attempt_error' => $primary['error'] ?? null,
                'san_list' => $sanList,
            ],
        ];

        $this->logger->info('SSL certificate fetched', [
            'hostname' => $hostname,
            'port' => $port,
            'status' => $snapshot['status'],
            'trusted' => $snapshot['is_trusted'],
            'days_remaining' => $snapshot['days_remaining'],
        ]);

        return $snapshot;
    }

    /**
     * Format a monitored target for display and notifications.
     */
    public function formatTargetLabel(string $hostname, int $port = self::DEFAULT_PORT): string
    {
        $hostname = strtolower(trim($hostname));
        return $port === self::DEFAULT_PORT ? $hostname : $hostname . ':' . $port;
    }

    /**
     * Determine the certificate status from its trust result and remaining validity.
     */
    public function determineStatus(bool $verified, ?int $daysRemaining): string
    {
        if ($daysRemaining !== null && $daysRemaining < 0) {
            return 'expired';
        }

        if (!$verified) {
            return 'invalid';
        }

        if ($daysRemaining !== null && $daysRemaining <= $this->expiringSoonDays) {
            return 'expiring';
        }

        return 'valid';
    }

    private function connect(string $hostname, int $port, bool $verifyPeer): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'SNI_enabled' => true,
                'peer_name' => $hostname,
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
                'disable_compression' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $warnings = [];

        // Keep every warning: the first ones carry the OpenSSL reason, the last is a generic "Unable to connect"
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        try {
            $socket = @stream_socket_client(
                "ssl://{$hostname}:{$port}",
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } finally {
            restore_error_handler();
        }

        $warning = $warnings ? implode("\n", $warnings) : null;

        if (!$socket) {
            return [
                'success' => false,
                'error' => $warning ?: $errstr ?: ('Connection failed (' . $errno . ')'),
                'errno' => $errno,
                'certificate' => null,
                'chain' => [],
            ];
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        return [
            'success' => true,
            'error' => $warning,
            'errno' => 0,
            'certificate' => $params['options']['ssl']['peer_certificate'] ?? null,
            'chain' => $params['options']['ssl']['peer_certificate_chain'] ?? [],
        ];
    }

    /**
     * Whether a failed connection never reached the TLS handshake (refused, timed out,
     * unreachable, reset, unknown host), so a second attempt would fail the same way.
     */
    private function isNetworkFailure(array $attempt, string $hostname): bool
    {
        if (in_array((int)($attempt['errno'] ?? 0), self::NETWORK_ERRNOS, true)) {
            return true;
        }

        return (bool)preg_match(self::NETWORK_ERROR_PATTERN, $this->withoutNames((string)($attempt['error'] ?? ''), $hostname));
    }

    /**
     * A connection error without the hostname and the certificate names PHP quotes in it, so a
     * name such as timeout.example.com can't match the patterns that read the error.
     */
    private function withoutNames(string $error, string $hostname): string
    {
        return preg_replace("/CN=`[^']*'/", 'CN=', str_ireplace($hostname, '', $error)) ?? $error;
    }

    /**
     * Explain in plain words why no certificate could be read from an endpoint.
     */
    private function describeConnectionError(string $error, string $hostname, int $port): string
    {
        $reasons = [
            '/refused/i' => "Connection refused on port {$port}",
            '/timed out|timeout|did not properly respond/i' => "Connection timed out on port {$port}",
            '/getaddrinfo|no such host|name or service not known|nodename nor servname/i' => 'Hostname could not be resolved',
            '/unreachable|no route to host/i' => 'Host or network is unreachable',
            '/connection reset|reset by peer|forcibly closed/i' => 'Connection was reset by the server',
            '/wrong version number|unknown protocol|http request|packet length too long|record layer failure/i' => "No TLS on port {$port}: it does not look like an HTTPS port (STARTTLS ports such as 587 are not supported)",
            '/handshake failure|unsupported protocol|no protocols available|no shared cipher|alert/i' => 'TLS handshake failed: no protocol version or cipher in common',
        ];

        $withoutNames = $this->withoutNames($error, $hostname);
        foreach ($reasons as $pattern => $reason) {
            if (preg_match($pattern, $withoutNames)) {
                return $reason;
            }
        }

        if (trim($error) === '') {
            return "No certificate received on port {$port}";
        }

        // Unknown error: the first message, without the "stream_socket_client(): " prefix
        return trim(preg_replace('/^\w+\(\):\s*/', '', strtok($error, "\n")));
    }

    /**
     * Explain in plain words why a certificate that was received is not trusted.
     */
    private function describeVerificationFailure(
        string $hostname,
        array $sanList,
        string $subjectName,
        string $issuerName,
        bool $isSelfSigned,
        array $chain,
        string $error
    ): string {
        $names = $sanList ?: ($subjectName !== '' ? [$subjectName] : []);
        $isIpAddress = filter_var($hostname, FILTER_VALIDATE_IP) !== false;

        if (str_contains($error, 'did not match expected CN')
            || ($names && !$isIpAddress && !$this->certificateCoversHost($hostname, $names))) {
            if (empty($names)) {
                return "Hostname mismatch: the certificate is not valid for {$hostname}";
            }

            $shown = array_slice($names, 0, 3);
            $more = count($names) - count($shown);
            return 'Hostname mismatch: the certificate is for ' . implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');
        }

        if ($isSelfSigned) {
            return 'Self-signed certificate: not issued by a trusted certificate authority';
        }

        // Only the server's own certificate was sent, without the intermediate that links it to a trusted root
        if (count($chain) <= 1) {
            return 'Incomplete certificate chain: the server does not send the intermediate certificate';
        }

        return 'Untrusted certificate authority' . ($issuerName !== '' ? ": {$issuerName}" : '');
    }

    /**
     * Whether a certificate name list covers a hostname. A wildcard covers exactly one label:
     * *.example.com matches www.example.com, but not example.com or a.b.example.com.
     */
    private function certificateCoversHost(string $hostname, array $names): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));

        foreach ($names as $name) {
            $name = strtolower(rtrim(trim((string)$name), '.'));
            if ($name === $hostname) {
                return true;
            }

            if (str_starts_with($name, '*.') && str_ends_with($hostname, substr($name, 1))) {
                $label = substr($hostname, 0, -strlen(substr($name, 1)));
                if ($label !== '' && !str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getPublicKeyDetails($certificate): array
    {
        $publicKey = @openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return ['bits' => null, 'type' => null];
        }

        $details = @openssl_pkey_get_details($publicKey) ?: [];
        if (PHP_VERSION_ID < 80000) {
            @openssl_free_key($publicKey);
        }

        return [
            'bits' => isset($details['bits']) ? (int)$details['bits'] : null,
            'type' => $this->mapKeyType($details['type'] ?? null),
        ];
    }

    private function mapKeyType(?int $type): ?string
    {
        return match ($type) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            OPENSSL_KEYTYPE_DH => 'DH',
            OPENSSL_KEYTYPE_EC => 'EC',
            default => null,
        };
    }

    private function extractSanList(array $parsed): array
    {
        $sanText = $parsed['extensions']['subjectAltName'] ?? '';
        if ($sanText === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $sanText) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $result[] = substr($entry, 4);
            }
        }

        return array_values(array_unique(array_filter($result)));
    }

    private function formatDistinguishedName(array $parts): string
    {
        if (!empty($parts['CN'])) {
            return (string)$parts['CN'];
        }

        foreach (['O', 'OU', 'emailAddress'] as $field) {
            if (!empty($parts[$field])) {
                return (string)$parts[$field];
            }
        }

        $values = [];
        foreach ($parts as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $values[] = $key . '=' . $value;
            }
        }

        return implode(', ', $values);
    }

    private function normalizeMonitorHostname(string $input, string $baseDomain): ?string
    {
        if ($input === '' || $input === '@') {
            return $baseDomain;
        }

        $input = rtrim($input, '.');

        if ($input === $baseDomain) {
            return $baseDomain;
        }

        if (InputValidator::validateDomain($input) && str_ends_with($input, '.' . $baseDomain)) {
            return $input;
        }

        if (!$this->isValidRelativeHost($input)) {
            return null;
        }

        $candidate = $input . '.' . $baseDomain;
        return InputValidator::validateDomain($candidate) ? $candidate : null;
    }

    private function isValidRelativeHost(string $host): bool
    {
        return (bool)preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',
            $host
        );
    }
}
