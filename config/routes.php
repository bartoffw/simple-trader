<?php

/**
 * Simple-Trader Web UI Routes
 *
 * Defines all application routes
 */

use SimpleTrader\Controllers\TickerController;
use SimpleTrader\Controllers\StrategyController;
use SimpleTrader\Controllers\RunnerController;
use SimpleTrader\Controllers\DocumentationController;
use SimpleTrader\Controllers\LogsController;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Dashboard / Home - redirects to ticker list
$app->get('/', function (Request $request, Response $response) {
    return $response
        ->withHeader('Location', '/tickers')
        ->withStatus(302);
});

// Ticker Management Routes
$app->group('/tickers', function (RouteCollectorProxy $group) {

    // List all tickers (index page)
    $group->get('', TickerController::class . ':index')
        ->setName('tickers.index');

    // Show create ticker form
    $group->get('/create', TickerController::class . ':create')
        ->setName('tickers.create');

    // Store new ticker (POST)
    $group->post('', TickerController::class . ':store')
        ->setName('tickers.store');

    // Show edit ticker form
    $group->get('/{id:[0-9]+}/edit', TickerController::class . ':edit')
        ->setName('tickers.edit');

    // Update ticker (POST - using _method override)
    $group->post('/{id:[0-9]+}', TickerController::class . ':update')
        ->setName('tickers.update');

    // Delete ticker (POST - using _method override)
    $group->post('/{id:[0-9]+}/delete', TickerController::class . ':destroy')
        ->setName('tickers.delete');

    // Toggle ticker enabled status (POST)
    $group->post('/{id:[0-9]+}/toggle', TickerController::class . ':toggle')
        ->setName('tickers.toggle');

    // Fetch quotes for ticker (AJAX)
    $group->post('/{id:[0-9]+}/fetch-quotes', TickerController::class . ':fetchQuotes')
        ->setName('tickers.fetch');

    // Download ticker quotes as CSV
    $group->get('/{id:[0-9]+}/download-csv', TickerController::class . ':downloadCsv')
        ->setName('tickers.downloadCsv');

    // View ticker details (optional - for future use)
    $group->get('/{id:[0-9]+}', TickerController::class . ':show')
        ->setName('tickers.show');
});

// Strategy Management Routes
$app->group('/strategies', function (RouteCollectorProxy $group) {

    // List all strategies (index page)
    $group->get('', StrategyController::class . ':index')
        ->setName('strategies.index');

    // View strategy details
    $group->get('/{className}', StrategyController::class . ':show')
        ->setName('strategies.show');
});

// Backtest Management Routes (Backtest Execution)
$app->group('/backtests', function (RouteCollectorProxy $group) {

    // List all backtests (index page)
    $group->get('', RunnerController::class . ':index')
        ->setName('backtests.index');

    // Show create backtest form
    $group->get('/create', RunnerController::class . ':create')
        ->setName('backtests.create');

    // Store new backtest and start execution (POST)
    $group->post('', RunnerController::class . ':store')
        ->setName('backtests.store');

    // View backtest details and results
    $group->get('/{id:[0-9]+}', RunnerController::class . ':show')
        ->setName('backtests.show');

    // AJAX endpoint for real-time log polling
    $group->get('/{id:[0-9]+}/logs', RunnerController::class . ':logs')
        ->setName('backtests.logs');

    // Download standalone HTML report
    $group->get('/{id:[0-9]+}/report', RunnerController::class . ':downloadReport')
        ->setName('backtests.report');

    // Delete backtest (POST)
    $group->post('/{id:[0-9]+}/delete', RunnerController::class . ':destroy')
        ->setName('backtests.delete');
});

// Monitor Management Routes (Strategy Monitoring)
$app->group('/monitors', function (RouteCollectorProxy $group) {

    // List all monitors (index page)
    $group->get('', SimpleTrader\Controllers\MonitorController::class . ':index')
        ->setName('monitors.index');

    // Show create monitor form
    $group->get('/create', SimpleTrader\Controllers\MonitorController::class . ':create')
        ->setName('monitors.create');

    // Store new monitor (POST)
    $group->post('', SimpleTrader\Controllers\MonitorController::class . ':store')
        ->setName('monitors.store');

    // Get backtest progress (AJAX)
    $group->get('/{id:[0-9]+}/progress', SimpleTrader\Controllers\MonitorController::class . ':progress')
        ->setName('monitors.progress');

    // View monitor details
    $group->get('/{id:[0-9]+}', SimpleTrader\Controllers\MonitorController::class . ':show')
        ->setName('monitors.show');

    // Stop monitor (POST)
    $group->post('/{id:[0-9]+}/stop', SimpleTrader\Controllers\MonitorController::class . ':stop')
        ->setName('monitors.stop');

    // Activate monitor (POST)
    $group->post('/{id:[0-9]+}/activate', SimpleTrader\Controllers\MonitorController::class . ':activate')
        ->setName('monitors.activate');

    // Delete monitor (POST)
    $group->post('/{id:[0-9]+}/delete', SimpleTrader\Controllers\MonitorController::class . ':destroy')
        ->setName('monitors.delete');
});

