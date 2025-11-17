<?php

/**
 * Get Backtest Results Command
 *
 * Retrieves backtest results from the database for analysis.
 * Essential for Claude to analyze performance and make optimization decisions.
 *
 * Usage:
 *   php commands/get-backtest-results.php --id=<backtest-id> [--format=json]
 *   php commands/get-backtest-results.php --strategy=<name> --last=5 [--format=json]
 *   php commands/get-backtest-results.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\BacktestRepository;
use SimpleTrader\Database\TickerRepository;

// Parse command line options
$options = getopt('h', ['help', 'id:', 'strategy:', 'last:', 'format:', 'summary-only', 'compare']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Get Backtest Results Command
=============================

Retrieves and displays backtest results for analysis and decision making.

USAGE:
  php commands/get-backtest-results.php --id=<backtest-id> [options]
  php commands/get-backtest-results.php --strategy=<name> --last=N [options]
  php commands/get-backtest-results.php --last=N [options]
  php commands/get-backtest-results.php --help

OPTIONS:
  --id=ID              Get results for specific backtest ID
  --strategy=NAME      Filter by strategy class name
  --last=N             Get last N backtests (default: 1)
  --format=FORMAT      Output format: 'human' (default) or 'json'
  --summary-only       Show only key performance metrics (no logs/HTML)
  --compare            Compare multiple results side-by-side
  -h, --help           Show this help message

KEY PERFORMANCE METRICS:
  - Net Profit: Total profit/loss
  - Return %: Percentage return on initial capital
  - Total Trades: Number of completed trades
  - Win Rate: Percentage of profitable trades
  - Profit Factor: Gross profit / Gross loss
  - Max Drawdown %: Maximum peak-to-trough decline
  - Sharpe Ratio: Risk-adjusted return metric
  - Average Trade: Mean profit per trade

JSON FORMAT:
  Returns structured data for automated analysis:
  {
    "success": true,
    "backtests": [{
      "id": 1,
      "strategy": "TestStrategy",
      "parameters": {...},
      "metrics": {...},
      "status": "completed"
    }]
  }

EXAMPLES:

  1. Get specific backtest results:
     php commands/get-backtest-results.php --id=5

  2. Get last 3 backtests for a strategy:
     php commands/get-backtest-results.php --strategy=TestStrategy --last=3

  3. JSON output for analysis:
     php commands/get-backtest-results.php --id=5 --format=json --summary-only

  4. Compare last 5 backtests:
     php commands/get-backtest-results.php --last=5 --compare --summary-only

EXIT CODES:
  0  Success
  1  Error - backtest not found or invalid parameters


HELP;
    exit(0);
}

try {
    $format = $options['format'] ?? 'human';
    $summaryOnly = isset($options['summary-only']);
    $compare = isset($options['compare']);

    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize database
    $backtestsDb = Database::getInstance($config['database']['backtests']);
    $tickersDb = Database::getInstance($config['database']['tickers']);

    // Initialize repositories
    $backtestRepository = new BacktestRepository($backtestsDb);
    $tickerRepository = new TickerRepository($tickersDb);

    // Fetch backtests based on options
    $backtests = [];

    if (isset($options['id'])) {
        $backtest = $backtestRepository->getBacktest((int)$options['id']);
        if (!$backtest) {
            throw new \RuntimeException("Backtest ID {$options['id']} not found");
        }
        $backtests = [$backtest];
    } elseif (isset($options['strategy'])) {
        $limit = isset($options['last']) ? (int)$options['last'] : 1;
        $backtests = $backtestRepository->getBacktestsByStrategy($options['strategy'], $limit);
        if (empty($backtests)) {
            throw new \RuntimeException("No backtests found for strategy '{$options['strategy']}'");
        }
    } elseif (isset($options['last'])) {
        $limit = (int)$options['last'];
        $backtests = $backtestRepository->getAllBacktests(null, $limit);
        if (empty($backtests)) {
            throw new \RuntimeException("No backtests found");
        }
    } else {
        echo "Error: Please specify --id, --strategy, or --last\n";
        echo "Use --help for usage information.\n";
        exit(1);
    }

    // Process backtest results
    $results = [];
    foreach ($backtests as $backtest) {
        $result = [
            'id' => $backtest['id'],
            'name' => $backtest['name'],
            'strategy' => $backtest['strategy_class'],
            'parameters' => json_decode($backtest['strategy_parameters'], true) ?? [],
            'tickers' => json_decode($backtest['tickers'], true) ?? [],
            'start_date' => $backtest['start_date'],
            'end_date' => $backtest['end_date'],
            'initial_capital' => (float)$backtest['initial_capital'],
            'status' => $backtest['status'],
            'created_at' => $backtest['created_at'],
            'execution_time' => $backtest['execution_time_seconds'] ?? null,
            'error_message' => $backtest['error_message'] ?? null
        ];

        // Parse metrics if available
        $metrics = [];
        if ($backtest['result_metrics']) {
            $rawMetrics = json_decode($backtest['result_metrics'], true);
            if ($rawMetrics) {
                // Extract key metrics for easy analysis
                $metrics = extractKeyMetrics($rawMetrics);
            }
        }
        $result['metrics'] = $metrics;

        // Include full logs if not summary-only
        if (!$summaryOnly) {
            $result['log_output'] = $backtest['log_output'] ?? null;
            // Don't include full HTML in JSON, it's too large
            $result['has_report'] = !empty($backtest['report_html']);
        }

        // Get ticker symbols
        $tickerSymbols = [];
        foreach ($result['tickers'] as $tickerId) {
            $ticker = $tickerRepository->getTicker($tickerId);
            if ($ticker) {
                $tickerSymbols[$tickerId] = $ticker['symbol'];
            }
        }
        $result['ticker_symbols'] = $tickerSymbols;

        $results[] = $result;
    }

    // Output based on format
    if ($format === 'json') {
        $output = [
            'success' => true,
            'backtests' => $results,
            'count' => count($results)
        ];
        echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        // Human readable format
        if ($compare && count($results) > 1) {
            outputComparison($results);
        } else {
            foreach ($results as $result) {
                outputBacktestResult($result, $summaryOnly);
            }
        }
    }

    exit(0);

} catch (\Exception $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
}

/**
 * Extract key metrics from raw result metrics
 */
