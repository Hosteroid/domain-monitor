<?php

namespace App\Services;

use App\Models\DnsHistory;
use App\Models\DnsRecord;
use App\Models\Setting;

/**
 * Saves DNS history (#75): the records a domain had before each change a DNS check or scan found,
 * and its last records when it is deleted.
 *
 * Nothing here throws. History is a record of what happened, so a failure to save it is logged
 * and must never stop a DNS check, an alert or a delete.
 */
class DnsHistoryService
{
    /** What saved an entry (dns_history.source), as shown to users */
    public const SOURCE_LABELS = [
        'cron'       => 'Scheduled check',
        'refresh'    => 'Manual refresh',
        'quick_scan' => 'Quick Scan',
        'deep_scan'  => 'Deep Scan',
        'deleted'    => 'Domain deleted',
    ];

    private DnsHistory $historyModel;
    private Setting $settingModel;
    private Logger $logger;

    /** Table there and global switch on, read once: a cron run asks for every domain */
    private ?bool $on = null;

    public function __construct()
    {
        $this->historyModel = new DnsHistory();
        $this->settingModel = new Setting();
        $this->logger = new Logger('dns');
    }

    /**
     * Whether history can be read and written: not between an update of the files and the
     * database update that adds the table.
     */
    public function isAvailable(): bool
    {
        try {
            return $this->historyModel->isInstalled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether history is saved for a domain: the global switch and the domain's own are both on
     *
     * @param array $domain Domain row
     */
    public function isOnFor(array $domain): bool
    {
        // A domain row read before the update has no switch yet: on, as the column defaults to
        if (($domain['dns_history_enabled'] ?? 1) != 1) {
            return false;
        }

        try {
            $this->on ??= $this->isAvailable() && $this->settingModel->isDnsHistoryOn();
        } catch (\Throwable $e) {
            return false;
        }

        return $this->on;
    }

    /**
     * Save the records a domain had before a change.
     *
     * @param array  $domain          Domain row
     * @param array  $previousRecords DnsRecord::getPreviousSnapshot() from before the lookup was saved
     * @param array  $changes         DnsService::diffRecords() of those records and the lookup
     * @param string $source          Key of SOURCE_LABELS
     * @return bool Whether an entry was saved
     */
    public function recordChange(array $domain, array $previousRecords, array $changes, string $source): bool
    {
        if (empty($changes['added']) && empty($changes['removed']) && empty($changes['changed'])) {
            return false;
        }

        try {
            if (!$this->isOnFor($domain)) {
                return false;
            }

            $saved = $this->historyModel->add($domain, $source, $previousRecords, self::trimChanges($changes)) !== null;
            $this->prune();

            return $saved;
        } catch (\Throwable $e) {
            $this->logFailure($domain, $e);
            return false;
        }
    }

    /**
     * Call right before a domain is deleted. Its entries get its name and owner, which is all that
     * identifies them afterwards, and its current records are saved as a last entry.
     *
     * @param array $domain Domain row
     * @return bool Whether the last entry was saved
     */
    public function recordDeletion(array $domain): bool
    {
        try {
            if (!$this->isAvailable()) {
                return false;
            }

            $this->historyModel->stampForDeletedDomain(
                (int)$domain['id'],
                $domain['domain_name'],
                !empty($domain['user_id']) ? (int)$domain['user_id'] : null
            );

            if (!$this->isOnFor($domain)) {
                return false;
            }

            $records = (new DnsRecord())->getPreviousSnapshot((int)$domain['id']);
            if (empty($records)) {
                return false;
            }

            $saved = $this->historyModel->add($domain, 'deleted', $records, null) !== null;
            $this->prune();

            return $saved;
        } catch (\Throwable $e) {
            $this->logFailure($domain, $e);
            return false;
        }
    }

    /**
     * Delete entries older than the "keep for" setting.
     *
     * @return int Entries deleted
     */
    public function prune(): int
    {
        try {
            if (!$this->isAvailable()) {
                return 0;
            }

            $days = $this->settingModel->getDnsHistoryRetentionDays();
            return $this->historyModel->deleteOlderThan(date('Y-m-d H:i:s', strtotime("-{$days} days")));
        } catch (\Throwable $e) {
            $this->logger->warning('DNS history cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Keep what identifies each changed record. A looked-up record also carries the raw answer
     * and IP details, which the saved records don't need twice.
     */
    private static function trimChanges(array $changes): array
    {
        $trim = static function (array $record): array {
            $entry = [
                'type'  => $record['record_type'] ?? '',
                'host'  => $record['host'] ?? '@',
                'value' => (string)($record['value'] ?? ''),
            ];
            if (isset($record['priority'])) {
                $entry['priority'] = (int)$record['priority'];
            }
            return $entry;
        };

        return [
            'added'   => array_map($trim, $changes['added'] ?? []),
            'removed' => array_map($trim, $changes['removed'] ?? []),
            'changed' => array_map(
                static fn(array $change): array => $trim($change['record']) + ['old_value' => (string)$change['old_value']],
                $changes['changed'] ?? []
            ),
        ];
    }

    private function logFailure(array $domain, \Throwable $e): void
    {
        try {
            $this->logger->warning('DNS history not saved', [
                'domain' => $domain['domain_name'] ?? null,
                'error'  => $e->getMessage(),
            ]);
        } catch (\Throwable $ignored) {
            // Logging is the last thing that may fail here
        }
    }
}
