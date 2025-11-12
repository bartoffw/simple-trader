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
    // List of supported stock exchanges grouped by region
    // Regions are sorted alphabetically, exchanges within each region are sorted by code
    'exchanges' => [
        'Africa' => [
            'BVMT' => 'Mauritius',
            'EGX' => 'Egypt',
            'JSE' => 'South Africa',
        ],
        'Asia-Pacific' => [
            'ASX' => 'Australia',
            'BSE' => 'India',
            'DSE' => 'Bangladesh',
            'HAN' => 'Vietnam',
            'HNX' => 'Vietnam',
            'HOSE' => 'Vietnam',
            'IDX' => 'Indonesia',
            'KRX' => 'South Korea',
            'KSE' => 'South Korea',
            'LSX' => 'Laos',
            'MYX' => 'Malaysia',
            'NSE' => 'India',
            'NZX' => 'New Zealand',
            'PSE' => 'Philippines',
            'SEHK' => 'Hong Kong',
            'SGX' => 'Singapore',
            'SSE' => 'China',
            'SZSE' => 'China',
            'TPEX' => 'Taiwan',
            'TSE' => 'Japan',
        ],
        'Europe' => [
            'AQUIS' => 'United Kingdom',
            'ATHEX' => 'Greece',
            'BELEX' => 'Serbia',
            'BER' => 'Germany',
            'BET' => 'Romania',
            'BME' => 'Spain',
            'BSSE' => 'Bulgaria',
            'BVB' => 'Romania',
            'DUS' => 'Germany',
            'EURONEXT' => 'Europe',
            'FWB' => 'Germany',
            'GPW' => 'Poland',
            'HAM' => 'Germany',
            'LSIN' => 'Latvia',
            'MIL' => 'Italy',
            'MUN' => 'Germany',
            'OMX' => 'Nordic Region',
            'OSL' => 'Norway',
            'SIX' => 'Switzerland',
            'SWB' => 'Germany',
            'XETR' => 'Germany',
        ],
        'Latin America' => [
            'B3' => 'Brazil',
            'BIVA' => 'Mexico',
            'BVC' => 'Colombia',
            'BVL' => 'Peru',
            'BYMA' => 'Argentina',
        ],
        'Middle East' => [
            'BHB' => 'Bahrain',
            'BIST' => 'Turkey',
            'DFM' => 'United Arab Emirates',
            'TASE' => 'Israel',
        ],
        'North America' => [
            'ARCA' => 'United States',
            'BX' => 'United States',
            'CSE' => 'Canada',
            'NASDAQ' => 'United States',
            'NYSE' => 'United States',
            'OTC' => 'United States',
            'TSX' => 'Canada',
        ],
    ],
];