function extractKeyMetrics(array $rawMetrics): array
{
    $metrics = [];

    // Direct mappings
    $keyMetrics = [
        'net_profit' => ['netProfit', 'net_profit'],
        'return_percent' => ['returnPercent', 'return_percent', 'returnPct'],
        'total_trades' => ['totalTrades', 'total_trades'],
        'winning_trades' => ['winningTrades', 'winning_trades'],
        'losing_trades' => ['losingTrades', 'losing_trades'],
        'win_rate' => ['winRate', 'win_rate'],
        'profit_factor' => ['profitFactor', 'profit_factor'],
        'max_drawdown_percent' => ['maxDrawdownPercent', 'maxDrawdown', 'max_drawdown_percent'],
        'max_drawdown_value' => ['maxDrawdownValue', 'max_drawdown_value'],
        'sharpe_ratio' => ['sharpeRatio', 'sharpe_ratio'],
        'average_trade' => ['averageTrade', 'avg_trade', 'average_trade'],
        'average_win' => ['averageWin', 'avg_win', 'average_win'],
        'average_loss' => ['averageLoss', 'avg_loss', 'average_loss'],
        'largest_win' => ['largestWin', 'largest_win'],
        'largest_loss' => ['largestLoss', 'largest_loss'],
        'gross_profit' => ['grossProfit', 'gross_profit'],
        'gross_loss' => ['grossLoss', 'gross_loss'],
        'final_capital' => ['finalCapital', 'final_capital'],
        'avg_bars_in_trade' => ['avgBarsInTrade', 'avg_bars_in_trade'],
        'max_consecutive_wins' => ['maxConsecutiveWins', 'max_consecutive_wins'],
        'max_consecutive_losses' => ['maxConsecutiveLosses', 'max_consecutive_losses']
    ];

    foreach ($keyMetrics as $key => $possibleNames) {
        foreach ($possibleNames as $name) {
            if (isset($rawMetrics[$name])) {
                $metrics[$key] = $rawMetrics[$name];
                break;
            }
        }
    }

    // Calculate some metrics if not present
    if (!isset($metrics['win_rate']) && isset($metrics['winning_trades']) && isset($metrics['total_trades']) && $metrics['total_trades'] > 0) {
        $metrics['win_rate'] = ($metrics['winning_trades'] / $metrics['total_trades']) * 100;
    }

    if (!isset($metrics['profit_factor']) && isset($metrics['gross_profit']) && isset($metrics['gross_loss']) && abs($metrics['gross_loss']) > 0) {
        $metrics['profit_factor'] = abs($metrics['gross_profit'] / $metrics['gross_loss']);
    }

    return $metrics;
}

