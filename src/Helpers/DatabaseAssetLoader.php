<?php

namespace SimpleTrader\Helpers;

use MammothPHP\WoollyM\DataFrame;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Assets;

/**
 * Database Asset Loader
 *
 * Loads asset data from database into Assets object for backtesting
 */
class DatabaseAssetLoader
{
    private QuoteRepository $quoteRepository;
    private TickerRepository $tickerRepository;

    public function __construct(QuoteRepository $quoteRepository, TickerRepository $tickerRepository)
    {
        $this->quoteRepository = $quoteRepository;
        $this->tickerRepository = $tickerRepository;
    }

    /**
     * Load assets from database for specified ticker IDs
     *
     * @param array $tickerIds Array of ticker IDs to load
     * @param string|null $startDate Start date (Y-m-d format), null for all data
     * @param string|null $endDate End date (Y-m-d format), null for all data
     * @return Assets Assets object with loaded data
     */
    public function loadAssets(array $tickerIds, ?string $startDate = null, ?string $endDate = null): Assets
    {
        $assets = new Assets();

        foreach ($tickerIds as $tickerId) {
            $ticker = $this->tickerRepository->getTicker($tickerId);
            if ($ticker === null) {
                continue;
            }

            // Get quotes from database
            $quotes = $this->quoteRepository->getQuotes($tickerId, $startDate, $endDate);

            if (empty($quotes)) {
                continue;
            }

            // Convert to DataFrame format
            $dataFrame = $this->quotesToDataFrame($quotes);

            // Add to assets with ticker symbol and exchange
            $assets->addAsset($dataFrame, $ticker['symbol'], false, $ticker['exchange']);
        }

        return $assets;
    }

    /**
     * Load a single asset from database
     *
     * @param int $tickerId Ticker ID
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return DataFrame|null
     */
    public function loadSingleAsset(int $tickerId, ?string $startDate = null, ?string $endDate = null): ?DataFrame
    {
        $quotes = $this->quoteRepository->getQuotes($tickerId, $startDate, $endDate);

        if (empty($quotes)) {
            return null;
        }

        return $this->quotesToDataFrame($quotes);
    }

    /**
     * Convert quote array to DataFrame
     *
     * @param array $quotes Array of quote data from database
     * @return DataFrame
     */
    private function quotesToDataFrame(array $quotes): DataFrame
    {
        // Prepare data in the format DataFrame expects
        // DataFrame needs: date, open, high, low, close, volume
        $data = [];

        foreach ($quotes as $quote) {
            $data[] = [
                'date' => $quote['date'],
                'open' => (float)$quote['open'],
                'high' => (float)$quote['high'],
                'low' => (float)$quote['low'],
                'close' => (float)$quote['close'],
                'volume' => (float)($quote['volume'] ?? 0)
            ];
        }

        // Create DataFrame from array
        // Note: WoollyM DataFrame can be created from arrays
        return DataFrame::fromArray($data);
    }

    /**
     * Get ticker symbol by ID
     *
     * @param int $tickerId
     * @return string|null
     */
    public function getTickerSymbol(int $tickerId): ?string
    {
        $ticker = $this->tickerRepository->getTicker($tickerId);
        return $ticker ? $ticker['symbol'] : null;
    }

    /**
     * Get ticker exchange by ID
     *
     * @param int $tickerId
     * @return string|null
     */
    public function getTickerExchange(int $tickerId): ?string
    {
        $ticker = $this->tickerRepository->getTicker($tickerId);
        return $ticker ? $ticker['exchange'] : null;
    }
}