// Documentation Routes
$app->group('/docs', function (RouteCollectorProxy $group) {

    // Documentation index page
    $group->get('', DocumentationController::class . ':index')
        ->setName('docs.index');

    // View specific documentation
    $group->get('/{slug}', DocumentationController::class . ':show')
        ->setName('docs.show');
});

// Logs Viewer Routes
$app->group('/logs', function (RouteCollectorProxy $group) {

    // Logs index page (overview of all logs)
    $group->get('', LogsController::class . ':index')
        ->setName('logs.index');

    // Get log statistics (AJAX)
    $group->get('/stats', LogsController::class . ':stats')
        ->setName('logs.stats');

    // View specific log file
    $group->get('/{slug}', LogsController::class . ':show')
        ->setName('logs.show');

    // Get log lines (AJAX for pagination)
    $group->get('/{slug}/lines', LogsController::class . ':getLines')
        ->setName('logs.lines');

    // Clear log file (POST)
    $group->post('/{slug}/clear', LogsController::class . ':clear')
        ->setName('logs.clear');
});

// API Routes (for AJAX requests - future enhancement)
$app->group('/api', function (RouteCollectorProxy $group) use ($container) {

    // Get quote data for charts
    $group->get('/tickers/{id:[0-9]+}/quotes', function (Request $request, Response $response, array $args) use ($container) {
        try {
            $tickerId = (int)$args['id'];

            // Get database and quote repository
            $database = $container->get('db');
            $quoteRepository = new \SimpleTrader\Database\QuoteRepository($database);

            // Get all quotes for this ticker
            $quotes = $quoteRepository->getQuotesByTicker($tickerId);

            if (empty($quotes)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'No quotes found for this ticker'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Format quotes for Lightweight Charts
            // Candlestick format: {time: 'YYYY-MM-DD', open: number, high: number, low: number, close: number}
            // Volume format: {time: 'YYYY-MM-DD', value: number, color: string}
            $candlestickData = [];
            $volumeData = [];
            $hasVolume = false;

            foreach ($quotes as $quote) {
                $candlestickData[] = [
                    'time' => $quote['date'],
                    'open' => (float)$quote['open'],
                    'high' => (float)$quote['high'],
                    'low' => (float)$quote['low'],
                    'close' => (float)$quote['close']
                ];

                // Only include volume if it exists and is non-zero
                if (isset($quote['volume']) && $quote['volume'] > 0) {
                    $hasVolume = true;
                    $volumeData[] = [
                        'time' => $quote['date'],
                        'value' => (float)$quote['volume'],
                        'color' => 'rgba(128, 128, 128, 0.3)'  // Grey color
                    ];
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'candlestickData' => $candlestickData,
                'volumeData' => $hasVolume ? $volumeData : null,
                'count' => count($candlestickData)
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Error fetching quotes: ' . $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    // Get ticker statistics
    $group->get('/stats', function (Request $request, Response $response) use ($container) {
        $repository = $container->get('tickerRepository');
        $stats = $repository->getStatistics();

        $response->getBody()->write(json_encode($stats));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Validate ticker symbol (check if exists)
    $group->get('/validate/symbol/{symbol}', function (Request $request, Response $response, array $args) use ($container) {
        $repository = $container->get('tickerRepository');
        $symbol = $args['symbol'];
        $ticker = $repository->getTickerBySymbol($symbol);

        $result = [
            'exists' => $ticker !== null,
            'valid' => $ticker === null, // Valid if doesn't exist
            'message' => $ticker !== null ? 'Symbol already exists' : 'Symbol available'
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });
});

// Health check endpoint
$app->get('/health', function (Request $request, Response $response) {
    $health = [
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'database' => file_exists(__DIR__ . '/../database/tickers.db') ? 'connected' : 'not found'
    ];

    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});
