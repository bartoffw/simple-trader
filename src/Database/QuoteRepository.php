<?php

namespace SimpleTrader\Database;

use PDO;
use PDOException;

/**
 * Quote Repository
 *
 * Handles all database operations for quotation data (OHLCV)
 */
class QuoteRepository
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
     * Get date range for a ticker (first and last quote dates)
     *
     * @param int $tickerId Ticker ID
     * @return array|null ['first_date' => '...', 'last_date' => '...', 'count' => int]
     */
    public function getDateRange(int $tickerId): ?array
    {
        $sql = 'SELECT
                    MIN(date) as first_date,
                    MAX(date) as last_date,
                    COUNT(*) as count
                FROM quotes
                WHERE ticker_id = :ticker_id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        if ($result && $result['first_date'] !== null) {
            return [
                'first_date' => $result['first_date'],
                'last_date' => $result['last_date'],
                'count' => (int)$result['count']
            ];
        }

        return null;
    }

    /**
     * Get latest quote date for a ticker
     *
     * @param int $tickerId Ticker ID
     * @return string|null Date string (Y-m-d) or null if no quotes
     */
    public function getLatestDate(int $tickerId): ?string
    {
        $sql = 'SELECT date FROM quotes
                WHERE ticker_id = :ticker_id
                ORDER BY date DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        return $result ? $result['date'] : null;
    }

    /**
     * Check if quotes exist for a ticker
     *
     * @param int $tickerId Ticker ID
     * @return bool
     */
    public function hasQuotes(int $tickerId): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM quotes WHERE ticker_id = :ticker_id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result && $result['count'] > 0;
    }

    /**
     * Insert or update a single quote
     *
     * @param int $tickerId Ticker ID
     * @param array $quote ['date' => '...', 'open' => float, 'high' => float, 'low' => float, 'close' => float, 'volume' => int|null]
     * @return bool
     */
    public function upsertQuote(int $tickerId, array $quote): bool
    {
        $sql = 'INSERT INTO quotes (ticker_id, date, open, high, low, close, volume, created_at)
                VALUES (:ticker_id, :date, :open, :high, :low, :close, :volume, CURRENT_TIMESTAMP)
                ON CONFLICT(ticker_id, date)
                DO UPDATE SET
                    open = excluded.open,
                    high = excluded.high,
                    low = excluded.low,
                    close = excluded.close,
                    volume = excluded.volume';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->bindValue(':date', $quote['date'], PDO::PARAM_STR);
        $stmt->bindValue(':open', $quote['open'], PDO::PARAM_STR);
        $stmt->bindValue(':high', $quote['high'], PDO::PARAM_STR);
        $stmt->bindValue(':low', $quote['low'], PDO::PARAM_STR);
        $stmt->bindValue(':close', $quote['close'], PDO::PARAM_STR);
        $stmt->bindValue(':volume', $quote['volume'] ?? null, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Batch insert/update quotes for a ticker (efficient for large datasets)
     *
     * @param int $tickerId Ticker ID
     * @param array $quotes Array of quote arrays
     * @return int Number of quotes inserted/updated
     */
    public function batchUpsertQuotes(int $tickerId, array $quotes): int
    {
        if (empty($quotes)) {
            return 0;
        }

        $this->db->beginTransaction();

        try {
            $sql = 'INSERT INTO quotes (ticker_id, date, open, high, low, close, volume, created_at)
                    VALUES (:ticker_id, :date, :open, :high, :low, :close, :volume, CURRENT_TIMESTAMP)
                    ON CONFLICT(ticker_id, date)
                    DO UPDATE SET
                        open = excluded.open,
                        high = excluded.high,
                        low = excluded.low,
                        close = excluded.close,
                        volume = excluded.volume';

            $stmt = $this->db->prepare($sql);

            $count = 0;
            foreach ($quotes as $quote) {
                $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
                $stmt->bindValue(':date', $quote['date'], PDO::PARAM_STR);
                $stmt->bindValue(':open', $quote['open'], PDO::PARAM_STR);
                $stmt->bindValue(':high', $quote['high'], PDO::PARAM_STR);
                $stmt->bindValue(':low', $quote['low'], PDO::PARAM_STR);
                $stmt->bindValue(':close', $quote['close'], PDO::PARAM_STR);
                $stmt->bindValue(':volume', $quote['volume'] ?? null, PDO::PARAM_INT);

                if ($stmt->execute()) {
                    $count++;
                }
            }

            $this->db->commit();
            return $count;

        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get quotes for a ticker within a date range
     *
     * @param int $tickerId Ticker ID
     * @param string|null $startDate Start date (Y-m-d) or null for all
     * @param string|null $endDate End date (Y-m-d) or null for all
     * @param int|null $limit Limit number of results
     * @return array
     */
    public function getQuotes(int $tickerId, ?string $startDate = null, ?string $endDate = null, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM quotes WHERE ticker_id = :ticker_id';

        if ($startDate !== null) {
            $sql .= ' AND date >= :start_date';
        }
        if ($endDate !== null) {
            $sql .= ' AND date <= :end_date';
        }

        $sql .= ' ORDER BY date DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);

        if ($startDate !== null) {
            $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        }
        if ($endDate !== null) {
            $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        }
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Delete all quotes for a ticker
     *
     * @param int $tickerId Ticker ID
     * @return bool
     */
    public function deleteQuotes(int $tickerId): bool
    {
        $sql = 'DELETE FROM quotes WHERE ticker_id = :ticker_id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get quote statistics for a ticker
     *
     * @param int $tickerId Ticker ID
     * @return array ['count' => int, 'avg_volume' => float, 'first_date' => string, 'last_date' => string]
     */
    public function getStatistics(int $tickerId): array
    {
        $sql = 'SELECT
                    COUNT(*) as count,
                    AVG(volume) as avg_volume,
                    MIN(date) as first_date,
                    MAX(date) as last_date,
                    MIN(low) as lowest_price,
                    MAX(high) as highest_price
                FROM quotes
                WHERE ticker_id = :ticker_id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':ticker_id', $tickerId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        return [
            'count' => (int)($result['count'] ?? 0),
            'avg_volume' => $result['avg_volume'] ? (float)$result['avg_volume'] : null,
            'first_date' => $result['first_date'] ?? null,
            'last_date' => $result['last_date'] ?? null,
            'lowest_price' => $result['lowest_price'] ? (float)$result['lowest_price'] : null,
            'highest_price' => $result['highest_price'] ? (float)$result['highest_price'] : null
        ];
    }
}
