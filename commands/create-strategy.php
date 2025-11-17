<?php

/**
 * Create Strategy Command
 *
 * Creates a new trading strategy PHP file from a template.
 * Claude can use this to programmatically create new strategies.
 *
 * Usage:
 *   php commands/create-strategy.php --name=MyStrategy --display-name="My Strategy" --description="Strategy description"
 *   php commands/create-strategy.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Helpers\StrategyDiscovery;

// Parse command line options
$options = getopt('h', ['help', 'name:', 'display-name:', 'description:', 'params:', 'lookback:', 'template:', 'format:']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Create Strategy Command
=======================

Creates a new trading strategy PHP file from a template.

USAGE:
  php commands/create-strategy.php --name=<ClassName> [options]
  php commands/create-strategy.php --help

REQUIRED OPTIONS:
  --name=NAME              PHP class name (e.g., MyStrategy, SMACrossover)
                           Must end with "Strategy" and be valid PHP class name

OPTIONAL OPTIONS:
  --display-name=NAME      Human-readable strategy name
  --description=DESC       Strategy description
  --params=JSON            JSON string of strategy parameters
                           Example: '{"length":20,"threshold":1.5}'
  --lookback=N             Max lookback period in bars (default: 30)
  --template=TYPE          Template type: 'basic' (default) or 'advanced'
  --format=FORMAT          Output format: 'human' (default) or 'json'
  -h, --help               Show this help message

TEMPLATES:
  - basic: Simple template with onOpen and onClose methods
  - advanced: Template with additional helper methods and state tracking

EXAMPLES:

  1. Create a basic strategy:
     php commands/create-strategy.php --name=MyStrategy

  2. Create with full details:
     php commands/create-strategy.php --name=SMACrossover \\
       --display-name="SMA Crossover Strategy" \\
       --description="Enters when fast SMA crosses above slow SMA" \\
       --params='{"fast_period":10,"slow_period":30}' \\
       --lookback=30

  3. JSON output for scripting:
     php commands/create-strategy.php --name=TestStrategy2 --format=json

OUTPUT:
  Creates a new PHP file in src/ directory:
  src/<StrategyName>.php

  The file will contain a basic strategy template that extends BaseStrategy
  and implements the required onOpen, onClose, and onStrategyEnd methods.

NOTES:
  - Strategy name must be a valid PHP class name
  - Strategy name should end with "Strategy" (will be added if missing)
  - Strategy name must be unique (file cannot already exist)
  - After creation, edit the generated file to implement your trading logic

EXIT CODES:
  0  Success - strategy file created
  1  Error - validation failed or file exists
  2  System error


HELP;
    exit(0);
}

// Validate required options
if (!isset($options['name']) || empty($options['name'])) {
    echo "✗ Error: --name is required\n";
    echo "Use --help for usage information.\n";
    exit(1);
}

try {
    $format = $options['format'] ?? 'human';
    $name = trim($options['name']);
    $displayName = $options['display-name'] ?? null;
    $description = $options['description'] ?? null;
    $paramsJson = $options['params'] ?? null;
    $lookback = isset($options['lookback']) ? (int)$options['lookback'] : 30;
    $template = $options['template'] ?? 'basic';

    // Validate and normalize strategy name
    if (!preg_match('/^[A-Z][a-zA-Z0-9]+$/', $name)) {
        throw new \InvalidArgumentException("Invalid strategy name. Must be PascalCase and contain only letters and numbers (e.g., MyStrategy)");
    }

    // Ensure name ends with "Strategy"
    if (!str_ends_with($name, 'Strategy')) {
        $name .= 'Strategy';
    }

    // Check if strategy already exists
    if (StrategyDiscovery::isValidStrategy($name)) {
        throw new \RuntimeException("Strategy '{$name}' already exists");
    }

    // Check if file already exists
    $filePath = __DIR__ . "/../src/{$name}.php";
    if (file_exists($filePath)) {
        throw new \RuntimeException("File '{$filePath}' already exists");
    }

    // Set default display name
    if (!$displayName) {
        // Convert PascalCase to spaces: MyNewStrategy -> My New Strategy
        $displayName = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('Strategy', '', $name)) . ' Strategy';
        $displayName = trim($displayName);
    }

    // Set default description
    if (!$description) {
        $description = "A custom trading strategy. Edit this description to explain your strategy's logic.";
    }

    // Parse parameters
    $parameters = [];
    if ($paramsJson) {
        $parameters = json_decode($paramsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON for --params: " . json_last_error_msg());
        }
    } else {
        // Default parameters
        $parameters = [
            'length' => 30
        ];
    }

    // Generate parameters code
    $paramsCode = "[\n";
    foreach ($parameters as $key => $value) {
        if (is_string($value)) {
            $paramsCode .= "        '{$key}' => '{$value}',\n";
        } elseif (is_bool($value)) {
            $paramsCode .= "        '{$key}' => " . ($value ? 'true' : 'false') . ",\n";
        } elseif (is_array($value)) {
            $paramsCode .= "        '{$key}' => " . var_export($value, true) . ",\n";
        } else {
            $paramsCode .= "        '{$key}' => {$value},\n";
        }
    }
    $paramsCode .= "    ]";

    // Get first parameter for lookback (commonly the main period)
    $firstParam = array_key_first($parameters);
    $lookbackCode = is_int($parameters[$firstParam] ?? null)
        ? "\$this->strategyParameters['{$firstParam}']"
        : $lookback;

    // Generate strategy code based on template
    if ($template === 'advanced') {
        $strategyCode = generateAdvancedTemplate($name, $displayName, $description, $paramsCode, $lookbackCode);
    } else {
        $strategyCode = generateBasicTemplate($name, $displayName, $description, $paramsCode, $lookbackCode);
    }

    // Write the file
    if (file_put_contents($filePath, $strategyCode) === false) {
        throw new \RuntimeException("Failed to write strategy file");
    }

    // Verify the file was created and is valid
    require_once $filePath;
    if (!StrategyDiscovery::isValidStrategy($name)) {
        // Clean up
        unlink($filePath);
        throw new \RuntimeException("Created strategy is not valid. Please check the template.");
    }

    $result = [
        'success' => true,
        'message' => "Strategy '{$name}' created successfully",
        'strategy' => [
            'class_name' => $name,
            'display_name' => $displayName,
            'description' => $description,
            'parameters' => $parameters,
            'lookback' => $lookback,
            'file_path' => $filePath,
            'template' => $template
        ]
    ];

    if ($format === 'json') {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "\n✓ " . $result['message'] . "\n\n";
        echo "Strategy Details:\n";
        echo "  Class Name: {$name}\n";
        echo "  Display Name: {$displayName}\n";
        echo "  File: {$filePath}\n";
        echo "  Template: {$template}\n";
        echo "  Parameters: " . json_encode($parameters) . "\n";
        echo "\nNext Steps:\n";
        echo "  1. Edit the strategy file to implement your trading logic:\n";
        echo "     {$filePath}\n";
        echo "  2. Run a backtest to test your strategy:\n";
        echo "     php commands/run-backtest.php --strategy={$name} --tickers=1 --start-date=2023-01-01 --end-date=2023-12-31\n";
        echo "\n";
    }

    exit(0);

} catch (\InvalidArgumentException $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Validation Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
} catch (\RuntimeException $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
} catch (\Exception $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ System Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(2);
}

/**
 * Generate basic strategy template
 */
