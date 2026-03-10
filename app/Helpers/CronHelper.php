<?php

namespace App\Helpers;

/**
 * Shared utilities for cron scripts (logging, formatting, DNS checks).
 *
 * Replaces the standalone functions that were duplicated across
 * check_dns.php, check_ssl.php, and check_domains.php.
 */
class CronHelper
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * Write a timestamped message to the log file and echo it.
     */
    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
        echo $line;
    }

    /**
     * Log elapsed time since a given microtime start.
     */
    public function logTimeSince(float $since, string $prefix = '  ⏱ '): void
    {
        $this->log($prefix . self::formatDuration(microtime(true) - $since));
    }

    /**
     * Short human-readable duration: "3.2s" or "2m 14.1s".
     */
    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);
        return $minutes . 'm ' . sprintf('%.1fs', $remaining);
    }

    /**
     * Verbose elapsed time: "3.25 seconds", "2 minutes 14.25 seconds", etc.
     */
    public static function formatElapsedTime(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.2f seconds', $seconds);
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            $remaining = $seconds - ($minutes * 60);
            return sprintf('%d minute%s %.2f seconds', $minutes, $minutes !== 1 ? 's' : '', $remaining);
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds - ($hours * 3600)) / 60);
        $remaining = $seconds - ($hours * 3600) - ($minutes * 60);
        return sprintf(
            '%d hour%s %d minute%s %.2f seconds',
            $hours,
            $hours !== 1 ? 's' : '',
            $minutes,
            $minutes !== 1 ? 's' : '',
            $remaining
        );
    }

    /**
     * Check whether a hostname resolves at all (SOA, A, or AAAA).
     */
    public static function hostnameResolves(string $hostname): bool
    {
        return @checkdnsrr($hostname, 'SOA')
            || @checkdnsrr($hostname, 'A')
            || @checkdnsrr($hostname, 'AAAA');
    }
}
