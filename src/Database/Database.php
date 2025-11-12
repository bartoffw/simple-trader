<?php

namespace SimpleTrader\Database;

use PDO;
use PDOException;

/**
 * Database Singleton Class
 *
 * Provides a single PDO connection instance for SQLite database operations
 */
class Database
{
    private static array $instances = [];
    private ?PDO $connection = null;
    private string $databasePath;

    /**
     * Private constructor to prevent direct instantiation
     *
     * @param string $databasePath Path to SQLite database file
     */
    private function __construct(string $databasePath)
    {
        $this->databasePath = $databasePath;
        $this->connect();
    }

    /**
     * Get singleton instance of Database for a specific path
     *
     * @param string $databasePath Path to SQLite database file
     * @return Database
     * @throws \RuntimeException If path not provided
     */
    public static function getInstance(string $databasePath): Database
    {
        // Use absolute path as key to ensure consistency
        $absolutePath = realpath($databasePath);

        // If file doesn't exist yet, use the provided path as-is
        if ($absolutePath === false) {
            $absolutePath = $databasePath;
        }

        if (!isset(self::$instances[$absolutePath])) {
            self::$instances[$absolutePath] = new self($absolutePath);
        }

        return self::$instances[$absolutePath];
    }

    /**
     * Establish PDO connection to SQLite database
     *
     * @return void
     * @throws PDOException
     */
    private function connect(): void
    {
        try {
            $dsn = 'sqlite:' . $this->databasePath;

            $this->connection = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Enable foreign key constraints
            $this->connection->exec('PRAGMA foreign_keys = ON;');

        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get PDO connection instance
     *
     * @return PDO
     */
    public function getConnection(): PDO
    {
        // Reconnect if connection was lost
        if ($this->connection === null) {
            $this->connect();
        }

        // Ensure foreign keys are enabled (SQLite requires this per-connection)
        $this->connection->exec('PRAGMA foreign_keys = ON;');

        return $this->connection;
    }

    /**
     * Execute a SQL file (for migrations)
     *
     * @param string $filePath Path to SQL file
     * @return bool
     * @throws PDOException
     */
    public function executeSqlFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("SQL file not found: {$filePath}");
        }

        $sql = file_get_contents($filePath);

        try {
            $this->connection->exec($sql);
            return true;
        } catch (PDOException $e) {
            throw new PDOException('Failed to execute SQL file: ' . $e->getMessage());
        }
    }

    /**
     * Begin transaction
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     *
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    /**
     * Get last insert ID
     *
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Prevent cloning of singleton instance
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton instance
     */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
