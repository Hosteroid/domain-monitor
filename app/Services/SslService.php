<?php

namespace App\Services;

use App\Helpers\InputValidator;

class SslService
{
    private const DEFAULT_PORT = 443;
    private const CONNECT_TIMEOUT = 15;
    private const EXPIRING_SOON_DAYS = 30;

    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('ssl');
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

        if (!$primary['success']) {
            $fallback = $this->connect($hostname, $port, false);
            if ($fallback['success']) {
                $connection = $fallback;
            }
        }

        if (empty($connection['certificate'])) {
            $error = $primary['error'] ?: ($connection['error'] ?? 'Could not retrieve certificate');

            $this->logger->warning('SSL certificate fetch failed', [
                'hostname' => $hostname,
                'port' => $port,
                'error' => $error,
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
        $error = $primary['error'] ?? null;

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
            'last_error' => $status === 'valid' || $status === 'expiring' || $status === 'expired' ? null : $error,
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

    private function determineStatus(bool $verified, ?int $daysRemaining): string
    {
        if ($daysRemaining !== null && $daysRemaining < 0) {
            return 'expired';
        }

        if (!$verified) {
            return 'invalid';
        }

        if ($daysRemaining !== null && $daysRemaining <= self::EXPIRING_SOON_DAYS) {
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
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
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

        if (!$socket) {
            return [
                'success' => false,
                'error' => $warning ?: $errstr ?: ('Connection failed (' . $errno . ')'),
                'certificate' => null,
            ];
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        return [
            'success' => true,
            'error' => $warning,
            'certificate' => $params['options']['ssl']['peer_certificate'] ?? null,
        ];
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
