<?php

namespace Core;

use PDO;

abstract class Model
{
    protected static string $table;
    protected PDO $db;

    /** @var array<string,bool> Columns looked up by hasColumn(), keyed by "table.column" */
    private static array $columns = [];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Whether the table has a column: one a migration that hasn't run yet adds is missing until
     * the update is run, so code writing it can leave it out in between
     */
    public function hasColumn(string $column): bool
    {
        $key = static::$table . '.' . $column;
        if (!isset(self::$columns[$key])) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([static::$table, $column]);
            self::$columns[$key] = (int)$stmt->fetchColumn() > 0;
        }

        return self::$columns[$key];
    }

    public function all(): array
    {
        $stmt = $this->db->query("SELECT * FROM " . static::$table . " ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO " . static::$table . " ($columns) VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $set = implode(', ', array_map(fn($col) => "$col = ?", array_keys($data)));
        $sql = "UPDATE " . static::$table . " SET $set WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([...array_values($data), $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . static::$table . " WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function where(string $column, $value): array
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE $column = ?");
        $stmt->execute([$value]);
        return $stmt->fetchAll();
    }
}

