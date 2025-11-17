<?php

/**
 * List Strategies Command
 *
 * Displays all available trading strategies with their parameters.
 * Essential for Claude to understand available strategies and create new ones.
 *
 * Usage:
 *   php commands/list-strategies.php [--format=human|json] [--details]
 *   php commands/list-strategies.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Helpers\QuantityType;

// Parse command line options
$options = getopt('h', ['help', 'format:', 'details', 'strategy:']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

List Strategies Command
=======================

Displays all available trading strategies with their parameters and metadata.

USAGE:
  php commands/list-strategies.php [options]
  php commands/list-strategies.php --strategy=TestStrategy --details
  php commands/list-strategies.php --help

OPTIONS:
  --format=FORMAT     Output format: 'human' (default) or 'json'
  --details           Show detailed information including overridden methods
  --strategy=NAME     Show info for specific strategy only
  -h, --help          Show this help message

OUTPUT FIELDS:
  - Class Name: PHP class name for the strategy
  - Strategy Name: Human-readable name
  - Description: What the strategy does
  - Parameters: Configurable strategy parameters with defaults
  - Lookback Period: How much historical data is needed

JSON FORMAT:
  When using --format=json, returns structured data:
  {
    "success": true,
    "strategies": [
      {
        "class_name": "TestStrategy",
        "strategy_name": "SMA Baseline Strategy",
        "description": "...",
        "parameters": {"length": 30},
        "max_lookback": 30,
        "file_path": "/path/to/file.php",
        "overridden_methods": [...]
      }
    ]
  }

EXAMPLES:

  1. List all strategies:
     php commands/list-strategies.php

  2. Get JSON output for scripting:
     php commands/list-strategies.php --format=json

  3. Show detailed info for a specific strategy:
     php commands/list-strategies.php --strategy=TestStrategy --details

  4. Show all strategies with full details:
     php commands/list-strategies.php --details

EXIT CODES:
  0  Success
  1  Error


HELP;
    exit(0);
}

try {
    $format = $options['format'] ?? 'human';
    $showDetails = isset($options['details']);
    $specificStrategy = $options['strategy'] ?? null;

    // Get available strategies
    $strategyNames = StrategyDiscovery::getAvailableStrategies();

    if (empty($strategyNames)) {
        if ($format === 'json') {
            echo json_encode([
                'success' => true,
                'strategies' => [],
                'message' => 'No strategies found'
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo "\nNo strategies found.\n";
            echo "Strategies should be placed in src/ directory and extend BaseStrategy.\n\n";
        }
        exit(0);
    }

    // Filter to specific strategy if requested
    if ($specificStrategy !== null) {
        if (!in_array($specificStrategy, $strategyNames)) {
            if ($format === 'json') {
                echo json_encode([
                    'success' => false,
                    'error' => "Strategy '{$specificStrategy}' not found",
                    'available_strategies' => $strategyNames
                ], JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                echo "✗ Strategy '{$specificStrategy}' not found.\n";
                echo "Available strategies: " . implode(', ', $strategyNames) . "\n";
            }
            exit(1);
        }
        $strategyNames = [$specificStrategy];
    }

    // Enrich strategy data
    $strategies = [];
    foreach ($strategyNames as $strategyClassName) {
        $info = StrategyDiscovery::getStrategyInfo($strategyClassName);

        $strategyData = [
            'class_name' => $info['class_name'],
            'strategy_name' => $info['strategy_name'],
            'description' => $info['strategy_description'],
            'file_path' => $info['file_path'],
        ];

        // Get strategy parameters
        $fullClassName = $info['full_class_name'];
        try {
            $reflection = new ReflectionClass($fullClassName);
            $instance = $reflection->newInstanceWithoutConstructor();

            // Get parameters
            $paramsProperty = $reflection->getProperty('strategyParameters');
            $paramsProperty->setAccessible(true);
            $strategyData['parameters'] = $paramsProperty->getValue($instance);

            // Get max lookback period
            $strategy = $reflection->newInstance(QuantityType::Percent, []);
            $strategyData['max_lookback'] = $strategy->getMaxLookbackPeriod();
        } catch (\Exception $e) {
            $strategyData['parameters'] = [];
            $strategyData['max_lookback'] = 0;
        }

        if ($showDetails) {
            $strategyData['overridden_methods'] = $info['overridden_methods'];
            $strategyData['doc_comment'] = $info['doc_comment'];
        }

        $strategies[] = $strategyData;
    }

    // Output based on format
    if ($format === 'json') {
        $output = [
            'success' => true,
            'strategies' => $strategies,
            'total' => count($strategies)
        ];
        echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        // Human readable format
        echo "\n=== Available Strategies ===\n";

        foreach ($strategies as $strategy) {
            echo "\n" . str_repeat('─', 60) . "\n";
            echo "Class: {$strategy['class_name']}\n";
            echo "Name: {$strategy['strategy_name']}\n";

            if ($strategy['description']) {
                echo "Description: " . wordwrap($strategy['description'], 58, "\n             ") . "\n";
            }

            echo "File: {$strategy['file_path']}\n";
            echo "Max Lookback Period: {$strategy['max_lookback']} bars\n";

            echo "\nParameters:\n";
            if (empty($strategy['parameters'])) {
                echo "  (none)\n";
            } else {
                foreach ($strategy['parameters'] as $key => $value) {
                    $type = gettype($value);
                    if ($type === 'boolean') {
                        $valueStr = $value ? 'true' : 'false';
                    } elseif ($type === 'array') {
                        $valueStr = json_encode($value);
                    } else {
                        $valueStr = (string)$value;
                    }
                    echo "  - {$key}: {$valueStr} ({$type})\n";
                }
            }

            if ($showDetails && !empty($strategy['overridden_methods'])) {
                echo "\nOverridden Methods:\n";
                foreach ($strategy['overridden_methods'] as $method) {
                    echo "  - {$method['name']}(";
                    echo implode(', ', $method['parameters']);
                    echo ")\n";
                }
            }
        }

        echo "\n" . str_repeat('─', 60) . "\n";
        echo "Total: " . count($strategies) . " strategies\n";
        echo "\nTo run a backtest:\n";
        echo "  php commands/run-backtest.php --strategy=<ClassName> --tickers=<ids> --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD\n";
        echo "\n";
    }

    exit(0);

} catch (\Exception $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
}
