<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SimpleTrader\Database\MonitorRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Helpers\StrategyDiscovery;

/**
 * Monitor Controller
 *
 * Handles strategy monitoring operations
 */
class MonitorController
{
    private ContainerInterface $container;
    private MonitorRepository $monitorRepository;
    private TickerRepository $tickerRepository;
    private Twig $view;
    private $flash;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->monitorRepository = $container->get('monitorRepository');
        $this->tickerRepository = $container->get('tickerRepository');
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
    }

    /**
     * Display list of all monitors
     *
     * GET /monitors
     */
    public function index(Request $request, Response $response): Response
    {
        $monitors = $this->monitorRepository->getAllMonitors();
        $stats = $this->monitorRepository->getStatistics();

        // Enhance monitors with latest snapshot data
        $enhancedMonitors = [];
        foreach ($monitors as $monitor) {
            $latestSnapshot = $this->monitorRepository->getLatestSnapshot($monitor['id']);

            $enhancedMonitors[] = [
                'id' => $monitor['id'],
                'name' => $monitor['name'],
                'strategy_class' => $monitor['strategy_class'],
                'status' => $monitor['status'],
                'initial_capital' => $monitor['initial_capital'],
                'start_date' => $monitor['start_date'],
                'last_processed_date' => $monitor['last_processed_date'],
                'created_at' => $monitor['created_at'],
                'current_equity' => $latestSnapshot['equity'] ?? $monitor['initial_capital'],
                'cumulative_return' => $latestSnapshot['cumulative_return'] ?? 0,
            ];
        }

        return $this->view->render($response, 'monitors/index.twig', [
            'monitors' => $enhancedMonitors,
            'stats' => $stats,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Show create monitor form
     *
     * GET /monitors/create
     */
    public function create(Request $request, Response $response): Response
    {
        // Get available strategies with parameters
        $strategyClasses = StrategyDiscovery::getAvailableStrategies();
        $strategies = [];
        foreach ($strategyClasses as $strategyClass) {
            $info = StrategyDiscovery::getStrategyInfo($strategyClass);
            if ($info) {
                // Extract strategy parameters using reflection
                $info['parameters'] = $this->extractStrategyParameters($strategyClass);
                $strategies[] = $info;
            }
        }

        // Get available tickers
        $tickers = $this->tickerRepository->getEnabledTickers();

        return $this->view->render($response, 'monitors/create.twig', [
            'strategies' => $strategies,
            'tickers' => $tickers,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Store a new monitor
     *
     * POST /monitors
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Validation
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Monitor name is required';
        }

        if (empty($data['strategy_class'])) {
            $errors['strategy_class'] = 'Strategy is required';
        }

        if (empty($data['tickers']) || !is_array($data['tickers']) || count($data['tickers']) === 0) {
            $errors['tickers'] = 'At least one ticker must be selected';
        }

        if (empty($data['start_date'])) {
            $errors['start_date'] = 'Start date is required';
        }

        if (empty($data['initial_capital']) || !is_numeric($data['initial_capital']) || $data['initial_capital'] <= 0) {
            $errors['initial_capital'] = 'Initial capital must be a positive number';
        }

        if (!empty($errors)) {
            // Get data for form re-render
            $strategyClasses = StrategyDiscovery::getAvailableStrategies();
            $strategies = [];
            foreach ($strategyClasses as $strategyClass) {
                $info = StrategyDiscovery::getStrategyInfo($strategyClass);
                if ($info) {
                    $strategies[] = $info;
                }
            }
            $tickers = $this->tickerRepository->getEnabledTickers();

            return $this->view->render($response->withStatus(400), 'monitors/create.twig', [
                'strategies' => $strategies,
                'tickers' => $tickers,
                'errors' => $errors,
                'old' => $data
            ]);
        }

        try {
            // Process strategy parameters from array format to JSON
            $strategyParametersJson = null;
            if (!empty($data['strategy_params']) && is_array($data['strategy_params'])) {
                // Convert array values to appropriate types
                $strategyParams = [];
                foreach ($data['strategy_params'] as $key => $value) {
                    if (is_numeric($value)) {
                        if (strpos($value, '.') !== false) {
                            $strategyParams[$key] = floatval($value);
                        } else {
                            $strategyParams[$key] = intval($value);
                        }
                    } else {
                        $strategyParams[$key] = $value;
                    }
                }
                $strategyParametersJson = json_encode($strategyParams);
            }

            // Prepare monitor data
            $monitorData = [
                'name' => $data['name'],
                'strategy_class' => $data['strategy_class'],
                'tickers' => implode(',', $data['tickers']),
                'strategy_parameters' => $strategyParametersJson,
                'start_date' => $data['start_date'],
                'initial_capital' => $data['initial_capital'],
                'status' => 'initializing'
            ];

            // Create monitor
            $monitorId = $this->monitorRepository->createMonitor($monitorData);

            // Set initial backtest status
            $this->monitorRepository->updateBacktestProgress($monitorId, 0, 'pending', null, null);

            // Trigger backtest in background
            $this->triggerBackgroundBacktest($monitorId);

            $this->flash->set('success', "Monitor '{$data['name']}' created successfully. Initial backtest is running in the background.");

            return $response
                ->withHeader('Location', '/monitors/' . $monitorId)
                ->withStatus(302);

        } catch (\Exception $e) {
            $strategyClasses = StrategyDiscovery::getAvailableStrategies();
            $strategies = [];
            foreach ($strategyClasses as $strategyClass) {
                $info = StrategyDiscovery::getStrategyInfo($strategyClass);
                if ($info) {
                    $strategies[] = $info;
                }
            }
            $tickers = $this->tickerRepository->getEnabledTickers();

            return $this->view->render($response->withStatus(500), 'monitors/create.twig', [
                'strategies' => $strategies,
                'tickers' => $tickers,
                'errors' => ['general' => 'An error occurred: ' . $e->getMessage()],
                'old' => $data
            ]);
        }
    }

    /**
     * Display monitor details
     *
     * GET /monitors/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $monitor = $this->monitorRepository->getMonitor($id);

        if ($monitor === null) {
            $this->flash->set('error', 'Monitor not found.');
            return $response
                ->withHeader('Location', '/monitors')
                ->withStatus(302);
        }

        // Get strategy info
        $strategyInfo = StrategyDiscovery::getStrategyInfo($monitor['strategy_class']);

        // Get ticker symbols
        $tickerIds = array_map('intval', explode(',', $monitor['tickers']));
        $tickerSymbols = [];
        foreach ($tickerIds as $tickerId) {
            $ticker = $this->tickerRepository->getTicker($tickerId);
            if ($ticker) {
                $tickerSymbols[] = $ticker['symbol'];
            }
        }

        // Get latest snapshot
        $latestSnapshot = $this->monitorRepository->getLatestSnapshot($id);

        // Get daily snapshots for chart (last 90 days)
        $snapshots = $this->monitorRepository->getDailySnapshots($id, 90);

        // Get recent trades
        $trades = $this->monitorRepository->getTrades($id, 20);

        // Get metrics
        $backtestMetrics = $this->monitorRepository->getMetrics($id, 'backtest');
        $forwardMetrics = $this->monitorRepository->getMetrics($id, 'forward');

        // Parse current positions from latest snapshot
        $currentPositions = [];
        if ($latestSnapshot && !empty($latestSnapshot['positions'])) {
            $positions = json_decode($latestSnapshot['positions'], true);
            if ($positions) {
                foreach ($positions as $position) {
                    $currentPositions[] = $position;
                }
            }
        }

        // Parse strategy parameters for display
        $strategyParams = [];
        if (!empty($monitor['strategy_parameters'])) {
            $strategyParams = json_decode($monitor['strategy_parameters'], true) ?: [];
        }

        return $this->view->render($response, 'monitors/show.twig', [
            'monitor' => $monitor,
            'strategy_info' => $strategyInfo,
            'strategy_params' => $strategyParams,
            'ticker_symbols' => $tickerSymbols,
            'latest_snapshot' => $latestSnapshot,
            'snapshots' => array_reverse($snapshots), // Reverse for chronological order in chart
            'trades' => $trades,
            'backtest_metrics' => !empty($backtestMetrics) ? $backtestMetrics[0] : null,
            'forward_metrics' => !empty($forwardMetrics) ? $forwardMetrics[0] : null,
            'current_positions' => $currentPositions,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Stop a monitor
     *
     * POST /monitors/{id}/stop
     */
    public function stop(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $monitor = $this->monitorRepository->getMonitor($id);
            if ($monitor === null) {
                $this->flash->set('error', 'Monitor not found.');
            } else {
                $this->monitorRepository->updateStatus($id, 'stopped');
                $this->flash->set('success', "Monitor '{$monitor['name']}' stopped successfully.");
            }
        } catch (\Exception $e) {
            $this->flash->set('error', 'Failed to stop monitor: ' . $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/monitors/' . $id)
            ->withStatus(302);
    }

    /**
     * Reactivate a monitor
     *
     * POST /monitors/{id}/activate
     */
    public function activate(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $monitor = $this->monitorRepository->getMonitor($id);
            if ($monitor === null) {
                $this->flash->set('error', 'Monitor not found.');
            } else {
                $this->monitorRepository->updateStatus($id, 'active');
                $this->flash->set('success', "Monitor '{$monitor['name']}' activated successfully.");
            }
        } catch (\Exception $e) {
            $this->flash->set('error', 'Failed to activate monitor: ' . $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/monitors/' . $id)
            ->withStatus(302);
    }

    /**
     * Delete a monitor
     *
     * POST /monitors/{id}/delete
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $monitor = $this->monitorRepository->getMonitor($id);
            if ($monitor === null) {
                $this->flash->set('error', 'Monitor not found.');
            } else {
                $this->monitorRepository->deleteMonitor($id);
                $this->flash->set('success', "Monitor '{$monitor['name']}' deleted successfully.");
            }
        } catch (\Exception $e) {
            $this->flash->set('error', 'Failed to delete monitor: ' . $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/monitors')
            ->withStatus(302);
    }

    /**
     * Get backtest progress (AJAX endpoint)
     *
     * GET /monitors/{id}/progress
     */
    public function progress(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $monitor = $this->monitorRepository->getMonitor($id);

            if ($monitor === null) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Monitor not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'progress' => $monitor['backtest_progress'] ?? 0,
                'status' => $monitor['backtest_status'] ?? 'pending',
                'current_date' => $monitor['backtest_current_date'] ?? null,
                'error' => $monitor['backtest_error'] ?? null,
                'monitor_status' => $monitor['status']
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Trigger background backtest execution
     *
     * @param int $monitorId Monitor ID
     * @return void
     */
    private function triggerBackgroundBacktest(int $monitorId): void
    {
        $projectRoot = __DIR__ . '/../..';
        $command = sprintf(
            'php %s/commands/monitor-backtest.php %d > /dev/null 2>&1 &',
            $projectRoot,
            $monitorId
        );

        // Execute in background
        if (substr(php_uname(), 0, 7) == "Windows") {
            // Windows
            pclose(popen("start /B " . $command, "r"));
        } else {
            // Unix/Linux/Mac
            exec($command);
        }
    }

    /**
     * Extract strategy parameters using reflection
     *
     * @param string $strategyClass Full class name
     * @return array Array of parameter definitions
     */
    private function extractStrategyParameters(string $strategyClass): array
    {
        $parameters = [];

        try {
            // Ensure the class name has the full namespace
            $className = $strategyClass;
            if (strpos($className, '\\') === false) {
                $className = "SimpleTrader\\{$strategyClass}";
            }

            if (!class_exists($className)) {
                return $parameters;
            }

            $reflection = new \ReflectionClass($className);
            $defaultProps = $reflection->getDefaultProperties();
            $strategyParams = $defaultProps['strategyParameters'] ?? [];

            foreach ($strategyParams as $name => $value) {
                $parameters[] = [
                    'name' => $name,
                    'default' => $value,
                    'type' => is_int($value) ? 'integer' : (is_float($value) ? 'float' : 'string')
                ];
            }
        } catch (\Exception $e) {
            // Log error or handle gracefully
        }

        return $parameters;
    }
}

