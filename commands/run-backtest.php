#!/usr/bin/env php
<?php

/**
 * CLI Tool: Run Backtest
 *
 * Executes a backtest run either from database (by run-id) or directly with parameters.
 * Supports multiple output formats and optional database saving.
 *
 * Usage Mode 1 (from database):
 *   php commands/run-backtest.php --run-id=<id> [--format=human|json] [--no-save]
 *
 * Usage Mode 2 (direct parameters):
 *   php commands/run-backtest.php --strategy=<name> --tickers=<ids> --start-date=<date> --end-date=<date> [options]
 *
 * Options:
 *   --run-id=<id>              Load configuration from database run
 *   --strategy=<name>          Strategy class name (e.g., TestStrategy)
 *   --tickers=<ids>            Comma-separated ticker IDs (e.g., 1,2,3)
 *   --start-date=<date>        Start date (YYYY-MM-DD)
 *   --end-date=<date>          End date (YYYY-MM-DD)
 *   --name=<name>              Run name (optional)
 *   --initial-capital=<amount> Initial capital (default: 10000)
 *   --benchmark=<ticker-id>    Benchmark ticker ID (optional)
 *   --format=<format>          Output format: human|json (default: human)
 *   --no-save                  Skip saving to database
 *   --param:<name>=<value>     Strategy parameter (can be specified multiple times)
 *   --optimize                 Enable optimization mode
 *   --opt:<name>=<from>:<to>:<step>  Optimization parameter range
 *
 * Examples:
 *   # Run from database with JSON output
 *   php commands/run-backtest.php --run-id=1 --format=json
 *
 *   # Run directly without saving to database
 *   php commands/run-backtest.php --strategy=TestStrategy --tickers=1,2 --start-date=2023-01-01 --end-date=2023-12-31 --no-save
 *
 *   # Run with custom parameters
 *   php commands/run-backtest.php --strategy=TestStrategy --tickers=1 --start-date=2023-01-01 --end-date=2023-12-31 --param:threshold=0.05 --param:window=14
 *
 *   # Run optimization
 *   php commands/run-backtest.php --strategy=TestStrategy --tickers=1 --start-date=2023-01-01 --end-date=2023-12-31 --optimize --opt:threshold=0.01:0.1:0.01
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\Database\BacktestRepository;
use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Helpers\DatabaseAssetLoader;
use SimpleTrader\Helpers\OptimizationParam;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Loggers\ConsoleLogger;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Services\BacktestLogger;
use SimpleTrader\Services\EmbeddedReportGenerator;

// Parse command line arguments
$options = parseArguments($argv);

// Check for help flag or no arguments
if (isset($options['help']) || isset($options['h'])) {
    printHelp();
    exit(0);
}

// Check if no arguments provided at all
if (empty($options)) {
    printHelp();
    exit(0);
}

// Validate required options
if (!isset($options['run-id']) && !isset($options['strategy'])) {
    echo "Error: Missing required parameters.\n\n";
    printHelp();
    exit(1);
}

// Determine mode
$isRunIdMode = isset($options['run-id']);
$outputFormat = $options['format'] ?? 'human';
$skipSave = isset($options['no-save']);

// Validate format
if (!in_array($outputFormat, ['human', 'json'])) {
    echo "Error: Invalid format. Use 'human' or 'json'\n";
    exit(1);
}

try {
    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize database connections and repositories
    $tickersDb = Database::getInstance($config['database']['tickers']);
    $backtestsDb = Database::getInstance($config['database']['backtests']);

    $backtestRepository = new BacktestRepository($backtestsDb);
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);

    // Prepare run configuration
    if ($isRunIdMode) {
        // Mode 1: Load from database
        $runId = (int)$options['run-id'];
        $run = $backtestRepository->getBacktest($runId);

        if (!$run) {
            outputError("Run not found: {$runId}", $outputFormat);
            exit(1);
        }

        $runConfig = [
            'id' => $runId,
            'name' => $run['name'],
            'strategy_class' => $run['strategy_class'],
            'strategy_parameters' => json_decode($run['strategy_parameters'], true) ?? [],
            'tickers' => json_decode($run['tickers'], true),
            'benchmark_ticker_id' => $run['benchmark_ticker_id'],
            'start_date' => $run['start_date'],
            'end_date' => $run['end_date'],
            'initial_capital' => $run['initial_capital'],
            'is_optimization' => $run['is_optimization'],
            'optimization_params' => json_decode($run['optimization_params'], true) ?? []
        ];

        $saveToDb = !$skipSave;

    } else {
        // Mode 2: Direct parameters
        // Validate required parameters
        if (!isset($options['tickers']) || !isset($options['start-date']) || !isset($options['end-date'])) {
            echo "Error: Missing required parameters. Need --tickers, --start-date, and --end-date\n";
            printUsage();
            exit(1);
        }

        // Validate strategy
        if (!StrategyDiscovery::isValidStrategy($options['strategy'])) {
            outputError("Invalid strategy: {$options['strategy']}", $outputFormat);
            outputError("Available strategies: " . implode(', ', StrategyDiscovery::getAvailableStrategies()), $outputFormat);
            exit(1);
        }

        // Parse tickers
        $tickerIds = array_map('intval', explode(',', $options['tickers']));

        // Parse strategy parameters
        $strategyParams = [];
        foreach ($options as $key => $value) {
            if (strpos($key, 'param:') === 0) {
                $paramName = substr($key, 6);
                $strategyParams[$paramName] = parseValue($value);
            }
        }

        // Parse optimization parameters
        $optimizationParams = [];
        $isOptimization = isset($options['optimize']);
        if ($isOptimization) {
            foreach ($options as $key => $value) {
                if (strpos($key, 'opt:') === 0) {
                    $paramName = substr($key, 4);
                    $parts = explode(':', $value);
                    if (count($parts) === 3) {
                        $optimizationParams[] = [
                            'name' => $paramName,
                            'from' => (float)$parts[0],
                            'to' => (float)$parts[1],
                            'step' => (float)$parts[2]
                        ];
                    }
                }
            }
        }

        $runConfig = [
            'id' => null,
            'name' => $options['name'] ?? "Backtest " . date('Y-m-d H:i:s'),
            'strategy_class' => $options['strategy'],
            'strategy_parameters' => $strategyParams,
            'tickers' => $tickerIds,
            'benchmark_ticker_id' => isset($options['benchmark']) ? (int)$options['benchmark'] : null,
            'start_date' => $options['start-date'],
            'end_date' => $options['end-date'],
            'initial_capital' => isset($options['initial-capital']) ? (float)$options['initial-capital'] : 10000.00,
            'is_optimization' => $isOptimization,
            'optimization_params' => $optimizationParams
        ];

        // Save to database if not skipped
        $saveToDb = !$skipSave;
        if ($saveToDb) {
            $backtestId = $backtestRepository->createBacktest([
                'name' => $runConfig['name'],
                'strategy_class' => $runConfig['strategy_class'],
                'strategy_parameters' => json_encode($runConfig['strategy_parameters']),
                'tickers' => json_encode($runConfig['tickers']),
                'benchmark_ticker_id' => $runConfig['benchmark_ticker_id'],
                'start_date' => $runConfig['start_date'],
                'end_date' => $runConfig['end_date'],
                'initial_capital' => $runConfig['initial_capital'],
                'is_optimization' => $runConfig['is_optimization'],
                'optimization_params' => json_encode($runConfig['optimization_params']),
                'status' => 'pending'
            ]);
            $runConfig['id'] = $backtestId;
        }
    }

    // Update status if saving to database
    if ($saveToDb && $runConfig['id']) {
        $backtestRepository->updateStatus($runConfig['id'], 'running');
    }

    // Create logger based on output format and save mode
    if ($saveToDb && $runConfig['id']) {
        // Use database logger
        $logger = new BacktestLogger($backtestRepository, $runConfig['id']);
        $logger->setLevel(Level::Info);
    } else {
        // Use console logger for non-save mode
        $logger = new ConsoleLogger();
        $logger->setLevel($outputFormat === 'json' ? Level::Warning : Level::Info);
    }

    $startTime = microtime(true);

    // Output initial info
    if ($outputFormat === 'human') {
        echo "\n=== Starting Backtest Run ===\n";
        echo "Strategy: {$runConfig['strategy_class']}\n";
        echo "Period: {$runConfig['start_date']} to {$runConfig['end_date']}\n";
        echo "Initial Capital: $" . number_format($runConfig['initial_capital'], 2) . "\n";
        if ($saveToDb && $runConfig['id']) {
            echo "Run ID: {$runConfig['id']}\n";
        }
        echo "\n";
    }

    // Load assets from database
    $assetLoader = new DatabaseAssetLoader($quoteRepository, $tickerRepository);
    $assets = $assetLoader->loadAssets($runConfig['tickers'], $runConfig['start_date'], $runConfig['end_date']);

    if ($assets->isEmpty()) {
        throw new Exception('No asset data loaded. Check if quotes exist for selected tickers in the specified date range.');
    }

    if ($outputFormat === 'human') {
        echo "Loaded tickers: " . implode(', ', $assets->getTickers()) . "\n";
    }

    // Create strategy instance
    $strategyClass = StrategyDiscovery::getStrategyClassName($runConfig['strategy_class']);
    $strategy = new $strategyClass(paramsOverrides: $runConfig['strategy_parameters']);
    $strategy->setCapital($runConfig['initial_capital']);
    $strategy->setLogger($logger);
    $strategy->setTickers($assets->getTickers());

    // Create backtester
    $backtest = new Backtester(Resolution::Daily);
    $backtest->setLogger($logger);
    $backtest->setStrategy($strategy);

    // Set benchmark if specified
    if ($runConfig['benchmark_ticker_id']) {
        $benchmarkSymbol = $assetLoader->getTickerSymbol($runConfig['benchmark_ticker_id']);
        if ($benchmarkSymbol && $assets->hasAsset($benchmarkSymbol)) {
            $benchmarkAsset = $assets->getAsset($benchmarkSymbol);
            $backtest->setBenchmark($benchmarkAsset, $benchmarkSymbol);
            if ($outputFormat === 'human') {
                echo "Benchmark: {$benchmarkSymbol}\n";
            }
        }
    }

    // Prepare optimization params if needed
    $optimizationParamsObjects = [];
    if ($runConfig['is_optimization'] && !empty($runConfig['optimization_params'])) {
        foreach ($runConfig['optimization_params'] as $param) {
            $optimizationParamsObjects[] = new OptimizationParam(
                $param['name'],
                $param['from'],
                $param['to'],
                $param['step']
            );
        }
        if ($outputFormat === 'human') {
            echo "Optimization enabled with " . count($optimizationParamsObjects) . " parameter(s)\n";
        }
    }

    // Run backtest
    if ($outputFormat === 'human') {
        echo "\nRunning backtest...\n";
    }

    $backtest->runBacktest(
        $assets,
        new Carbon($runConfig['start_date']),
        new Carbon($runConfig['end_date']),
        empty($optimizationParamsObjects) ? null : $optimizationParamsObjects
    );

    $executionTime = microtime(true) - $startTime;

    if ($outputFormat === 'human') {
        echo "Backtest completed in " . number_format($executionTime, 2) . "s\n\n";
    }

    // Extract metrics
    $strategyInstance = $runConfig['is_optimization'] ? $backtest->getBestStrategy() : $backtest->getStrategy();
    if ($strategyInstance) {
        $tradeLog = $strategyInstance->getTradeLog();
        $tradeStats = $strategyInstance->getTradeStats($tradeLog);

        $metrics = [
            'net_profit' => $tradeStats['net_profit'] ?? 0,
            'net_profit_percent' => $tradeStats['net_profit_percent'] ?? 0,
            'total_transactions' => count($tradeLog),
            'profitable_transactions' => $tradeStats['profitable_transactions'] ?? 0,
            'losing_transactions' => $tradeStats['losing_transactions'] ?? 0,
            'profit_factor' => $tradeStats['profit_factor'] ?? 0,
            'max_drawdown_value' => $tradeStats['max_strategy_drawdown_value'] ?? 0,
            'max_drawdown_percent' => $tradeStats['max_strategy_drawdown_percent'] ?? 0,
            'win_rate' => $tradeStats['win_rate'] ?? 0,
            'average_win' => $tradeStats['average_win'] ?? 0,
            'average_loss' => $tradeStats['average_loss'] ?? 0
        ];
    } else {
        $metrics = [];
    }

    // Generate report if saving to database
    $reportHtml = null;
    if ($saveToDb && $runConfig['id']) {
        $reportGenerator = new EmbeddedReportGenerator();
        $reportHtml = $reportGenerator->generateReport($backtest, $assets->getTickers());

        // Save results
        $backtestRepository->updateResults($runConfig['id'], [
            'report_html' => $reportHtml,
            'result_metrics' => json_encode($metrics),
            'execution_time' => $executionTime,
            'status' => 'completed'
        ]);
    }

    // Output results based on format
    if ($outputFormat === 'json') {
        outputJson([
            'success' => true,
            'run_id' => $runConfig['id'],
            'execution_time' => $executionTime,
            'metrics' => $metrics,
            'configuration' => [
                'name' => $runConfig['name'],
                'strategy' => $runConfig['strategy_class'],
                'tickers' => $runConfig['tickers'],
                'start_date' => $runConfig['start_date'],
                'end_date' => $runConfig['end_date'],
                'initial_capital' => $runConfig['initial_capital'],
                'is_optimization' => $runConfig['is_optimization']
            ]
        ]);
    } else {
        // Human-readable output
        echo "=== Results ===\n";
        echo "Net Profit: $" . number_format($metrics['net_profit'], 2) . " (" . number_format($metrics['net_profit_percent'], 2) . "%)\n";
        echo "Total Transactions: " . $metrics['total_transactions'] . "\n";
        echo "Profitable: " . $metrics['profitable_transactions'] . " | Losing: " . $metrics['losing_transactions'] . "\n";
        echo "Win Rate: " . number_format($metrics['win_rate'], 2) . "%\n";
        echo "Profit Factor: " . number_format($metrics['profit_factor'], 2) . "\n";
        echo "Max Drawdown: $" . number_format($metrics['max_drawdown_value'], 2) . " (" . number_format($metrics['max_drawdown_percent'], 2) . "%)\n";
        echo "Average Win: $" . number_format($metrics['average_win'], 2) . "\n";
        echo "Average Loss: $" . number_format($metrics['average_loss'], 2) . "\n";
        echo "\n=== Backtest Completed Successfully ===\n";
        if ($saveToDb && $runConfig['id']) {
            echo "Run saved to database with ID: {$runConfig['id']}\n";
        }
        echo "\n";
    }

    exit(0);

} catch (\Exception $e) {
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();

    if (isset($saveToDb) && $saveToDb && isset($runConfig) && isset($runConfig['id']) && $runConfig['id']) {
        $backtestRepository->updateError($runConfig['id'], $errorMsg . "\n" . $errorTrace);
    }

    outputError($errorMsg, $outputFormat ?? 'human', $errorTrace);
    exit(1);
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Parse command line arguments into associative array
 */
