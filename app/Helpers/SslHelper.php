<?php

namespace App\Helpers;

use App\Services\SslService;

class SslHelper
{
    /**
     * Format an SSL certificate row for display
     * Adds computed fields: display_target, days_left, days_expired and status styling.
     * The status is re-evaluated against the current date, so a certificate that
     * crossed the expiring/expired line since its last check is shown as such.
     */
    public static function formatForDisplay(array $certificate, ?SslService $sslService = null): array
    {
        $sslService ??= new SslService();
        $secondsLeft = !empty($certificate['valid_to']) ? strtotime($certificate['valid_to']) - time() : null;

        $certificate['hostname'] = strtolower($certificate['hostname']);
        $certificate['port'] = (int)($certificate['port'] ?? 443);
        $certificate['display_target'] = $sslService->formatTargetLabel($certificate['hostname'], $certificate['port']);
        $certificate['days_left'] = $secondsLeft !== null ? (int)floor($secondsLeft / 86400) : null;
        // Whole days since expiry (days_left is floored, so it reads one day more)
        $certificate['days_expired'] = $secondsLeft !== null && $secondsLeft < 0 ? intdiv(-$secondsLeft, 86400) : null;
        $certificate['status'] = $sslService->determineStatus(!empty($certificate['is_trusted']), $certificate['days_left']);

        return array_merge($certificate, self::getStatusMeta($certificate['status']));
    }

    /**
     * Format multiple SSL certificates for display
     */
    public static function formatMultiple(array $certificates): array
    {
        $sslService = new SslService();

        return array_map(
            static fn(array $certificate): array => self::formatForDisplay($certificate, $sslService),
            $certificates
        );
    }

    /**
     * Get CSS classes and labels for an SSL status.
     */
    public static function getStatusMeta(string $status): array
    {
        return match ($status) {
            'valid' => [
                'status_label' => 'Valid & Trusted',
                'status_icon' => 'fa-check-circle',
                'status_badge_class' => 'bg-green-100 dark:bg-green-500/10 text-green-800 dark:text-green-400 border-green-200 dark:border-green-800',
                'card_border_class' => 'border-green-200 dark:border-green-800',
                'header_class' => 'bg-green-50 dark:bg-green-500/10 border-green-200 dark:border-green-800',
                'accent_class' => 'text-green-600 dark:text-green-400',
            ],
            'expiring' => [
                'status_label' => 'Expiring Soon',
                'status_icon' => 'fa-exclamation-triangle',
                'status_badge_class' => 'bg-amber-100 dark:bg-amber-500/10 text-amber-800 dark:text-amber-400 border-amber-200 dark:border-amber-800',
                'card_border_class' => 'border-amber-200 dark:border-amber-800',
                'header_class' => 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-800',
                'accent_class' => 'text-amber-600 dark:text-amber-400',
            ],
            'expired' => [
                'status_label' => 'Expired',
                'status_icon' => 'fa-times-circle',
                'status_badge_class' => 'bg-red-100 dark:bg-red-500/10 text-red-800 dark:text-red-400 border-red-200 dark:border-red-800',
                'card_border_class' => 'border-red-200 dark:border-red-800',
                'header_class' => 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-800',
                'accent_class' => 'text-red-600 dark:text-red-400',
            ],
            default => [
                'status_label' => 'Invalid / Untrusted',
                'status_icon' => 'fa-ban',
                'status_badge_class' => 'bg-red-100 dark:bg-red-500/10 text-red-800 dark:text-red-400 border-red-200 dark:border-red-800',
                'card_border_class' => 'border-red-200 dark:border-red-800',
                'header_class' => 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-800',
                'accent_class' => 'text-red-600 dark:text-red-400',
            ],
        };
    }

    /**
     * Build SSL summary counts by status.
     */
    public static function buildStats(array $certificates): array
    {
        $stats = [
            'total' => count($certificates),
            'valid' => 0,
            'expiring' => 0,
            'expired' => 0,
            'invalid' => 0,
        ];

        foreach ($certificates as $certificate) {
            $status = $certificate['status'] ?? 'invalid';
            if (isset($stats[$status])) {
                $stats[$status]++;
            } else {
                $stats['invalid']++;
            }
        }

        $stats['issues'] = $stats['expired'] + $stats['invalid'];
        return $stats;
    }

    /**
     * Summarise certificates for the lock icon on the domain list, keyed by domain id.
     * The worst live status sets the colour; a domain with no certificates yet gets a grey lock.
     *
     * @param int[] $domainIds Domains the SSL cron checks
     */
    public static function summarizeByDomain(array $domainIds, array $certificates): array
    {
        $byDomain = array_fill_keys(array_map('intval', $domainIds), []);
        foreach (self::formatMultiple($certificates) as $certificate) {
            $byDomain[(int)$certificate['domain_id']][] = $certificate;
        }

        return array_map(
            static fn(array $domainCertificates): array => self::summarizeDomain($domainCertificates),
            $byDomain
        );
    }

    private static function summarizeDomain(array $certificates): array
    {
        if (empty($certificates)) {
            return [
                'status' => null,
                'label' => 'SSL monitoring is on, not checked yet',
                'icon' => 'fa-lock',
                'accent_class' => 'text-gray-400 dark:text-slate-500',
            ];
        }

        $stats = self::buildStats($certificates);
        $worst = null;
        $parts = [];
        foreach (['expired' => 'expired', 'invalid' => 'invalid', 'expiring' => 'expiring soon', 'valid' => 'valid'] as $status => $text) {
            if ($stats[$status] > 0) {
                $worst ??= $status;
                $parts[] = $stats[$status] . ' ' . $text;
            }
        }

        if ($stats['total'] === 1) {
            $daysLeft = $certificates[0]['days_left'];
            $daysText = $daysLeft === null ? '' : ($daysLeft < 1 ? 'less than a day' : $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'));
            $label = match ($worst) {
                'valid' => 'SSL certificate valid' . ($daysText !== '' ? ", {$daysText} left" : ''),
                'expiring' => "SSL certificate expires in {$daysText}",
                'expired' => 'SSL certificate expired',
                default => 'SSL certificate invalid or untrusted',
            };
        } else {
            $label = $stats['total'] . ' SSL certificates: ' . implode(', ', $parts);
        }

        return [
            'status' => $worst,
            'label' => $label,
            'icon' => in_array($worst, ['expired', 'invalid'], true) ? 'fa-unlock-alt' : 'fa-lock',
            'accent_class' => self::getStatusMeta($worst)['accent_class'],
        ];
    }
}