function generateBasicTemplate(string $name, string $displayName, string $description, string $paramsCode, $lookbackCode): string
{
    $escapedDescription = addslashes($description);
    $escapedDisplayName = addslashes($displayName);

    return <<<PHP
<?php

namespace SimpleTrader;

use Carbon\Carbon;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Side;

/**
 * {$displayName}
 *
 * {$description}
 */
class {$name} extends BaseStrategy
{
    protected string \$strategyName = '{$escapedDisplayName}';
    protected string \$strategyDescription = '{$escapedDescription}';

    protected array \$strategyParameters = {$paramsCode};

    // Strategy state variables
    protected bool \$buySignal = false;
    protected bool \$sellSignal = false;
    protected ?string \$buyTicker = null;
    protected string \$entryComment = '';
    protected string \$exitComment = '';

    /**
     * Returns the maximum number of historical bars needed for this strategy.
     * The backtester uses this to know how much historical data to provide.
     */
    public function getMaxLookbackPeriod(): int
    {
        return {$lookbackCode};
    }

    /**
     * Called at market open each day.
     * Execute pending buy/sell signals set during onClose.
     *
     * @throws StrategyException
     */
    public function onOpen(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onOpen(\$assets, \$dateTime, \$isLive);

        // Execute pending buy signal
        if (\$this->buySignal && \$this->buyTicker) {
            \$position = \$this->entry(Side::Long, \$this->buyTicker, comment: \$this->entryComment);
            \$this->currentPositions[\$position->getId()] = \$position;
            \$this->buySignal = false;
            \$this->buyTicker = null;
        }

        // Execute pending sell signal
        if (\$this->sellSignal) {
            \$this->closeAll(\$this->exitComment);
            \$this->currentPositions = [];
            \$this->sellSignal = false;
        }
    }

    /**
     * Called at market close each day.
     * Analyze data and set buy/sell signals for next open.
     */
    public function onClose(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onClose(\$assets, \$dateTime, \$isLive);

        // TODO: Implement your strategy logic here
        // Example structure:

        foreach (\$this->tickers as \$ticker) {
            \$asset = \$assets->getAsset(\$ticker);

            // Ensure we have enough data
            if (\$asset->count() < \$this->getMaxLookbackPeriod()) {
                continue;
            }

            // Get closing prices
            \$closes = [];
            \$closesDf = \$asset->col('close')->export();
            foreach (\$closesDf as \$df) {
                \$closes[] = \$df['close'];
            }

            // Get current price
            \$currentPrice = \$assets->getCurrentValue(\$ticker, \$dateTime);

            // TODO: Calculate your indicators here
            // Example: \$sma = array_values(trader_sma(\$closes, \$this->strategyParameters['length']));

            // Check for exit signals (if we have positions)
            if (!empty(\$this->currentPositions)) {
                /** @var Position \$position */
                foreach (\$this->currentPositions as \$position) {
                    if (\$position->getTicker() === \$ticker) {
                        // TODO: Add your exit logic here
                        // if (exit_condition) {
                        //     \$this->sellSignal = true;
                        //     \$this->exitComment = "Exit reason";
                        // }
                    }
                }
            } else {
                // Check for entry signals (if no positions)
                // TODO: Add your entry logic here
                // if (entry_condition) {
                //     \$this->buySignal = true;
                //     \$this->buyTicker = \$ticker;
                //     \$this->entryComment = "Entry reason";
                // }
            }
        }
    }

    /**
     * Called when the backtest/strategy ends.
     * Clean up any open positions.
     *
     * @throws StrategyException
     */
    public function onStrategyEnd(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onStrategyEnd(\$assets, \$dateTime, \$isLive);

        // Close all positions at strategy end
        if (!empty(\$this->currentPositions)) {
            \$this->closeAll('Strategy end');
        }
    }
}
PHP;
}

