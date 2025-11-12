#!/usr/bin/env php
<?php

/**
 * CLI Tool: Run Backtest
 *
 * Executes a backtest run in the background.
 * Called by BackgroundRunner service.
 *
 * Usage:
 *   php commands/run-backtest.php <run-id>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\Database\Database;
use SimpleTrader\Database\RunRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Helpers\DatabaseAssetLoader;
use SimpleTrader\Helpers\OptimizationParam;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Services\BacktestLogger;
use SimpleTrader\Services\EmbeddedReportGenerator;

if ($argc < 2) {
    echo "Usage: php commands/run-backtest.php <run-id>\n";
    exit(1);
}

$runId = (int)$argv[1];

try {
    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize database connections and repositories
    $tickersDb = Database::getInstance($config['database']['tickers']);
    $runsDb = Database::getInstance($config['database']['runs']);

    $runRepository = new RunRepository($runsDb);
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);

    // Get run details
    $run = $runRepository->getRun($runId);
    if (!$run) {
        echo "Run not found: {$runId}\n";
        exit(1);
    }

    // Update status to running
    $runRepository->updateStatus($runId, 'running');

    // Create custom logger that writes to database
    $logger = new BacktestLogger($runRepository, $runId);
    $logger->setLevel(Level::Info);

    $startTime = microtime(true);

    $logger->logInfo("=== Starting backtest run #{$runId} ===");
    $logger->logInfo("Strategy: {$run['strategy_class']}");
    $logger->logInfo("Period: {$run['start_date']} to {$run['end_date']}");
    $logger->logInfo("Initial Capital: " . number_format($run['initial_capital'], 2));

    // Load assets from database
    $assetLoader = new DatabaseAssetLoader($quoteRepository, $tickerRepository);
    $tickerIds = json_decode($run['tickers'], true);

    $logger->logInfo("Loading {count} ticker(s) from database...", ['count' => count($tickerIds)]);
    $assets = $assetLoader->loadAssets($tickerIds, $run['start_date'], $run['end_date']);

    if ($assets->isEmpty()) {
        throw new Exception('No asset data loaded. Check if quotes exist for selected tickers in the specified date range.');
    }

    $logger->logInfo("Loaded tickers: " . implode(', ', $assets->getTickers()));

    // Create strategy instance
    $strategyClass = StrategyDiscovery::getStrategyClassName($run['strategy_class']);
    $strategyParams = $run['strategy_parameters'] ? json_decode($run['strategy_parameters'], true) : [];

    $strategy = new $strategyClass(paramsOverrides: $strategyParams);
    $strategy->setCapital($run['initial_capital']);
    $strategy->setLogger($logger);
    $strategy->setTickers($assets->getTickers());

    // Create backtester
    $backtest = new Backtester(Resolution::Daily);
    $backtest->setLogger($logger);
    $backtest->setStrategy($strategy);

    // Set benchmark if specified
    if ($run['benchmark_ticker_id']) {
        $benchmarkSymbol = $assetLoader->getTickerSymbol($run['benchmark_ticker_id']);
        if ($benchmarkSymbol && $assets->hasAsset($benchmarkSymbol)) {
            $benchmarkAsset = $assets->getAsset($benchmarkSymbol);
            $backtest->setBenchmark($benchmarkAsset, $benchmarkSymbol);
            $logger->logInfo("Benchmark: {$benchmarkSymbol}");
        }
    }

    // Prepare optimization params if needed
    $optimizationParams = [];
    if ($run['is_optimization'] && $run['optimization_params']) {
        $optimizationData = json_decode($run['optimization_params'], true);
        foreach ($optimizationData as $param) {
            $optimizationParams[] = new OptimizationParam(
                $param['name'],
                $param['from'],
                $param['to'],
                $param['step']
            );
        }
        $logger->logInfo("Optimization enabled with " . count($optimizationParams) . " parameter(s)");
    }

    // Run backtest
    $logger->logInfo("Running backtest...");
    $backtest->runBacktest(
        $assets,
        new Carbon($run['start_date']),
        new Carbon($run['end_date']),
        empty($optimizationParams) ? null : $optimizationParams
    );

    $executionTime = microtime(true) - $startTime;
    $logger->logInfo("Backtest completed in " . number_format($executionTime, 2) . "s");

    // Generate report with embedded chart library
    $logger->logInfo("Generating report...");
    $reportGenerator = new EmbeddedReportGenerator();
    $reportHtml = $reportGenerator->generateReport($backtest, $assets->getTickers());

    // Extract metrics
    $strategyInstance = $run['is_optimization'] ? $backtest->getBestStrategy() : $backtest->getStrategy();
    if ($strategyInstance) {
        $tradeLog = $strategyInstance->getTradeLog();
        $tradeStats = $strategyInstance->getTradeStats($tradeLog);

        $metrics = [
            'net_profit' => $tradeStats['net_profit'] ?? 0,
            'net_profit_percent' => $tradeStats['net_profit_percent'] ?? 0,
            'total_transactions' => count($tradeLog),
            'profitable_transactions' => $tradeStats['profitable_transactions'] ?? 0,
            'profit_factor' => $tradeStats['profit_factor'] ?? 0,
            'max_drawdown_value' => $tradeStats['max_strategy_drawdown_value'] ?? 0,
            'max_drawdown_percent' => $tradeStats['max_strategy_drawdown_percent'] ?? 0
        ];
    } else {
        $metrics = [];
    }

    // Save results
    $runRepository->updateResults($runId, [
        'report_html' => $reportHtml,
        'result_metrics' => json_encode($metrics),
        'execution_time' => $executionTime,
        'status' => 'completed'
    ]);

    $logger->logInfo("=== Backtest completed successfully ===");

    exit(0);

} catch (\Exception $e) {
    $errorMsg = $e->getMessage() . "\n" . $e->getTraceAsString();

    if (isset($logger)) {
        $logger->logError("ERROR: " . $e->getMessage());
    }

    if (isset($runRepository) && isset($runId)) {
        $runRepository->updateError($runId, $errorMsg);
    }

    echo "Error: {$errorMsg}\n";
    exit(1);
}