/**
 * Output a single backtest result in human-readable format
 */
function outputBacktestResult(array $result, bool $summaryOnly): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "BACKTEST #{$result['id']}: {$result['name']}\n";
    echo str_repeat('=', 70) . "\n";

    echo "Strategy: {$result['strategy']}\n";
    echo "Status: " . strtoupper($result['status']) . "\n";
    echo "Period: {$result['start_date']} to {$result['end_date']}\n";
    echo "Tickers: " . implode(', ', array_values($result['ticker_symbols'])) . "\n";
    echo "Initial Capital: $" . number_format($result['initial_capital'], 2) . "\n";

    if (!empty($result['parameters'])) {
        echo "\nParameters:\n";
        foreach ($result['parameters'] as $key => $value) {
            $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            echo "  {$key}: {$valueStr}\n";
        }
    }

    if (!empty($result['metrics'])) {
        echo "\n--- PERFORMANCE METRICS ---\n";
        $m = $result['metrics'];

        // Primary metrics
        if (isset($m['net_profit'])) {
            $sign = $m['net_profit'] >= 0 ? '+' : '';
            echo "Net Profit: {$sign}$" . number_format($m['net_profit'], 2) . "\n";
        }
        if (isset($m['return_percent'])) {
            $sign = $m['return_percent'] >= 0 ? '+' : '';
            echo "Return: {$sign}" . number_format($m['return_percent'], 2) . "%\n";
        }
        if (isset($m['final_capital'])) {
            echo "Final Capital: $" . number_format($m['final_capital'], 2) . "\n";
        }

        echo "\n--- TRADE STATISTICS ---\n";
        if (isset($m['total_trades'])) {
            echo "Total Trades: {$m['total_trades']}\n";
        }
        if (isset($m['winning_trades'])) {
            echo "Winning Trades: {$m['winning_trades']}\n";
        }
        if (isset($m['losing_trades'])) {
            echo "Losing Trades: {$m['losing_trades']}\n";
        }
        if (isset($m['win_rate'])) {
            echo "Win Rate: " . number_format($m['win_rate'], 1) . "%\n";
        }
        if (isset($m['average_trade'])) {
            $sign = $m['average_trade'] >= 0 ? '+' : '';
            echo "Average Trade: {$sign}$" . number_format($m['average_trade'], 2) . "\n";
        }

        echo "\n--- RISK METRICS ---\n";
        if (isset($m['profit_factor'])) {
            echo "Profit Factor: " . number_format($m['profit_factor'], 2) . "\n";
        }
        if (isset($m['sharpe_ratio'])) {
            echo "Sharpe Ratio: " . number_format($m['sharpe_ratio'], 2) . "\n";
        }
        if (isset($m['max_drawdown_percent'])) {
            echo "Max Drawdown: -" . number_format($m['max_drawdown_percent'], 2) . "%\n";
        }
        if (isset($m['max_drawdown_value'])) {
            echo "Max Drawdown Value: -$" . number_format($m['max_drawdown_value'], 2) . "\n";
        }

        if (isset($m['average_win']) || isset($m['average_loss'])) {
            echo "\n--- WIN/LOSS ANALYSIS ---\n";
            if (isset($m['average_win'])) {
                echo "Average Win: $" . number_format($m['average_win'], 2) . "\n";
            }
            if (isset($m['average_loss'])) {
                echo "Average Loss: -$" . number_format(abs($m['average_loss']), 2) . "\n";
            }
            if (isset($m['largest_win'])) {
                echo "Largest Win: $" . number_format($m['largest_win'], 2) . "\n";
            }
            if (isset($m['largest_loss'])) {
                echo "Largest Loss: -$" . number_format(abs($m['largest_loss']), 2) . "\n";
            }
        }
    } else {
        echo "\nNo metrics available (backtest may not be completed)\n";
    }

    if ($result['execution_time']) {
        echo "\nExecution Time: " . number_format($result['execution_time'], 2) . " seconds\n";
    }

    if ($result['error_message']) {
        echo "\n⚠ Error: {$result['error_message']}\n";
    }

    if (!$summaryOnly && $result['log_output'] ?? null) {
        echo "\n--- EXECUTION LOG (last 50 lines) ---\n";
        $lines = explode("\n", $result['log_output']);
        $lastLines = array_slice($lines, -50);
        echo implode("\n", $lastLines) . "\n";
    }

    echo "\n";
}

