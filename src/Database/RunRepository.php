<?php

namespace SimpleTrader\Database;

use PDO;

/**
 * Run Repository
 *
 * Handles all database operations for backtest runs
 */
class RunRepository
{
    private PDO $db;

    public function __construct(Database $database)
    {
        $this->db = $database->getConnection();
    }

    /**
     * Get all runs ordered by created date
     *
     * @param string|null $status Filter by status
     * @param int|null $limit Limit results
     * @return array
     */
    public function getAllRuns(?string $status = null, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM runs WHERE 1=1';

        if ($status !== null) {
            $sql .= ' AND status = :status';
        }

        $sql .= ' ORDER BY created_at DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->db->prepare($sql);

        if ($status !== null) {
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        }

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get a single run by ID
     *
     * @param int $id
     * @return array|null
     */
    public function getRun(int $id): ?array
    {
        $sql = 'SELECT * FROM runs WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a new run
     *
     * @param array $data Run data
     * @return int|false Run ID or false on failure
     */
    public function createRun(array $data): int|false
    {
        $sql = 'INSERT INTO runs (
                    name, strategy_class, strategy_parameters, tickers,
                    benchmark_ticker_id, start_date, end_date, initial_capital,
                    is_optimization, optimization_params, status
                ) VALUES (
                    :name, :strategy_class, :strategy_parameters, :tickers,
                    :benchmark_ticker_id, :start_date, :end_date, :initial_capital,
                    :is_optimization, :optimization_params, :status
                )';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':strategy_class', $data['strategy_class'], PDO::PARAM_STR);
        $stmt->bindValue(':strategy_parameters', $data['strategy_parameters'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':tickers', $data['tickers'], PDO::PARAM_STR);
        $stmt->bindValue(':benchmark_ticker_id', $data['benchmark_ticker_id'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $data['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $data['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(':initial_capital', $data['initial_capital'] ?? 10000.00, PDO::PARAM_STR);
        $stmt->bindValue(':is_optimization', $data['is_optimization'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':optimization_params', $data['optimization_params'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'] ?? 'pending', PDO::PARAM_STR);

        if ($stmt->execute()) {
            return (int)$this->db->lastInsertId();
        }

        return false;
    }

    /**
     * Update run status
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool
    {
        $sql = 'UPDATE runs SET status = :status';

        if ($status === 'running') {
            $sql .= ', started_at = CURRENT_TIMESTAMP';
        } elseif ($status === 'completed' || $status === 'failed') {
            $sql .= ', completed_at = CURRENT_TIMESTAMP';
        }

        $sql .= ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Update run with results
     *
     * @param int $id
     * @param array $results
     * @return bool
     */
    public function updateResults(int $id, array $results): bool
    {
        $sql = 'UPDATE runs SET
                    report_html = :report_html,
                    log_output = :log_output,
                    result_metrics = :result_metrics,
                    execution_time_seconds = :execution_time,
                    status = :status,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':report_html', $results['report_html'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':log_output', $results['log_output'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':result_metrics', $results['result_metrics'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':execution_time', $results['execution_time'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':status', $results['status'] ?? 'completed', PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Update run error
     *
     * @param int $id
     * @param string $errorMessage
     * @return bool
     */
    public function updateError(int $id, string $errorMessage): bool
    {
        $sql = 'UPDATE runs SET
                    error_message = :error_message,
                    status = :status,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':error_message', $errorMessage, PDO::PARAM_STR);
        $stmt->bindValue(':status', 'failed', PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Append to run log
     *
     * @param int $id
     * @param string $logText
     * @return bool
     */
    public function appendLog(int $id, string $logText): bool
    {
        // Get current log
        $run = $this->getRun($id);
        if (!$run) {
            return false;
        }

        $currentLog = $run['log_output'] ?? '';
        $newLog = $currentLog . $logText;

        $sql = 'UPDATE runs SET log_output = :log_output WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':log_output', $newLog, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Delete a run
     *
     * @param int $id
     * @return bool
     */
    public function deleteRun(int $id): bool
    {
        $sql = 'DELETE FROM runs WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get run statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $sql = 'SELECT
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_runs,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_runs,
                    SUM(CASE WHEN status = "running" THEN 1 ELSE 0 END) as running_runs,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_runs
                FROM runs';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch();

        return [
            'total' => (int)($result['total_runs'] ?? 0),
            'completed' => (int)($result['completed_runs'] ?? 0),
            'failed' => (int)($result['failed_runs'] ?? 0),
            'running' => (int)($result['running_runs'] ?? 0),
            'pending' => (int)($result['pending_runs'] ?? 0)
        ];
    }
}
