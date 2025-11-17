<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SimpleTrader\Database\BacktestRepository;
use Slim\Views\Twig;
use SimpleTrader\Helpers\StrategyDiscovery;
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
    private BacktestRepository $backtestRepository;
    private TickerRepository $tickerRepository;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
        $this->backtestRepository = $container->get('backtestRepository');
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

        // Get all backtests for this strategy
        $backtests = $this->backtestRepository->getBacktestsByStrategy($strategy['full_class_name']);

        // Enhance backtests with ticker information and formatted data
        $enhancedBacktests = [];
        foreach ($backtests as $backtest) {
            // Parse tickers (comma-separated IDs)
            $tickerIds = array_map('intval', explode(',', $backtest['tickers'] ?? ''));
            $tickerSymbols = [];
            foreach ($tickerIds as $tickerId) {
                $ticker = $this->tickerRepository->getTicker($tickerId);
                if ($ticker) {
                    $tickerSymbols[] = $ticker['symbol'];
                }
            }

            // Parse result metrics if available
            $netProfit = null;
            if (!empty($backtest['result_metrics'])) {
                $metrics = json_decode($backtest['result_metrics'], true);
                if (isset($metrics['net_profit'])) {
                    $netProfit = $metrics['net_profit'];
                }
            }

            $enhancedBacktests[] = [
                'id' => $backtest['id'],
                'name' => $backtest['name'],
                'status' => $backtest['status'],
                'created_at' => $backtest['created_at'],
                'tickers' => implode(', ', $tickerSymbols),
                'net_profit' => $netProfit,
                'execution_time' => $backtest['execution_time_seconds'] ?? null,
                'start_date' => $backtest['start_date'],
                'end_date' => $backtest['end_date']
            ];
        }

        return $this->view->render($response, 'strategies/show.twig', [
            'strategy' => $strategy,
            'backtests' => $enhancedBacktests,
            'flash' => $this->flash->all()
        ]);
    }
}
