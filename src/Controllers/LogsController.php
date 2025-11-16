<?php

namespace SimpleTrader\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SimpleTrader\Services\LogManager;

/**
 * Logs Controller
 *
 * Handles log viewing operations
 */
class LogsController
{
    private Twig $view;
    private LogManager $logManager;
    private $flash;

    public function __construct(Twig $view, LogManager $logManager, $flash)
    {
        $this->view = $view;
        $this->logManager = $logManager;
        $this->flash = $flash;
    }

    /**
     * Display logs index page with overview of all logs
     *
     * GET /logs
     */
    public function index(Request $request, Response $response): Response
    {
        $logDefinitions = $this->logManager->getLogDefinitions();
        $logStats = $this->logManager->getLogStatistics();

        return $this->view->render($response, 'logs/index.twig', [
            'logDefinitions' => $logDefinitions,
            'logStats' => $logStats,
            'active_menu' => 'logs',
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Display a specific log file with tail view
     *
     * GET /logs/{slug}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';

        $logInfo = $this->logManager->getLogInfo($slug);

        if ($logInfo === null) {
            $this->flash->set('error', 'Log file not found.');
            return $response
                ->withHeader('Location', '/logs')
                ->withStatus(302);
        }

        // Read last 1000 lines by default
        $logData = $this->logManager->readLogTail($slug, 1000, 0);

        return $this->view->render($response, 'logs/show.twig', [
            'logInfo' => $logInfo,
            'logData' => $logData,
            'logDefinitions' => $this->logManager->getLogDefinitions(),
            'currentSlug' => $slug,
            'active_menu' => 'logs',
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * AJAX endpoint to load more log lines
     *
     * GET /logs/{slug}/lines
     */
    public function getLines(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $queryParams = $request->getQueryParams();
        $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
        $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 1000;

        $logInfo = $this->logManager->getLogInfo($slug);

        if ($logInfo === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Log file not found'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $logData = $this->logManager->readLogTail($slug, $limit, $offset);

        $response->getBody()->write(json_encode([
            'success' => true,
            'lines' => $logData['lines'],
            'total_lines' => $logData['total_lines'],
            'has_more' => $logData['has_more'],
            'offset' => $logData['offset'],
            'limit' => $logData['limit'],
            'start_line' => $logData['start_line'] ?? 0,
            'end_line' => $logData['end_line'] ?? 0
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Clear a specific log file
     *
     * POST /logs/{slug}/clear
     */
    public function clear(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';

        $logInfo = $this->logManager->getLogInfo($slug);

        if ($logInfo === null) {
            $this->flash->set('error', 'Log file not found.');
            return $response
                ->withHeader('Location', '/logs')
                ->withStatus(302);
        }

        $success = $this->logManager->clearLog($slug);

        if ($success) {
            $this->flash->set('success', "Log '{$logInfo['title']}' cleared successfully.");
        } else {
            $this->flash->set('error', "Failed to clear log '{$logInfo['title']}'.");
        }

        return $response
            ->withHeader('Location', '/logs/' . $slug)
            ->withStatus(302);
    }

    /**
     * Refresh log statistics (AJAX)
     *
     * GET /logs/stats
     */
    public function stats(Request $request, Response $response): Response
    {
        $stats = $this->logManager->getLogStatistics();

        $response->getBody()->write(json_encode([
            'success' => true,
            'stats' => $stats
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
