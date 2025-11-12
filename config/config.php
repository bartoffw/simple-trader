<?php

/**
 * Simple-Trader Configuration
 *
 * Central configuration file for both web UI and CLI tools
 */

return [
    // Application Settings
    'app' => [
        'name' => 'Simple-Trader',
        'version' => '1.0.0',
        'environment' => getenv('APP_ENV') ?: 'production', // production, development, testing
    ],

    // Database Settings
    'database' => [
        'tickers' => __DIR__ . '/../database/tickers.db',
        'runs' => __DIR__ . '/../database/runs.db',
        'monitors' => __DIR__ . '/../database/monitors.db',
    ],

    // View Settings
    'view' => [
        'cache' => false, // Set to true in production for better performance
        'auto_reload' => true,
    ],

    // Paths
    'paths' => [
        'views' => __DIR__ . '/../src/Views',
        'migrations' => __DIR__ . '/../database/migrations',
        'data' => __DIR__ . '/../data',
    ],
];
