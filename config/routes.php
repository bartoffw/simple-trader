<?php

/**
 * Simple-Trader Web UI Routes
 *
 * Defines all application routes
 */

use SimpleTrader\Controllers\TickerController;
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

    // View ticker details (optional - for future use)
    $group->get('/{id:[0-9]+}', TickerController::class . ':show')
        ->setName('tickers.show');
});

// API Routes (for AJAX requests - future enhancement)
$app->group('/api', function (RouteCollectorProxy $group) {

    // Get quote data for charts
    $group->get('/tickers/{id:[0-9]+}/quotes', function (Request $request, Response $response, array $args, $container) {
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
                // Color based on price movement (green for up, red for down)
                $color = (float)$quote['close'] >= (float)$quote['open'] ? 'rgba(38, 166, 154, 0.5)' : 'rgba(239, 83, 80, 0.5)';
                $volumeData[] = [
                    'time' => $quote['date'],
                    'value' => (float)$quote['volume'],
                    'color' => $color
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
    });

    // Get ticker statistics
    $group->get('/stats', function (Request $request, Response $response, $container) {
        $repository = $container->get('tickerRepository');
        $stats = $repository->getStatistics();

        $response->getBody()->write(json_encode($stats));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Validate ticker symbol (check if exists)
    $group->get('/validate/symbol/{symbol}', function (Request $request, Response $response, array $args, $container) {
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