function parseArguments(array $argv): array
{
    $options = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);

            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $options[$key] = $value;
            } else {
                $options[$arg] = true;
            }
        }
    }

    return $options;
}

/**
 * Parse value to appropriate type
 */
function parseValue(string $value): mixed
{
    // Boolean
    if (strtolower($value) === 'true') return true;
    if (strtolower($value) === 'false') return false;

    // Numeric
    if (is_numeric($value)) {
        return strpos($value, '.') !== false ? (float)$value : (int)$value;
    }

    // String
    return $value;
}

/**
 * Output error message based on format
 */
function outputError(string $message, string $format, ?string $trace = null): void
{
    if ($format === 'json') {
        outputJson([
            'success' => false,
            'error' => $message,
            'trace' => $trace
        ]);
    } else {
        echo "Error: {$message}\n";
        if ($trace) {
            echo "\nStack trace:\n{$trace}\n";
        }
    }
}

/**
 * Output JSON response
 */
function outputJson(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Print help and usage instructions
 */
function printHelp(): void
{
    echo <<<HELP

Simple-Trader Backtest Runner
==============================

Execute backtests from database configurations or directly with command-line parameters.
Supports multiple output formats and optional database saving.

USAGE:
  php commands/run-backtest.php [options]
  php commands/run-backtest.php --help

MODE 1 - Run from Database:
  php commands/run-backtest.php --run-id=<id> [--format=human|json] [--no-save]

  Load an existing backtest configuration from the database and execute it.
  Useful for re-running backtests or automating saved configurations.

MODE 2 - Direct Parameters:
  php commands/run-backtest.php --strategy=<name> --tickers=<ids> --start-date=<date> --end-date=<date> [options]

  Execute a backtest directly with all parameters specified via command line.
  Saves to database by default unless --no-save is specified.

OPTIONS:
  -h, --help                 Show this help message and exit

  Required (Mode 1):
    --run-id=<id>            Load configuration from database run ID

  Required (Mode 2):
    --strategy=<name>        Strategy class name (e.g., TestStrategy)
    --tickers=<ids>          Comma-separated ticker IDs (e.g., 1,2,3)
    --start-date=<date>      Start date in YYYY-MM-DD format
    --end-date=<date>        End date in YYYY-MM-DD format

  Optional:
    --name=<name>            Custom run name (default: "Backtest YYYY-MM-DD HH:MM:SS")
    --initial-capital=<amt>  Initial capital amount (default: 10000)
    --benchmark=<id>         Benchmark ticker ID for comparison
    --format=<format>        Output format: human|json (default: human)
    --no-save                Skip saving to database (for quick tests)

  Strategy Parameters:
    --param:<name>=<value>   Set strategy parameter (can be used multiple times)
                             Example: --param:threshold=0.05 --param:window=14

  Optimization:
    --optimize               Enable optimization mode (test parameter ranges)
    --opt:<name>=<f>:<t>:<s> Define parameter range: from:to:step
                             Example: --opt:threshold=0.01:0.1:0.01

EXAMPLES:

  1. Show help:
     php commands/run-backtest.php --help

  2. Run from database with human-readable output:
     php commands/run-backtest.php --run-id=1

  3. Run from database with JSON output:
     php commands/run-backtest.php --run-id=1 --format=json

  4. Simple backtest with direct parameters:
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1,2,3 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31

  5. Backtest without saving to database (quick test):
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31 \\
       --no-save

  6. Backtest with custom strategy parameters:
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31 \\
       --param:threshold=0.05 \\
       --param:window=14 \\
       --param:stop_loss=0.02

  7. Backtest with benchmark and custom capital:
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1,2 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31 \\
       --benchmark=1 \\
       --initial-capital=50000

  8. Run optimization (test parameter ranges):
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31 \\
       --optimize \\
       --opt:threshold=0.01:0.1:0.01 \\
       --opt:window=5:20:1

  9. JSON output for scripting/automation:
     php commands/run-backtest.php \\
       --strategy=TestStrategy \\
       --tickers=1 \\
       --start-date=2023-01-01 \\
       --end-date=2023-12-31 \\
       --format=json \\
       --no-save > results.json

OUTPUT FORMATS:

  human (default):
    Clear, formatted console output with metrics summary.
    Best for interactive use and reviewing results.

  json:
    Structured JSON output with all metrics and configuration.
    Best for scripting, automation, and programmatic processing.

EXIT CODES:
  0  Success
  1  Error (invalid parameters, strategy not found, execution failure)

MORE INFORMATION:
  See commands/README.md for detailed documentation and advanced examples.


HELP;
}
