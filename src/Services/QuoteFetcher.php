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

    // Maximum bars that can be fetched per request from most sources
    private const MAX_BARS_PER_REQUEST = 5000;

    public function __construct(QuoteRepository $quoteRepository, TickerRepository $tickerRepository)
    {
        $this->quoteRepository = $quoteRepository;
        $this->tickerRepository = $tickerRepository;
    }

    /**
     * Fetch and store quotes for a ticker
     *
     * Automatically handles fetching in chunks if more than MAX_BARS_PER_REQUEST bars are needed.
     * Continues fetching until all available data is retrieved.
     *
     * @param int $tickerId Ticker ID
     * @param int|null $barCount Number of bars to fetch (null = fetch all available)
     * @return array ['success' => bool, 'message' => string, 'count' => int, 'date_range' => array, 'fetches' => int]
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
            // If no quotes exist, fetch all available data (in chunks)
            // If quotes exist, calculate missing days
            $barCount = $dateRange === null ? null : $this->calculateMissingDays($dateRange['last_date']);
        }

        // If we have data and barCount is calculated as 0 or negative, no need to fetch
        if ($barCount !== null && $barCount <= 0) {
            return [
                'success' => true,
                'message' => 'Data is already up to date',
                'count' => 0,
                'date_range' => $dateRange,
                'fetches' => 0
            ];
        }

        try {
            // Create source instance
            $source = SourceDiscovery::createSourceInstance($ticker['source']);

            $totalInserted = 0;
            $fetchCount = 0;
            $continuesFetching = true;

            // If barCount is null, we fetch until we get less than MAX_BARS_PER_REQUEST
            // If barCount is set, we fetch in chunks until we have all bars
            while ($continuesFetching) {
                $fetchCount++;

                // Determine how many bars to request in this iteration
                if ($barCount === null) {
                    // Fetching all data - request max bars
                    $barsToRequest = self::MAX_BARS_PER_REQUEST;
                } else {
                    // Fetching specific amount - request remaining or max, whichever is smaller
                    $remainingBars = $barCount - $totalInserted;
                    $barsToRequest = min($remainingBars, self::MAX_BARS_PER_REQUEST);
                }

                // Fetch quotes from source
                $quotes = $source->getQuotes(
                    $ticker['symbol'],
                    $ticker['exchange'],
                    '1D', // Daily interval
                    $barsToRequest
                );

                if (empty($quotes)) {
                    // No more data available
                    if ($totalInserted === 0) {
                        return [
                            'success' => false,
                            'message' => "No quotes returned from source (requested {$barsToRequest} bars for {$ticker['symbol']} on {$ticker['exchange']})",
                            'count' => 0,
                            'date_range' => $dateRange,
                            'fetches' => $fetchCount
                        ];
                    }
                    break;
                }

                // Convert Ohlc objects to arrays, filtering out duplicates
                $quotesData = [];
                $currentDateRange = $this->quoteRepository->getDateRange($tickerId);
                $latestDate = $currentDateRange !== null ? $currentDateRange['last_date'] : null;

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

                // If no new quotes after filtering, stop
                if (empty($quotesData)) {
                    break;
                }

                // Store quotes in database (batch insert)
                $insertedCount = $this->quoteRepository->batchUpsertQuotes($tickerId, $quotesData);
                $totalInserted += $insertedCount;

                // Decide whether to continue fetching
                if ($barCount === null) {
                    // Fetching all data - continue if we got a full batch (means there might be more)
                    $continuesFetching = count($quotes) >= self::MAX_BARS_PER_REQUEST;
                } else {
                    // Fetching specific amount - continue if we haven't reached the target
                    $continuesFetching = $totalInserted < $barCount && count($quotes) >= $barsToRequest;
                }

                // Safety check: don't fetch more than 10 times (50,000 bars max)
                if ($fetchCount >= 10) {
                    break;
                }
            }

            // Get updated date range
            $updatedDateRange = $this->quoteRepository->getDateRange($tickerId);

            $message = "Successfully fetched and stored {$totalInserted} quote" . ($totalInserted !== 1 ? 's' : '');
            if ($fetchCount > 1) {
                $message .= " in {$fetchCount} fetches";
            }

            return [
                'success' => true,
                'message' => $message,
                'count' => $totalInserted,
                'date_range' => $updatedDateRange,
                'fetches' => $fetchCount
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching quotes: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')',
                'count' => 0,
                'date_range' => $dateRange,
                'fetches' => 0
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
