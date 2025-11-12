<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SimpleTrader\Helpers\StrategyDiscovery;

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

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
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

        return $this->view->render($response, 'strategies/show.twig', [
            'strategy' => $strategy,
            'flash' => $this->flash->all()
        ]);
    }
}
