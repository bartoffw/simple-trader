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

    // View ticker details (optional - for future use)
    $group->get('/{id:[0-9]+}', TickerController::class . ':show')
        ->setName('tickers.show');
});

// API Routes (for AJAX requests - future enhancement)
$app->group('/api', function (RouteCollectorProxy $group) {

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
