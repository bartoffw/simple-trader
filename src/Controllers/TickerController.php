<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SimpleTrader\Database\TickerRepository;
use Slim\Views\Twig;

/**
 * Ticker Controller
 *
 * Handles all ticker management operations
 */
class TickerController
{
    private ContainerInterface $container;
    private TickerRepository $repository;
    private Twig $view;
    private $flash;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->repository = $container->get('tickerRepository');
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
    }

    /**
     * Display list of all tickers
     *
     * GET /tickers
     */
    public function index(Request $request, Response $response): Response
    {
        $tickers = $this->repository->getAllTickers();
        $stats = $this->repository->getStatistics();

        return $this->view->render($response, 'tickers/index.twig', [
            'tickers' => $tickers,
            'stats' => $stats,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Show create ticker form
     *
     * GET /tickers/create
     */
    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'tickers/create.twig', [
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Store a new ticker
     *
     * POST /tickers
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Validate input
        $errors = $this->repository->validateTickerData($data);

        if (!empty($errors)) {
            return $this->view->render($response->withStatus(400), 'tickers/create.twig', [
                'errors' => $errors,
                'old' => $data
            ]);
        }

        // Check if CSV file exists (optional warning)
        $csvWarning = null;
        if (!file_exists($data['csv_path'])) {
            $csvWarning = "Warning: CSV file does not exist yet at path: {$data['csv_path']}";
        }

        try {
            // Create ticker
            $tickerId = $this->repository->createTicker([
                'symbol' => $data['symbol'],
                'exchange' => $data['exchange'],
                'csv_path' => $data['csv_path'],
                'enabled' => isset($data['enabled']) && $data['enabled'] === '1'
            ]);

            $message = "Ticker '{$data['symbol']}' created successfully.";
            if ($csvWarning) {
                $message .= " " . $csvWarning;
            }

            $this->flash->set('success', $message);

            return $response
                ->withHeader('Location', '/tickers')
                ->withStatus(302);

        } catch (\RuntimeException $e) {
            // Handle duplicate or other runtime errors
            return $this->view->render($response->withStatus(400), 'tickers/create.twig', [
                'errors' => ['general' => $e->getMessage()],
                'old' => $data
            ]);
        } catch (\Exception $e) {
            // Handle unexpected errors
            return $this->view->render($response->withStatus(500), 'tickers/create.twig', [
                'errors' => ['general' => 'An unexpected error occurred: ' . $e->getMessage()],
                'old' => $data
            ]);
        }
    }

    /**
     * Show edit ticker form
     *
     * GET /tickers/{id}/edit
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $ticker = $this->repository->getTicker($id);

        if ($ticker === null) {
            $this->flash->set('error', 'Ticker not found.');
            return $response
                ->withHeader('Location', '/tickers')
                ->withStatus(302);
        }

        return $this->view->render($response, 'tickers/edit.twig', [
            'ticker' => $ticker,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Update an existing ticker
     *
     * POST /tickers/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        // Get existing ticker
        $ticker = $this->repository->getTicker($id);
        if ($ticker === null) {
            $this->flash->set('error', 'Ticker not found.');
            return $response
                ->withHeader('Location', '/tickers')
                ->withStatus(302);
        }

        // Validate input (for update)
        $errors = $this->repository->validateTickerData($data, true);

        if (!empty($errors)) {
            return $this->view->render($response->withStatus(400), 'tickers/edit.twig', [
                'ticker' => array_merge($ticker, $data),
                'errors' => $errors
            ]);
        }

        // Check if CSV file exists (optional warning)
        $csvWarning = null;
        if (isset($data['csv_path']) && !file_exists($data['csv_path'])) {
            $csvWarning = "Warning: CSV file does not exist at path: {$data['csv_path']}";
        }

        try {
            // Prepare update data
            $updateData = [];
            if (isset($data['symbol']) && !empty($data['symbol'])) {
                $updateData['symbol'] = $data['symbol'];
            }
            if (isset($data['exchange']) && !empty($data['exchange'])) {
                $updateData['exchange'] = $data['exchange'];
            }
            if (isset($data['csv_path']) && !empty($data['csv_path'])) {
                $updateData['csv_path'] = $data['csv_path'];
            }
            if (isset($data['enabled'])) {
                $updateData['enabled'] = $data['enabled'] === '1';
            }

            // Update ticker
            $this->repository->updateTicker($id, $updateData);

            $message = "Ticker '{$ticker['symbol']}' updated successfully.";
            if ($csvWarning) {
                $message .= " " . $csvWarning;
            }

            $this->flash->set('success', $message);

            return $response
                ->withHeader('Location', '/tickers')
                ->withStatus(302);

        } catch (\RuntimeException $e) {
            // Handle duplicate or other runtime errors
            return $this->view->render($response->withStatus(400), 'tickers/edit.twig', [
                'ticker' => array_merge($ticker, $data),
                'errors' => ['general' => $e->getMessage()]
            ]);
        } catch (\Exception $e) {
            // Handle unexpected errors
            return $this->view->render($response->withStatus(500), 'tickers/edit.twig', [
                'ticker' => array_merge($ticker, $data),
                'errors' => ['general' => 'An unexpected error occurred: ' . $e->getMessage()]
            ]);
        }
    }

    /**
     * Delete a ticker
     *
     * POST /tickers/{id}/delete
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $ticker = $this->repository->getTicker($id);
            if ($ticker === null) {
                $this->flash->set('error', 'Ticker not found.');
            } else {
                $this->repository->deleteTicker($id);
                $this->flash->set('success', "Ticker '{$ticker['symbol']}' deleted successfully.");
            }
        } catch (\Exception $e) {
            $this->flash->set('error', 'Failed to delete ticker: ' . $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/tickers')
            ->withStatus(302);
    }

    /**
     * Toggle ticker enabled status
     *
     * POST /tickers/{id}/toggle
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        try {
            $ticker = $this->repository->getTicker($id);
            if ($ticker === null) {
                $this->flash->set('error', 'Ticker not found.');
            } else {
                $newStatus = $this->repository->toggleEnabled($id);
                $statusText = $newStatus ? 'enabled' : 'disabled';
                $this->flash->set('success', "Ticker '{$ticker['symbol']}' {$statusText} successfully.");
            }
        } catch (\Exception $e) {
            $this->flash->set('error', 'Failed to toggle ticker: ' . $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/tickers')
            ->withStatus(302);
    }

    /**
     * Show ticker details (for future use)
     *
     * GET /tickers/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $ticker = $this->repository->getTicker($id);

        if ($ticker === null) {
            $this->flash->set('error', 'Ticker not found.');
            return $response
                ->withHeader('Location', '/tickers')
                ->withStatus(302);
        }

        // Get audit log for this ticker
        $auditLog = $this->repository->getAuditLog($id, 20);

        return $this->view->render($response, 'tickers/show.twig', [
            'ticker' => $ticker,
            'audit_log' => $auditLog,
            'flash' => $this->flash->all()
        ]);
    }
}
