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
        'backtests' => __DIR__ . '/../database/backtests.db',
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

    // Stock Exchanges
    // List of supported stock exchanges with their countries
    // Sorted alphabetically by exchange code
    'exchanges' => [
        'AQUIS' => 'United Kingdom',
        'ARCA' => 'United States',
        'ASX' => 'Australia',
        'ATHEX' => 'Greece',
        'B3' => 'Brazil',
        'BELEX' => 'Serbia',
        'BER' => 'Germany',
        'BET' => 'Romania',
        'BHB' => 'Bahrain',
        'BIST' => 'Turkey',
        'BIVA' => 'Mexico',
        'BME' => 'Spain',
        'BSE' => 'India',
        'BSSE' => 'Bulgaria',
        'BVC' => 'Colombia',
        'BVB' => 'Romania',
        'BVL' => 'Peru',
        'BVMT' => 'Mauritius',
        'BX' => 'United States',
        'BYMA' => 'Argentina',
        'CSE' => 'Canada',
        'DFM' => 'United Arab Emirates',
        'DSE' => 'Bangladesh',
        'DUS' => 'Germany',
        'EGX' => 'Egypt',
        'EURONEXT' => 'Europe',
        'FWB' => 'Germany',
        'GPW' => 'Poland',
        'HAM' => 'Germany',
        'HAN' => 'Vietnam',
        'HNX' => 'Vietnam',
        'HOSE' => 'Vietnam',
        'IDX' => 'Indonesia',
        'JSE' => 'South Africa',
        'KRX' => 'South Korea',
        'KSE' => 'South Korea',
        'LSIN' => 'Latvia',
        'LSX' => 'Laos',
        'MIL' => 'Italy',
        'MUN' => 'Germany',
        'MYX' => 'Malaysia',
        'NASDAQ' => 'United States',
        'NSE' => 'India',
        'NYSE' => 'United States',
        'NZX' => 'New Zealand',
        'OMX' => 'Nordic Region',
        'OSL' => 'Norway',
        'OTC' => 'United States',
        'PSE' => 'Philippines',
        'SEHK' => 'Hong Kong',
        'SGX' => 'Singapore',
        'SIX' => 'Switzerland',
        'SSE' => 'China',
        'SWB' => 'Germany',
        'SZSE' => 'China',
        'TASE' => 'Israel',
        'TPEX' => 'Taiwan',
        'TSE' => 'Japan',
        'TSX' => 'Canada',
        'XETR' => 'Germany',
    ],
];
