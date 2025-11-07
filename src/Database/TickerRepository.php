<?php

namespace SimpleTrader\Database;

use PDO;
use PDOException;

/**
 * Ticker Repository
 *
 * Handles all database operations for tickers
 */
class TickerRepository
{
    private PDO $db;

    /**
     * Constructor
     *
     * @param Database $database Database instance
     */
    public function __construct(Database $database)
    {
        $this->db = $database->getConnection();
    }

    /**
     * Get all tickers
     *
     * @param bool|null $enabledOnly Filter by enabled status (null = all, true = enabled only, false = disabled only)
     * @return array
     */
    public function getAllTickers(?bool $enabledOnly = null): array
    {
        $sql = 'SELECT t.*,
                       (SELECT MAX(q.date) FROM quotes q WHERE q.ticker_id = t.id) as latest_quote_date
                FROM tickers t';

        if ($enabledOnly !== null) {
            $sql .= ' WHERE t.enabled = :enabled';
        }

        $sql .= ' ORDER BY t.symbol ASC';

        $stmt = $this->db->prepare($sql);

        if ($enabledOnly !== null) {
            $stmt->bindValue(':enabled', $enabledOnly ? 1 : 0, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get enabled tickers only (for investor.php integration)
     *
     * @return array Formatted as ['SYMBOL' => ['path' => '...', 'exchange' => '...']]
     */
    public function getEnabledTickers(): array
    {
        $tickers = $this->getAllTickers(true);
        $result = [];

        foreach ($tickers as $ticker) {
            $result[$ticker['symbol']] = [
                'path' => $ticker['csv_path'],
                'exchange' => $ticker['exchange']
            ];
        }

        return $result;
    }

    /**
     * Get a single ticker by ID
     *
     * @param int $id Ticker ID
     * @return array|null
     */
    public function getTicker(int $id): ?array
    {
        $sql = 'SELECT * FROM tickers WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Get a ticker by symbol
     *
     * @param string $symbol Ticker symbol
     * @return array|null
     */
    public function getTickerBySymbol(string $symbol): ?array
    {
        $sql = 'SELECT * FROM tickers WHERE symbol = :symbol';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':symbol', strtoupper($symbol), PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Create a new ticker
     *
     * @param array $data ['symbol' => '...', 'exchange' => '...', 'source' => '...', 'enabled' => true/false]
     * @return int|false Last insert ID or false on failure
     * @throws PDOException
     */
    public function createTicker(array $data): int|false
    {
        // Validate required fields
        $requiredFields = ['symbol', 'exchange', 'source'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Normalize symbol to uppercase
        $data['symbol'] = strtoupper($data['symbol']);

        // Check if ticker already exists
        if ($this->getTickerBySymbol($data['symbol']) !== null) {
            throw new \RuntimeException("Ticker with symbol '{$data['symbol']}' already exists");
        }

        // Auto-generate CSV path based on symbol
        $data['csv_path'] = $data['csv_path'] ?? '/var/www/' . $data['symbol'] . '.csv';

        $sql = 'INSERT INTO tickers (symbol, exchange, source, csv_path, enabled, created_at, updated_at)
                VALUES (:symbol, :exchange, :source, :csv_path, :enabled, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':symbol', $data['symbol'], PDO::PARAM_STR);
        $stmt->bindValue(':exchange', $data['exchange'], PDO::PARAM_STR);
        $stmt->bindValue(':source', $data['source'], PDO::PARAM_STR);
        $stmt->bindValue(':csv_path', $data['csv_path'], PDO::PARAM_STR);
        $stmt->bindValue(':enabled', $data['enabled'] ?? true, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $tickerId = (int)$this->db->lastInsertId();

            // Log audit entry
            $this->logAudit($tickerId, 'created', null, json_encode($data));

            return $tickerId;
        }

        return false;
    }

    /**
     * Update an existing ticker
     *
     * @param int $id Ticker ID
     * @param array $data Fields to update
     * @return bool
     * @throws PDOException
     */
    public function updateTicker(int $id, array $data): bool
    {
        $ticker = $this->getTicker($id);
        if ($ticker === null) {
            throw new \RuntimeException("Ticker with ID {$id} not found");
        }

        // Build dynamic update query
        $allowedFields = ['symbol', 'exchange', 'source', 'csv_path', 'enabled'];
        $updateFields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'symbol') {
                    $value = strtoupper($value);

                    // Check if new symbol already exists (except current ticker)
                    $existing = $this->getTickerBySymbol($value);
                    if ($existing !== null && $existing['id'] !== $id) {
                        throw new \RuntimeException("Ticker with symbol '{$value}' already exists");
                    }
                }

                $updateFields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
        }

        if (empty($updateFields)) {
            return true; // Nothing to update
        }

        $sql = 'UPDATE tickers SET ' . implode(', ', $updateFields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }

        $result = $stmt->execute();

        if ($result) {
            // Log audit entry
            $this->logAudit($id, 'updated', json_encode($ticker), json_encode($data));
        }

        return $result;
    }

    /**
     * Delete a ticker
     *
     * @param int $id Ticker ID
     * @return bool
     * @throws PDOException
     */
    public function deleteTicker(int $id): bool
    {
        $ticker = $this->getTicker($id);
        if ($ticker === null) {
            throw new \RuntimeException("Ticker with ID {$id} not found");
        }

        // Note: We don't log the deletion in audit_log because CASCADE DELETE
        // will remove all audit entries for this ticker anyway.
        // The entire ticker and its history is being permanently removed.

        $sql = 'DELETE FROM tickers WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Toggle ticker enabled status
     *
     * @param int $id Ticker ID
     * @return bool New enabled status
     * @throws PDOException
     */
    public function toggleEnabled(int $id): bool
    {
        $ticker = $this->getTicker($id);
        if ($ticker === null) {
            throw new \RuntimeException("Ticker with ID {$id} not found");
        }

        $newStatus = !$ticker['enabled'];
        $action = $newStatus ? 'enabled' : 'disabled';

        $sql = 'UPDATE tickers SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':enabled', $newStatus ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $result = $stmt->execute();

        if ($result) {
            // Log audit entry
            $this->logAudit($id, $action, json_encode(['enabled' => $ticker['enabled']]), json_encode(['enabled' => $newStatus]));
        }

        return $newStatus;
    }

    /**
     * Get ticker count statistics
     *
     * @return array ['total' => int, 'enabled' => int, 'disabled' => int]
     */
    public function getStatistics(): array
    {
        $sql = 'SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled,
                    SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) as disabled
                FROM tickers';

        $stmt = $this->db->query($sql);
        $result = $stmt->fetch();

        return [
            'total' => (int)$result['total'],
            'enabled' => (int)$result['enabled'],
            'disabled' => (int)$result['disabled']
        ];
    }

    /**
     * Get audit log for a ticker
     *
     * @param int $tickerId Ticker ID
     * @param int $limit Maximum number of records to return
     * @return array
     */
    public function getAuditLog(int $tickerId, int $limit = 50): array
    {
        $sql = 'SELECT * FROM ticker_audit_log
                WHERE ticker_id = :ticker_id
                ORDER BY timestamp DESC
                LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Log an audit entry
     *
     * @param int $tickerId Ticker ID
     * @param string $action Action performed
     * @param string|null $oldValues JSON of old values
     * @param string|null $newValues JSON of new values
     * @return void
     */
    private function logAudit(int $tickerId, string $action, ?string $oldValues, ?string $newValues): void
    {
        $sql = 'INSERT INTO ticker_audit_log (ticker_id, action, old_values, new_values, timestamp)
                VALUES (:ticker_id, :action, :old_values, :new_values, CURRENT_TIMESTAMP)';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':old_values', $oldValues, PDO::PARAM_STR);
        $stmt->bindValue(':new_values', $newValues, PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * Check if a ticker CSV file exists
     *
     * @param string $csvPath Path to CSV file
     * @return bool
     */
    public function csvFileExists(string $csvPath): bool
    {
        return file_exists($csvPath);
    }

    /**
     * Validate ticker data before creating/updating
     *
     * @param array $data Ticker data
     * @param bool $isUpdate Whether this is an update operation
     * @return array Validation errors (empty if valid)
     */
    public function validateTickerData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Validate symbol
        if (!$isUpdate && (!isset($data['symbol']) || empty($data['symbol']))) {
            $errors['symbol'] = 'Symbol is required';
        } elseif (isset($data['symbol'])) {
            if (strlen($data['symbol']) > 10) {
                $errors['symbol'] = 'Symbol must be 10 characters or less';
            }
            if (!preg_match('/^[A-Z0-9]+$/i', $data['symbol'])) {
                $errors['symbol'] = 'Symbol must contain only letters and numbers';
            }
        }

        // Validate exchange
        if (!$isUpdate && (!isset($data['exchange']) || empty($data['exchange']))) {
            $errors['exchange'] = 'Exchange is required';
        } elseif (isset($data['exchange']) && strlen($data['exchange']) > 10) {
            $errors['exchange'] = 'Exchange must be 10 characters or less';
        }

        // Validate source
        if (!$isUpdate && (!isset($data['source']) || empty($data['source']))) {
            $errors['source'] = 'Data source is required';
        } elseif (isset($data['source'])) {
            // Validate that source is a valid source class
            if (!\SimpleTrader\Helpers\SourceDiscovery::isValidSource($data['source'])) {
                $errors['source'] = 'Invalid data source selected';
            }
        }

        // Validate CSV path (optional - will be auto-generated if not provided)
        if (isset($data['csv_path'])) {
            // Check for path traversal attempts
            if (strpos($data['csv_path'], '..') !== false) {
                $errors['csv_path'] = 'Invalid path: path traversal not allowed';
            }
        }

        return $errors;
    }
}
