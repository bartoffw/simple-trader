<?php

namespace SimpleTrader\Services;

use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Helpers\SourceDiscovery;
use SimpleTrader\Loaders\SourceInterface;
use Carbon\Carbon;

/**
 * Quote Fetcher Service
 *
 * Fetches quotation data from sources and stores in database
 */
class QuoteFetcher
{
    private QuoteRepository $quoteRepository;
    private TickerRepository $tickerRepository;

    public function __construct(QuoteRepository $quoteRepository, TickerRepository $tickerRepository)
    {
        $this->quoteRepository = $quoteRepository;
        $this->tickerRepository = $tickerRepository;
    }

    /**
     * Fetch and store quotes for a ticker
     *
     * @param int $tickerId Ticker ID
     * @param int|null $barCount Number of bars to fetch (null = fetch all available)
     * @return array ['success' => bool, 'message' => string, 'count' => int, 'date_range' => array]
     */
    public function fetchQuotes(int $tickerId, ?int $barCount = null): array
    {
        // Get ticker details
        $ticker = $this->tickerRepository->getTicker($tickerId);
        if ($ticker === null) {
            return [
                'success' => false,
                'message' => 'Ticker not found',
                'count' => 0
            ];
        }

        // Get existing date range
        $dateRange = $this->quoteRepository->getDateRange($tickerId);

        // Determine how many bars to fetch
        if ($barCount === null) {
            // If no quotes exist, fetch maximum available (e.g., 5000 bars ~ 20 years of daily data)
            $barCount = $dateRange === null ? 5000 : $this->calculateMissingDays($dateRange['last_date']);
        }

        // If we have data and barCount is calculated as 0 or negative, no need to fetch
        if ($barCount <= 0) {
            return [
                'success' => true,
                'message' => 'Data is already up to date',
                'count' => 0,
                'date_range' => $dateRange
            ];
        }

        try {
            // Create source instance
            $source = SourceDiscovery::createSourceInstance($ticker['source']);

            // Fetch quotes from source
            // Note: Following the same pattern as Investor::updateSources()
            $quotes = $source->getQuotes(
                $ticker['symbol'],
                $ticker['exchange'],
                '1D', // Daily interval
                $barCount
            );

            if (empty($quotes)) {
                return [
                    'success' => false,
                    'message' => "No quotes returned from source (requested {$barCount} bars for {$ticker['symbol']} on {$ticker['exchange']})",
                    'count' => 0,
                    'date_range' => $dateRange
                ];
            }

            // Convert Ohlc objects to arrays, filtering out duplicates
            $quotesData = [];
            $latestDate = $dateRange !== null ? $dateRange['last_date'] : null;

            foreach ($quotes as $quote) {
                /** @var \SimpleTrader\Helpers\Ohlc $quote */
                $quoteDate = $quote->getDateTime()->toDateString();

                // Skip quotes that are older than or equal to our latest date
                if ($latestDate !== null && $quoteDate <= $latestDate) {
                    continue;
                }

                $quotesData[] = [
                    'date' => $quoteDate,
                    'open' => (string)$quote->getOpen(),
                    'high' => (string)$quote->getHigh(),
                    'low' => (string)$quote->getLow(),
                    'close' => (string)$quote->getClose(),
                    'volume' => $quote->getVolume() ?? 0
                ];
            }

            // If after filtering we have no new quotes
            if (empty($quotesData)) {
                return [
                    'success' => true,
                    'message' => "Received {$barCount} bars from source, but all were duplicates (already in database)",
                    'count' => 0,
                    'date_range' => $dateRange
                ];
            }

            // Store quotes in database (batch insert)
            $insertedCount = $this->quoteRepository->batchUpsertQuotes($tickerId, $quotesData);

            // Get updated date range
            $updatedDateRange = $this->quoteRepository->getDateRange($tickerId);

            return [
                'success' => true,
                'message' => "Successfully fetched and stored {$insertedCount} quote" . ($insertedCount !== 1 ? 's' : ''),
                'count' => $insertedCount,
                'date_range' => $updatedDateRange
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching quotes: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')',
                'count' => 0,
                'date_range' => $dateRange
            ];
        }
    }

    /**
     * Calculate number of missing days since last quote date
     *
     * @param string $lastDate Last quote date (Y-m-d)
     * @return int Number of days to fetch (0 if up to date)
     */
    private function calculateMissingDays(string $lastDate): int
    {
        $lastDateCarbon = Carbon::parse($lastDate);
        $today = Carbon::today();

        // Calculate business days between last date and today
        $daysDiff = $lastDateCarbon->diffInDays($today);

        // If last date is today or in the future, no need to fetch
        if ($daysDiff <= 0) {
            return 0;
        }

        // Add a buffer (fetch a bit more to account for weekends/holidays)
        // For daily data, fetch daysDiff + 10 to ensure we get all missing data
        return min($daysDiff + 10, 365); // Cap at 365 days for incremental updates
    }

    /**
     * Re-fetch all quotes for a ticker (deletes existing data first)
     *
     * @param int $tickerId Ticker ID
     * @param int $barCount Number of bars to fetch
     * @return array Result array
     */
    public function refetchAllQuotes(int $tickerId, int $barCount = 5000): array
    {
        // Delete existing quotes
        $this->quoteRepository->deleteQuotes($tickerId);

        // Fetch fresh data
        return $this->fetchQuotes($tickerId, $barCount);
    }
}
