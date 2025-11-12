<?php

/**
 * Simple-Trader Web UI Entry Point
 *
 * Slim Framework 4 Application
 */

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load Configuration
$config = require __DIR__ . '/../config/config.php';

// Create Container
$container = new Container();

// Set container to create App with
AppFactory::setContainer($container);

// Create App
$app = AppFactory::create();

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Register Configuration in Container
$container->set('config', function() use ($config) {
    return $config;
});

// Register Tickers Database in Container
$container->set('db', function() use ($config) {
    return \SimpleTrader\Database\Database::getInstance($config['database']['tickers']);
});

// Register Runs Database in Container
$container->set('runsDb', function() use ($config) {
    return \SimpleTrader\Database\Database::getInstance($config['database']['runs']);
});

// Register Monitors Database in Container
$container->set('monitorsDb', function() use ($config) {
    return \SimpleTrader\Database\Database::getInstance($config['database']['monitors']);
});

// Register TickerRepository in Container
$container->set('tickerRepository', function($container) {
    return new \SimpleTrader\Database\TickerRepository($container->get('db'));
});

// Register QuoteRepository in Container
$container->set('quoteRepository', function($container) {
    return new \SimpleTrader\Database\QuoteRepository($container->get('db'));
});

// Register RunRepository in Container
$container->set('runRepository', function($container) {
    return new \SimpleTrader\Database\RunRepository($container->get('runsDb'));
});

// Register MonitorRepository in Container
$container->set('monitorRepository', function($container) {
    return new \SimpleTrader\Database\MonitorRepository($container->get('monitorsDb'));
});

// Register Twig View in Container
$container->set('view', function() use ($config) {
    $twig = Twig::create($config['paths']['views'], [
        'cache' => $config['view']['cache'],
        'auto_reload' => $config['view']['auto_reload']
    ]);

    // Add global variables
    $environment = $twig->getEnvironment();
    $environment->addGlobal('app_name', $config['app']['name']);
    $environment->addGlobal('app_version', $config['app']['version']);

    return $twig;
});

// Add Twig-View Middleware
$app->add(TwigMiddleware::createFromContainer($app, 'view'));

// Start Session for flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Add flash messages to container
$container->set('flash', function() {
    return new class {
        public function set(string $key, string $message): void {
            $_SESSION['flash'][$key] = $message;
        }

        public function get(string $key): ?string {
            if (!isset($_SESSION['flash'][$key])) {
                return null;
            }
            $message = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $message;
        }

        public function has(string $key): bool {
            return isset($_SESSION['flash'][$key]);
        }

        public function all(): array {
            $messages = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            return $messages;
        }
    };
});

// Register DocumentationController with dependencies
$container->set(\SimpleTrader\Controllers\DocumentationController::class, function($container) use ($config) {
    return new \SimpleTrader\Controllers\DocumentationController(
        $container->get('view'),
        __DIR__ . '/..'  // Project root directory
    );
});

// Load Routes
require __DIR__ . '/../config/routes.php';

// Run App
$app->run();