/**
 * Generate advanced strategy template with additional features
 */
function generateAdvancedTemplate(string $name, string $displayName, string $description, string $paramsCode, $lookbackCode): string
{
    $escapedDescription = addslashes($description);
    $escapedDisplayName = addslashes($displayName);

    return <<<PHP
<?php

namespace SimpleTrader;

use Carbon\Carbon;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\Level;

/**
 * {$displayName}
 *
 * {$description}
 */
class {$name} extends BaseStrategy
{
    protected string \$strategyName = '{$escapedDisplayName}';
    protected string \$strategyDescription = '{$escapedDescription}';

    protected array \$strategyParameters = {$paramsCode};

    // Strategy state variables
    protected bool \$buySignal = false;
    protected bool \$sellSignal = false;
    protected ?string \$buyTicker = null;
    protected string \$entryComment = '';
    protected string \$exitComment = '';

    // Track indicators for analysis
    protected array \$indicators = [];

    /**
     * Returns the maximum number of historical bars needed for this strategy.
     */
    public function getMaxLookbackPeriod(): int
    {
        return {$lookbackCode};
    }

    /**
     * Called at market open each day.
     *
     * @throws StrategyException
     */
    public function onOpen(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onOpen(\$assets, \$dateTime, \$isLive);

        // Execute pending buy signal
        if (\$this->buySignal && \$this->buyTicker) {
            \$position = \$this->entry(Side::Long, \$this->buyTicker, comment: \$this->entryComment);
            \$this->currentPositions[\$position->getId()] = \$position;
            \$this->log("Opened position in {\$this->buyTicker}: {\$this->entryComment}");
            \$this->buySignal = false;
            \$this->buyTicker = null;
        }

        // Execute pending sell signal
        if (\$this->sellSignal) {
            \$this->closeAll(\$this->exitComment);
            \$this->log("Closed all positions: {\$this->exitComment}");
            \$this->currentPositions = [];
            \$this->sellSignal = false;
        }
    }

    /**
     * Called at market close each day.
     */
    public function onClose(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onClose(\$assets, \$dateTime, \$isLive);

        // Calculate indicators for all tickers
        \$this->calculateIndicators(\$assets, \$dateTime);

        // Check exit conditions first
        if (!empty(\$this->currentPositions)) {
            \$this->checkExitConditions(\$assets, \$dateTime);
        } else {
            // Then check entry conditions
            \$this->checkEntryConditions(\$assets, \$dateTime);
        }
    }

    /**
     * Calculate technical indicators for all tickers
     */
    protected function calculateIndicators(Assets \$assets, Carbon \$dateTime): void
    {
        \$this->indicators = [];

        foreach (\$this->tickers as \$ticker) {
            \$asset = \$assets->getAsset(\$ticker);

            if (\$asset->count() < \$this->getMaxLookbackPeriod()) {
                \$this->log("[{\$ticker}] Insufficient data ({\$asset->count()} bars)");
                continue;
            }

            // Extract OHLCV data
            \$ohlcv = \$this->extractOHLCV(\$asset);

            \$this->indicators[\$ticker] = [
                'price' => \$assets->getCurrentValue(\$ticker, \$dateTime),
                'ohlcv' => \$ohlcv,
            ];

            // TODO: Add your indicator calculations here
            // Example:
            // \$sma = trader_sma(\$ohlcv['close'], \$this->strategyParameters['length']);
            // \$this->indicators[\$ticker]['sma'] = \$sma ? end(\$sma) : null;
        }
    }

    /**
     * Check for entry signals
     */
    protected function checkEntryConditions(Assets \$assets, Carbon \$dateTime): void
    {
        \$bestTicker = null;
        \$bestScore = 0;

        foreach (\$this->indicators as \$ticker => \$data) {
            // TODO: Implement your entry logic
            // Calculate a score or check conditions
            \$score = 0;

            // Example scoring logic:
            // if (\$data['price'] > \$data['sma']) {
            //     \$score = (\$data['price'] - \$data['sma']) / \$data['sma'] * 100;
            // }

            if (\$score > \$bestScore) {
                \$bestScore = \$score;
                \$bestTicker = \$ticker;
            }
        }

        if (\$bestTicker && \$bestScore > 0) {
            \$this->buySignal = true;
            \$this->buyTicker = \$bestTicker;
            \$this->entryComment = "Score: " . number_format(\$bestScore, 2);
            \$this->log("[{\$bestTicker}] Entry signal: {\$this->entryComment}");
        }
    }

    /**
     * Check for exit signals
     */
    protected function checkExitConditions(Assets \$assets, Carbon \$dateTime): void
    {
        /** @var Position \$position */
        foreach (\$this->currentPositions as \$position) {
            \$ticker = \$position->getTicker();

            if (!isset(\$this->indicators[\$ticker])) {
                continue;
            }

            \$data = \$this->indicators[\$ticker];

            // TODO: Implement your exit logic
            // Example:
            // if (\$data['price'] < \$data['sma']) {
            //     \$this->sellSignal = true;
            //     \$this->exitComment = "Price below SMA";
            // }

            // Stop loss check
            \$entryPrice = \$position->getPrice();
            \$currentPrice = \$data['price'];
            \$percentChange = ((\$currentPrice - \$entryPrice) / \$entryPrice) * 100;

            // Default stop loss at 5%
            if (\$percentChange < -5) {
                \$this->sellSignal = true;
                \$this->exitComment = "Stop loss triggered: " . number_format(\$percentChange, 2) . "%";
            }
        }
    }

    /**
     * Extract OHLCV arrays from asset DataFrame
     */
    protected function extractOHLCV(\\MammothPHP\\WoollyM\\DataFrame \$asset): array
    {
        \$open = [];
        \$high = [];
        \$low = [];
        \$close = [];
        \$volume = [];

        foreach (\$asset->toArray() as \$row) {
            \$open[] = (float)\$row['open'];
            \$high[] = (float)\$row['high'];
            \$low[] = (float)\$row['low'];
            \$close[] = (float)\$row['close'];
            \$volume[] = (int)(\$row['volume'] ?? 0);
        }

        return [
            'open' => \$open,
            'high' => \$high,
            'low' => \$low,
            'close' => \$close,
            'volume' => \$volume,
        ];
    }

    /**
     * Log a message (only active during live trading or debugging)
     */
    protected function log(string \$message): void
    {
        if (\$this->logger) {
            \$this->logger->logInfo(\$message);
        }
    }

    /**
     * Called when the strategy ends.
     *
     * @throws StrategyException
     */
    public function onStrategyEnd(Assets \$assets, Carbon \$dateTime, bool \$isLive = false): void
    {
        parent::onStrategyEnd(\$assets, \$dateTime, \$isLive);

        if (!empty(\$this->currentPositions)) {
            \$this->closeAll('Strategy end');
            \$this->log("Closed all positions at strategy end");
        }
    }
}
PHP;
}
