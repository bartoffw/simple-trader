<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SimpleTrader\Database\BacktestRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Services\BackgroundRunner;

/**
 * Runner Controller
 *
 * Manages backtest run creation, execution, and result viewing
 */
class RunnerController
{
    private ContainerInterface $container;
    private BacktestRepository $backtestRepository;
    private TickerRepository $tickerRepository;
    private Twig $view;
    private $flash;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->backtestRepository = new BacktestRepository($container->get('backtestsDb'));
        $this->tickerRepository = $container->get('tickerRepository');
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
    }

    /**
     * Display list of all runs
     *
     * GET /runs
     */
    public function index(Request $request, Response $response): Response
    {
        // Get all runs
        $runs = $this->backtestRepository->getAllBacktests();

        // Get statistics
        $stats = $this->backtestRepository->getStatistics();

        // Enhance runs with additional info
        foreach ($runs as &$run) {
            $run['tickers_decoded'] = json_decode($run['tickers'], true);
            $run['ticker_count'] = count($run['tickers_decoded']);

            // Get ticker symbols
            $symbols = [];
            foreach ($run['tickers_decoded'] as $tickerId) {
                $ticker = $this->tickerRepository->getTicker($tickerId);
                if ($ticker) {
                    $symbols[] = $ticker['symbol'];
                }
            }
            $run['ticker_symbols'] = implode(', ', $symbols);

            // Decode metrics if available
            if ($run['result_metrics']) {
                $run['metrics'] = json_decode($run['result_metrics'], true);
            }
        }

        return $this->view->render($response, 'backtests/index.twig', [
            'runs' => $runs,
            'stats' => $stats,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Show form to create new run
     *
     * GET /runs/create
     */
    public function create(Request $request, Response $response): Response
    {
        // Get available strategies
        $strategies = [];
        $strategyClasses = StrategyDiscovery::getAvailableStrategies();
        foreach ($strategyClasses as $strategyClass) {
            $info = StrategyDiscovery::getStrategyInfo($strategyClass);
            if ($info) {
                // Get strategy parameters for dynamic form
                $strategies[] = [
                    'class_name' => $info['class_name'],
                    'strategy_name' => $info['strategy_name'],
                    'parameters' => $this->extractStrategyParameters($info['class_name'])
                ];
            }
        }

        // Get available tickers (enabled only)
        $tickers = $this->tickerRepository->getAllTickers(true);

        return $this->view->render($response, 'backtests/create.twig', [
            'strategies' => $strategies,
            'tickers' => $tickers,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Store new run and start execution
     *
     * POST /runs
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Validate required fields
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'Run name is required';
        }
        if (empty($data['strategy_class'])) {
            $errors[] = 'Strategy is required';
        }
        if (empty($data['tickers']) || !is_array($data['tickers'])) {
            $errors[] = 'At least one ticker is required';
        }
        if (empty($data['start_date'])) {
            $errors[] = 'Start date is required';
        }
        if (empty($data['end_date'])) {
            $errors[] = 'End date is required';
        }
        if (empty($data['initial_capital'])) {
            $errors[] = 'Initial capital is required';
        }

        if (!empty($errors)) {
            $this->flash->set('error', implode(', ', $errors));
            return $response
                ->withHeader('Location', '/backtests/create')
                ->withStatus(302);
        }

        // Prepare strategy parameters
        $strategyParams = [];
        if (!empty($data['strategy_params'])) {
            foreach ($data['strategy_params'] as $key => $value) {
                if ($value !== '') {
                    $strategyParams[$key] = $value;
                }
            }
        }

        // Prepare optimization parameters
        $isOptimization = !empty($data['enable_optimization']);
        $optimizationParams = [];
        if ($isOptimization && !empty($data['optimization_params'])) {
            foreach ($data['optimization_params'] as $paramData) {
                if (!empty($paramData['name']) && $paramData['from'] !== '' && $paramData['to'] !== '' && $paramData['step'] !== '') {
                    $optimizationParams[] = [
                        'name' => $paramData['name'],
                        'from' => (float)$paramData['from'],
                        'to' => (float)$paramData['to'],
                        'step' => (float)$paramData['step']
                    ];
                }
            }
        }

        // Create run record
        $runData = [
            'name' => $data['name'],
            'strategy_class' => $data['strategy_class'],
            'strategy_parameters' => !empty($strategyParams) ? json_encode($strategyParams) : null,
            'tickers' => json_encode($data['tickers']),
            'benchmark_ticker_id' => !empty($data['benchmark_ticker_id']) ? (int)$data['benchmark_ticker_id'] : null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'initial_capital' => (float)$data['initial_capital'],
            'is_optimization' => $isOptimization ? 1 : 0,
            'optimization_params' => !empty($optimizationParams) ? json_encode($optimizationParams) : null,
            'status' => 'pending'
        ];

        $runId = $this->backtestRepository->createBacktest($runData);

        if ($runId === false) {
            $this->flash->set('error', 'Failed to create run');
            return $response
                ->withHeader('Location', '/backtests/create')
                ->withStatus(302);
        }

        // Start background execution
        $runner = new BackgroundRunner(__DIR__ . '/../..');
        $runner->startRun($runId);

        $this->flash->set('success', "Run #{$runId} started successfully!");

        return $response
            ->withHeader('Location', '/backtests/' . $runId)
            ->withStatus(302);
    }

    /**
     * Show run details and results
     *
     * GET /runs/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $run = $this->backtestRepository->getBacktest($id);

        if ($run === null) {
            return $this->view->render($response->withStatus(404), 'error.twig', [
                'error_code' => '404',
                'error_message' => 'Run not found',
                'error_details' => "Run with ID {$id} does not exist."
            ]);
        }

        // Decode JSON fields
        $run['tickers_decoded'] = json_decode($run['tickers'], true);
        $run['strategy_parameters_decoded'] = $run['strategy_parameters'] ? json_decode($run['strategy_parameters'], true) : [];
        $run['optimization_params_decoded'] = $run['optimization_params'] ? json_decode($run['optimization_params'], true) : [];
        $run['metrics'] = $run['result_metrics'] ? json_decode($run['result_metrics'], true) : null;

        // Get ticker info
        $tickers = [];
        foreach ($run['tickers_decoded'] as $tickerId) {
            $ticker = $this->tickerRepository->getTicker($tickerId);
            if ($ticker) {
                $tickers[] = $ticker;
            }
        }
        $run['tickers_info'] = $tickers;

        // Get benchmark info
        if ($run['benchmark_ticker_id']) {
            $run['benchmark_info'] = $this->tickerRepository->getTicker($run['benchmark_ticker_id']);
        }

        // Get strategy info
        $run['strategy_info'] = StrategyDiscovery::getStrategyInfo($run['strategy_class']);

        return $this->view->render($response, 'backtests/show.twig', [
            'run' => $run,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Get run logs (AJAX endpoint for polling)
     *
     * GET /runs/{id}/logs
     */
    public function logs(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $run = $this->backtestRepository->getBacktest($id);

        if ($run === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Run not found'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'status' => $run['status'],
            'log_output' => $run['log_output'] ?? '',
            'has_report' => !empty($run['report_html']),
            'metrics' => $run['result_metrics'] ? json_decode($run['result_metrics'], true) : null,
            'error_message' => $run['error_message']
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Download report as HTML file
     *
     * GET /runs/{id}/report
     */
    public function downloadReport(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $run = $this->backtestRepository->getBacktest($id);

        if ($run === null || empty($run['report_html'])) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($run['report_html']);

        $filename = 'backtest-report-' . $run['id'] . '-' . date('Y-m-d-His') . '.html';

        return $response
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Delete a run
     *
     * POST /runs/{id}/delete
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $run = $this->backtestRepository->getBacktest($id);

        if ($run === null) {
            $this->flash->set('error', 'Run not found');
            return $response
                ->withHeader('Location', '/backtests')
                ->withStatus(302);
        }

        // Don't delete running runs
        if ($run['status'] === 'running') {
            $this->flash->set('error', 'Cannot delete a running backtest');
            return $response
                ->withHeader('Location', '/backtests')
                ->withStatus(302);
        }

        if ($this->backtestRepository->deleteBacktest($id)) {
            $this->flash->set('success', "Run #{$id} deleted successfully");
        } else {
            $this->flash->set('error', 'Failed to delete run');
        }

        return $response
            ->withHeader('Location', '/backtests')
            ->withStatus(302);
    }

    /**
     * Manual restart endpoint
     *
     * POST /backtests/{id}/restart
     */
    public function restart(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $backtest = $this->backtestRepository->getBacktest($id);

        if ($backtest === null) {
            $this->flash->set('error', 'Backtest not found');
            return $response
                ->withHeader('Location', '/backtests')
                ->withStatus(302);
        }

        // Only restart pending or failed backtests
        if ($backtest['status'] !== 'pending' && $backtest['status'] !== 'failed') {
            $this->flash->set('error', 'Can only restart pending or failed backtests');
            return $response
                ->withHeader('Location', '/backtests/' . $id)
                ->withStatus(302);
        }

        // Add log message
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "\n[{$timestamp}] [MANUAL RESTART] User manually restarted the backtest\n";
        $this->backtestRepository->appendLog($id, $logMessage);

        // Reset status to pending if failed
        if ($backtest['status'] === 'failed') {
            $this->backtestRepository->updateStatus($id, 'pending');
        }

        // Restart
        $runner = new BackgroundRunner(__DIR__ . '/../..');
        $runner->startRun($id);

        $this->flash->set('success', 'Backtest restart initiated');

        return $response
            ->withHeader('Location', '/backtests/' . $id)
            ->withStatus(302);
    }

    /**
     * Health check endpoint - restart stalled backtests
     *
     * POST /backtests/health-check
     */
    public function healthCheck(Request $request, Response $response): Response
    {
        $runner = new BackgroundRunner(__DIR__ . '/../..');
        $stats = $runner->healthCheck($this->backtestRepository);

        $response->getBody()->write(json_encode([
            'success' => true,
            'stats' => $stats
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Extract strategy parameters with types and defaults
     */
    private function extractStrategyParameters(string $strategyClass): array
    {
        $className = StrategyDiscovery::getStrategyClassName($strategyClass);

        try {
            $reflection = new \ReflectionClass($className);

            // Try to get default parameters from property
            $defaultProps = $reflection->getDefaultProperties();
            $strategyParams = $defaultProps['strategyParameters'] ?? [];

            $params = [];
            foreach ($strategyParams as $name => $defaultValue) {
                $params[] = [
                    'name' => $name,
                    'default' => $defaultValue,
                    'type' => is_int($defaultValue) ? 'integer' : (is_float($defaultValue) ? 'float' : 'string')
                ];
            }

            return $params;
        } catch (\Exception $e) {
            return [];
        }
    }
}
