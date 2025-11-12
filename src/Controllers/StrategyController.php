<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Database\RunRepository;
use SimpleTrader\Database\TickerRepository;

/**
 * Strategy Controller
 *
 * Handles display and management of trading strategies
 */
class StrategyController
{
    private ContainerInterface $container;
    private Twig $view;
    private $flash;
    private RunRepository $runRepository;
    private TickerRepository $tickerRepository;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
        $this->runRepository = $container->get('runRepository');
        $this->tickerRepository = $container->get('tickerRepository');
    }

    /**
     * Display list of all available strategies
     *
     * GET /strategies
     */
    public function index(Request $request, Response $response): Response
    {
        // Get all available strategies
        $strategyClasses = StrategyDiscovery::getAvailableStrategies();

        // Get basic info for each strategy
        $strategies = [];
        foreach ($strategyClasses as $strategyClass) {
            $info = StrategyDiscovery::getStrategyInfo($strategyClass);
            if ($info) {
                $strategies[] = [
                    'class_name' => $info['class_name'],
                    'strategy_name' => $info['strategy_name'],
                    'strategy_description' => $info['strategy_description'] ?? null,
                    'overridden_count' => count($info['overridden_methods'])
                ];
            }
        }

        return $this->view->render($response, 'strategies/index.twig', [
            'strategies' => $strategies,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Display details of a specific strategy
     *
     * GET /strategies/{className}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];

        // Get strategy information
        $strategy = StrategyDiscovery::getStrategyInfo($className);

        if ($strategy === null) {
            // Strategy not found
            return $this->view->render($response->withStatus(404), 'error.twig', [
                'error_code' => '404',
                'error_message' => 'Strategy not found',
                'error_details' => "Strategy class '{$className}' does not exist or is not available."
            ]);
        }

        // Get all runs for this strategy
        $runs = $this->runRepository->getRunsByStrategy($strategy['full_class_name']);

        // Enhance runs with ticker information and formatted data
        $enhancedRuns = [];
        foreach ($runs as $run) {
            // Parse tickers (comma-separated IDs)
            $tickerIds = array_map('intval', explode(',', $run['tickers'] ?? ''));
            $tickerSymbols = [];
            foreach ($tickerIds as $tickerId) {
                $ticker = $this->tickerRepository->getTicker($tickerId);
                if ($ticker) {
                    $tickerSymbols[] = $ticker['symbol'];
                }
            }

            // Parse result metrics if available
            $netProfit = null;
            if (!empty($run['result_metrics'])) {
                $metrics = json_decode($run['result_metrics'], true);
                if (isset($metrics['net_profit'])) {
                    $netProfit = $metrics['net_profit'];
                }
            }

            $enhancedRuns[] = [
                'id' => $run['id'],
                'name' => $run['name'],
                'status' => $run['status'],
                'created_at' => $run['created_at'],
                'tickers' => implode(', ', $tickerSymbols),
                'net_profit' => $netProfit,
                'execution_time' => $run['execution_time_seconds'] ?? null,
                'start_date' => $run['start_date'],
                'end_date' => $run['end_date']
            ];
        }

        return $this->view->render($response, 'strategies/show.twig', [
            'strategy' => $strategy,
            'runs' => $enhancedRuns,
            'flash' => $this->flash->all()
        ]);
    }
}
