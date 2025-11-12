<?php

namespace SimpleTrader\Database;

use PDO;
use PDOException;

/**
 * Monitor Repository
 *
 * Handles all database operations for strategy monitors
 */
class MonitorRepository
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
     * Get all monitors
     *
     * @return array
     */
    public function getAllMonitors(): array
    {
        $sql = 'SELECT * FROM monitors ORDER BY created_at DESC';
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get monitor by ID
     *
     * @param int $id Monitor ID
     * @return array|null
     */
    public function getMonitor(int $id): ?array
    {
        $sql = 'SELECT * FROM monitors WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a new monitor
     *
     * @param array $data Monitor data
     * @return int Monitor ID
     */
    public function createMonitor(array $data): int
    {
        $sql = 'INSERT INTO monitors (
                    name, strategy_class, tickers, strategy_parameters,
                    start_date, initial_capital, status, created_at, updated_at
                ) VALUES (
                    :name, :strategy_class, :tickers, :strategy_parameters,
                    :start_date, :initial_capital, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':strategy_class', $data['strategy_class'], PDO::PARAM_STR);
        $stmt->bindValue(':tickers', $data['tickers'], PDO::PARAM_STR);
        $stmt->bindValue(':strategy_parameters', $data['strategy_parameters'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':start_date', $data['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(':initial_capital', $data['initial_capital'], PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'] ?? 'initializing', PDO::PARAM_STR);

        $stmt->execute();
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update monitor status
     *
     * @param int $id Monitor ID
     * @param string $status New status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool
    {
        $sql = 'UPDATE monitors SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        return $stmt->execute();
    }

    /**
     * Update monitor after backtest completion
     *
     * @param int $id Monitor ID
     * @param string $lastProcessedDate Last processed date
     * @return bool
     */
    public function markBacktestCompleted(int $id, string $lastProcessedDate): bool
    {
        $sql = 'UPDATE monitors SET
                    status = :status,
                    backtest_completed_at = CURRENT_TIMESTAMP,
                    last_processed_date = :last_processed_date,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'active', PDO::PARAM_STR);
        $stmt->bindValue(':last_processed_date', $lastProcessedDate, PDO::PARAM_STR);
        return $stmt->execute();
    }

    /**
     * Update last processed date
     *
     * @param int $id Monitor ID
     * @param string $date Date
     * @return bool
     */
    public function updateLastProcessedDate(int $id, string $date): bool
    {
        $sql = 'UPDATE monitors SET last_processed_date = :date, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':date', $date, PDO::PARAM_STR);
        return $stmt->execute();
    }

    /**
     * Delete a monitor
     *
     * @param int $id Monitor ID
     * @return bool
     */
    public function deleteMonitor(int $id): bool
    {
        // Delete related data first
        $this->db->exec("DELETE FROM monitor_daily_snapshots WHERE monitor_id = {$id}");
        $this->db->exec("DELETE FROM monitor_trades WHERE monitor_id = {$id}");
        $this->db->exec("DELETE FROM monitor_metrics WHERE monitor_id = {$id}");

        // Delete monitor
        $sql = 'DELETE FROM monitors WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Save daily snapshot
     *
     * @param int $monitorId Monitor ID
     * @param array $snapshot Snapshot data
     * @return bool
     */
    public function saveDailySnapshot(int $monitorId, array $snapshot): bool
    {
        $sql = 'INSERT OR REPLACE INTO monitor_daily_snapshots (
                    monitor_id, date, equity, cash, positions_value, positions,
                    strategy_state, daily_return, cumulative_return, created_at
                ) VALUES (
                    :monitor_id, :date, :equity, :cash, :positions_value, :positions,
                    :strategy_state, :daily_return, :cumulative_return, CURRENT_TIMESTAMP
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':date', $snapshot['date'], PDO::PARAM_STR);
        $stmt->bindValue(':equity', $snapshot['equity'], PDO::PARAM_STR);
        $stmt->bindValue(':cash', $snapshot['cash'], PDO::PARAM_STR);
        $stmt->bindValue(':positions_value', $snapshot['positions_value'], PDO::PARAM_STR);
        $stmt->bindValue(':positions', $snapshot['positions'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':strategy_state', $snapshot['strategy_state'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':daily_return', $snapshot['daily_return'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':cumulative_return', $snapshot['cumulative_return'] ?? null, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Get daily snapshots for a monitor
     *
     * @param int $monitorId Monitor ID
     * @param int|null $limit Limit results
     * @return array
     */
    public function getDailySnapshots(int $monitorId, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM monitor_daily_snapshots WHERE monitor_id = :monitor_id ORDER BY date DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get latest snapshot for a monitor
     *
     * @param int $monitorId Monitor ID
     * @return array|null
     */
    public function getLatestSnapshot(int $monitorId): ?array
    {
        $sql = 'SELECT * FROM monitor_daily_snapshots WHERE monitor_id = :monitor_id ORDER BY date DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Save trade
     *
     * @param int $monitorId Monitor ID
     * @param array $trade Trade data
     * @return int Trade ID
     */
    public function saveTrade(int $monitorId, array $trade): int
    {
        $sql = 'INSERT INTO monitor_trades (
                    monitor_id, date, ticker_id, ticker_symbol, action,
                    quantity, price, total_value, commission, notes, created_at
                ) VALUES (
                    :monitor_id, :date, :ticker_id, :ticker_symbol, :action,
                    :quantity, :price, :total_value, :commission, :notes, CURRENT_TIMESTAMP
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':date', $trade['date'], PDO::PARAM_STR);
        $stmt->bindValue(':ticker_id', $trade['ticker_id'], PDO::PARAM_INT);
        $stmt->bindValue(':ticker_symbol', $trade['ticker_symbol'], PDO::PARAM_STR);
        $stmt->bindValue(':action', $trade['action'], PDO::PARAM_STR);
        $stmt->bindValue(':quantity', $trade['quantity'], PDO::PARAM_STR);
        $stmt->bindValue(':price', $trade['price'], PDO::PARAM_STR);
        $stmt->bindValue(':total_value', $trade['total_value'], PDO::PARAM_STR);
        $stmt->bindValue(':commission', $trade['commission'] ?? 0, PDO::PARAM_STR);
        $stmt->bindValue(':notes', $trade['notes'] ?? null, PDO::PARAM_STR);

        $stmt->execute();
        return (int)$this->db->lastInsertId();
    }

    /**
     * Get trades for a monitor
     *
     * @param int $monitorId Monitor ID
     * @param int|null $limit Limit results
     * @return array
     */
    public function getTrades(int $monitorId, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM monitor_trades WHERE monitor_id = :monitor_id ORDER BY date DESC, id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Save or update metrics
     *
     * @param int $monitorId Monitor ID
     * @param string $metricType 'backtest' or 'forward'
     * @param array $metrics Metrics data
     * @return bool
     */
    public function saveMetrics(int $monitorId, string $metricType, array $metrics): bool
    {
        $sql = 'INSERT OR REPLACE INTO monitor_metrics (
                    monitor_id, metric_type, period_start, period_end,
                    total_return, annualized_return, sharpe_ratio, max_drawdown,
                    win_rate, total_trades, winning_trades, losing_trades,
                    avg_win, avg_loss, profit_factor, final_equity, updated_at
                ) VALUES (
                    :monitor_id, :metric_type, :period_start, :period_end,
                    :total_return, :annualized_return, :sharpe_ratio, :max_drawdown,
                    :win_rate, :total_trades, :winning_trades, :losing_trades,
                    :avg_win, :avg_loss, :profit_factor, :final_equity, CURRENT_TIMESTAMP
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':metric_type', $metricType, PDO::PARAM_STR);
        $stmt->bindValue(':period_start', $metrics['period_start'], PDO::PARAM_STR);
        $stmt->bindValue(':period_end', $metrics['period_end'], PDO::PARAM_STR);
        $stmt->bindValue(':total_return', $metrics['total_return'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':annualized_return', $metrics['annualized_return'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':sharpe_ratio', $metrics['sharpe_ratio'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':max_drawdown', $metrics['max_drawdown'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':win_rate', $metrics['win_rate'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':total_trades', $metrics['total_trades'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':winning_trades', $metrics['winning_trades'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':losing_trades', $metrics['losing_trades'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':avg_win', $metrics['avg_win'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':avg_loss', $metrics['avg_loss'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':profit_factor', $metrics['profit_factor'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':final_equity', $metrics['final_equity'] ?? null, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Get metrics for a monitor
     *
     * @param int $monitorId Monitor ID
     * @param string|null $metricType 'backtest', 'forward', or null for all
     * @return array
     */
    public function getMetrics(int $monitorId, ?string $metricType = null): array
    {
        $sql = 'SELECT * FROM monitor_metrics WHERE monitor_id = :monitor_id';

        if ($metricType !== null) {
            $sql .= ' AND metric_type = :metric_type';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);

        if ($metricType !== null) {
            $stmt->bindValue(':metric_type', $metricType, PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get statistics for all monitors
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $sql = 'SELECT
                    COUNT(*) as total_monitors,
                    SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_monitors,
                    SUM(CASE WHEN status = "stopped" THEN 1 ELSE 0 END) as stopped_monitors,
                    SUM(CASE WHEN status = "initializing" THEN 1 ELSE 0 END) as initializing_monitors
                FROM monitors';

        $stmt = $this->db->query($sql);
        return $stmt->fetch();
    }
}
