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

// Register Database in Container
$container->set('db', function() {
    return \SimpleTrader\Database\Database::getInstance(__DIR__ . '/../database/tickers.db');
});

// Register TickerRepository in Container
$container->set('tickerRepository', function($container) {
    return new \SimpleTrader\Database\TickerRepository($container->get('db'));
});

// Register Twig View in Container
$container->set('view', function() {
    $twig = Twig::create(__DIR__ . '/../src/Views', [
        'cache' => false, // Disable cache for development
        'auto_reload' => true
    ]);

    // Add global variables
    $environment = $twig->getEnvironment();
    $environment->addGlobal('app_name', 'Simple-Trader');
    $environment->addGlobal('app_version', '1.0.0');

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

// Load Routes
require __DIR__ . '/../config/routes.php';

// Run App
$app->run();
