<?php

namespace Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    /** A connection was tried in this request and failed */
    private static bool $failed = false;

    public function __construct()
    {
        if (self::$pdo === null) {
            $this->connect();
        }
    }

    private function connect()
    {
        $host = $_ENV['DB_HOST'] ?? null;
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? null;
        $username = $_ENV['DB_USERNAME'] ?? null;
        $password = $_ENV['DB_PASSWORD'] ?? '';

        // Validate required credentials
        if (empty($host) || empty($database) || empty($username)) {
            throw new \Exception(
                "Database credentials not configured!\n\n" .
                "Missing in .env file:\n" .
                (!empty($host) ? "" : "- DB_HOST\n") .
                (!empty($database) ? "" : "- DB_DATABASE\n") .
                (!empty($username) ? "" : "- DB_USERNAME\n") .
                "\nPlease update your .env file with valid database credentials."
            );
        }

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
            self::$pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            self::$failed = true;
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Whether connecting was tried and failed, so the error handler does not try again
     */
    public static function hasFailed(): bool
    {
        return self::$failed;
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            new self();
        }
        return self::$pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

