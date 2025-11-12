<?php

namespace SimpleTrader\Services;

use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Loggers\LoggerInterface;

/**
 * Quote Update Service
 *
 * Handles daily updates of ticker quotes for all enabled tickers
 */
class QuoteUpdateService
{
    private TickerRepository $tickerRepository;
    private QuoteRepository $quoteRepository;
    private QuoteFetcher $quoteFetcher;
    private ?LoggerInterface $logger = null;

    public function __construct(
        TickerRepository $tickerRepository,
        QuoteRepository $quoteRepository,
        QuoteFetcher $quoteFetcher
    ) {
        $this->tickerRepository = $tickerRepository;
        $this->quoteRepository = $quoteRepository;
        $this->quoteFetcher = $quoteFetcher;
    }

    /**
     * Set logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Update all enabled tickers
     *
     * @return array ['success' => int, 'failed' => int, 'total' => int, 'errors' => array]
     */
    public function updateAllTickers(): array
    {
        $tickers = $this->tickerRepository->getEnabledTickers();
        $results = [
            'success' => 0,
            'failed' => 0,
            'total' => count($tickers),
            'errors' => [],
            'details' => []
        ];

        $this->log("Starting quote update for {$results['total']} tickers");

        foreach ($tickers as $ticker) {
            try {
                $this->log("Updating {$ticker['symbol']} (ID: {$ticker['id']})...");

                $result = $this->quoteFetcher->fetchQuotes($ticker['id']);

                if ($result['success']) {
                    $results['success']++;
                    $this->log("✓ {$ticker['symbol']}: Fetched {$result['count']} quotes");

                    $results['details'][] = [
                        'ticker' => $ticker['symbol'],
                        'status' => 'success',
                        'quotes_added' => $result['count'],
                        'message' => $result['message']
                    ];
                } else {
                    $results['failed']++;
                    $errorMsg = "✗ {$ticker['symbol']}: {$result['message']}";
                    $this->log($errorMsg, 'error');
                    $results['errors'][] = $errorMsg;

                    $results['details'][] = [
                        'ticker' => $ticker['symbol'],
                        'status' => 'failed',
                        'error' => $result['message']
                    ];
                }

            } catch (\Exception $e) {
                $results['failed']++;
                $errorMsg = "✗ {$ticker['symbol']}: Exception - " . $e->getMessage();
                $this->log($errorMsg, 'error');
                $results['errors'][] = $errorMsg;

                $results['details'][] = [
                    'ticker' => $ticker['symbol'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->log("Quote update completed: {$results['success']} succeeded, {$results['failed']} failed");

        return $results;
    }

    /**
     * Update specific ticker
     *
     * @param int $tickerId Ticker ID
     * @return array ['success' => bool, 'message' => string, 'count' => int]
     */
    public function updateTicker(int $tickerId): array
    {
        $ticker = $this->tickerRepository->getTicker($tickerId);

        if (!$ticker) {
            return [
                'success' => false,
                'message' => 'Ticker not found',
                'count' => 0
            ];
        }

        if (!$ticker['enabled']) {
            return [
                'success' => false,
                'message' => 'Ticker is disabled',
                'count' => 0
            ];
        }

        $this->log("Updating {$ticker['symbol']} (ID: {$tickerId})...");

        try {
            $result = $this->quoteFetcher->fetchQuotes($tickerId);

            if ($result['success']) {
                $this->log("✓ {$ticker['symbol']}: Fetched {$result['count']} quotes");
            } else {
                $this->log("✗ {$ticker['symbol']}: {$result['message']}", 'error');
            }

            return $result;

        } catch (\Exception $e) {
            $errorMsg = "Exception: " . $e->getMessage();
            $this->log("✗ {$ticker['symbol']}: {$errorMsg}", 'error');

            return [
                'success' => false,
                'message' => $errorMsg,
                'count' => 0
            ];
        }
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger) {
            switch ($level) {
                case 'error':
                    $this->logger->logError($message);
                    break;
                case 'warning':
                    $this->logger->logWarning($message);
                    break;
                case 'debug':
                    $this->logger->logDebug($message);
                    break;
                default:
                    $this->logger->logInfo($message);
            }
        }
    }
}
