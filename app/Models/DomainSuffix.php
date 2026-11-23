<?php

namespace App\Models;

use Core\Model;

/**
 * Domain endings that take registrations, from the registry part of the Public Suffix List.
 *
 * A rule is kept as its ending in lower case ASCII with a type: a plain rule (co.uk), a wildcard
 * (*.ck: every label under the ending is an ending too) or an exception to a wildcard (!www.ck).
 */
class DomainSuffix extends Model
{
    protected static string $table = 'domain_suffixes';

    public const RULE = 0;
    public const WILDCARD = 1;
    public const EXCEPTION = 2;

    /**
     * Whether the table is there: the update that adds it may not have run yet
     */
    public function isInstalled(): bool
    {
        return $this->hasColumn('rule_type');
    }

    public function countAll(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM domain_suffixes")->fetchColumn();
    }

    /**
     * The rules kept for any of these endings
     *
     * @param string[] $suffixes
     * @return array<int, array{suffix: string, rule_type: int}>
     */
    public function findRules(array $suffixes): array
    {
        if (!$suffixes) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT suffix, rule_type FROM domain_suffixes WHERE suffix IN ("
            . implode(',', array_fill(0, count($suffixes), '?')) . ")"
        );
        $stmt->execute(array_values($suffixes));

        return array_map(
            static fn(array $row): array => ['suffix' => (string)$row['suffix'], 'rule_type' => (int)$row['rule_type']],
            $stmt->fetchAll()
        );
    }

    /**
     * Replace every rule with these, in one transaction, so a check never sees half a list
     *
     * @param array<int, array{0: string, 1: int}> $rules Ending and type
     */
    public function replaceAll(array $rules): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec("DELETE FROM domain_suffixes");
            foreach (array_chunk($rules, 500) as $chunk) {
                $values = [];
                foreach ($chunk as [$suffix, $type]) {
                    $values[] = $suffix;
                    $values[] = $type;
                }
                $stmt = $this->db->prepare(
                    "INSERT IGNORE INTO domain_suffixes (suffix, rule_type) VALUES "
                    . implode(',', array_fill(0, count($chunk), '(?, ?)'))
                );
                $stmt->execute($values);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