/**
 * Output comparison table for multiple backtests
 */
function outputComparison(array $results): void
{
    echo "\n" . str_repeat('=', 100) . "\n";
    echo "BACKTEST COMPARISON\n";
    echo str_repeat('=', 100) . "\n";

    // Build header
    $header = sprintf("%-25s", "Metric");
    foreach ($results as $result) {
        $header .= sprintf("%-15s", "#{$result['id']}");
    }
    echo $header . "\n";
    echo str_repeat('-', 100) . "\n";

    // Strategy
    $row = sprintf("%-25s", "Strategy");
    foreach ($results as $result) {
        $row .= sprintf("%-15s", substr($result['strategy'], 0, 14));
    }
    echo $row . "\n";

    // Parameters (show first param as example)
    if (!empty($results[0]['parameters'])) {
        $firstParam = array_key_first($results[0]['parameters']);
        $row = sprintf("%-25s", "Param: {$firstParam}");
        foreach ($results as $result) {
            $val = $result['parameters'][$firstParam] ?? 'N/A';
            $row .= sprintf("%-15s", $val);
        }
        echo $row . "\n";
    }

    echo str_repeat('-', 100) . "\n";

    // Key Metrics
    $metricsList = [
        'net_profit' => 'Net Profit',
        'return_percent' => 'Return %',
        'total_trades' => 'Total Trades',
        'win_rate' => 'Win Rate %',
        'profit_factor' => 'Profit Factor',
        'sharpe_ratio' => 'Sharpe Ratio',
        'max_drawdown_percent' => 'Max Drawdown %',
        'average_trade' => 'Avg Trade $'
    ];

    foreach ($metricsList as $key => $label) {
        $row = sprintf("%-25s", $label);
        foreach ($results as $result) {
            if (isset($result['metrics'][$key])) {
                $val = $result['metrics'][$key];
                if ($key === 'net_profit' || $key === 'average_trade') {
                    $formatted = '$' . number_format($val, 0);
                } elseif (strpos($key, 'percent') !== false || $key === 'win_rate') {
                    $formatted = number_format($val, 1) . '%';
                } else {
                    $formatted = number_format($val, 2);
                }
                $row .= sprintf("%-15s", $formatted);
            } else {
                $row .= sprintf("%-15s", "N/A");
            }
        }
        echo $row . "\n";
    }

    echo str_repeat('=', 100) . "\n";

    // Determine best performer
    $bestReturn = -PHP_FLOAT_MAX;
    $bestId = null;
    foreach ($results as $result) {
        $ret = $result['metrics']['return_percent'] ?? -PHP_FLOAT_MAX;
        if ($ret > $bestReturn) {
            $bestReturn = $ret;
            $bestId = $result['id'];
        }
    }

    if ($bestId) {
        echo "\nBest Performer: Backtest #{$bestId} with " . number_format($bestReturn, 2) . "% return\n";
    }

    echo "\n";
}
