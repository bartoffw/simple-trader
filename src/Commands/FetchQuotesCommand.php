<?php

namespace SimpleTrader\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Services\QuoteFetcher;

/**
 * CLI Command to fetch quotes for a specific ticker
 */
class FetchQuotesCommand extends Command
{
    protected static $defaultName = 'quotes:fetch';
    protected static $defaultDescription = 'Fetch quotation data for a specific ticker from its configured source';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addArgument('ticker-id', InputArgument::REQUIRED, 'The ID of the ticker to fetch quotes for')
            ->addArgument('bar-count', InputArgument::OPTIONAL, 'Number of bars to fetch (default: auto-calculate missing days)', null)
            ->setHelp(<<<'HELP'
<info>Simple-Trader Quote Fetcher</info>
==============================

Fetches quotation data for a specific ticker from its configured data source.
Automatically detects missing dates and fetches only the required data.

<comment>USAGE:</comment>
  php commands/fetch-quotes.php <ticker-id> [bar-count]
  php commands/fetch-quotes.php --help

<comment>ARGUMENTS:</comment>
  ticker-id    The database ID of the ticker to fetch quotes for (required)
  bar-count    Number of bars/candles to fetch (optional)
               If not specified, automatically calculates missing days

<comment>EXAMPLES:</comment>

  1. Show help:
     <info>php commands/fetch-quotes.php --help</info>

  2. Fetch quotes for ticker with ID 1 (auto-detect missing dates):
     <info>php commands/fetch-quotes.php 1</info>

  3. Fetch last 100 bars for ticker with ID 1:
     <info>php commands/fetch-quotes.php 1 100</info>

  4. Fetch last 365 days of data (1 year):
     <info>php commands/fetch-quotes.php 1 365</info>

  5. Fetch all available historical data (5000 bars):
     <info>php commands/fetch-quotes.php 1 5000</info>

<comment>HOW IT WORKS:</comment>

  1. Loads ticker configuration from database (symbol, exchange, source)
  2. Checks existing quotes to determine date range
  3. Calculates missing days (if bar-count not specified)
  4. Connects to configured data source (e.g., Yahoo Finance, Interactive Brokers)
  5. Fetches OHLCV (Open, High, Low, Close, Volume) data
  6. Stores quotes in database
  7. Displays summary with date range and record count

<comment>AUTO-DETECTION:</comment>

  When bar-count is not specified, the command:
  - Checks the most recent quote date in database
  - Calculates business days between then and now
  - Fetches only the missing data
  - Adds a buffer of 5 extra days to ensure no gaps

<comment>DATA SOURCES:</comment>

  The ticker's configured source determines where data is fetched from:
  - yahoo: Yahoo Finance (free, most stocks)
  - ib: Interactive Brokers TWS (requires active connection)
  - csv: Local CSV file (for custom data)

<comment>EXIT CODES:</comment>
  0  Success - quotes fetched and stored
  1  Error - ticker not found, source unavailable, or fetch failed

<comment>NOTES:</comment>
  - Ticker must exist in database before fetching quotes
  - Use the web UI (Ticker Management) to add new tickers
  - For continuous updates, set up a cron job to run this command daily
  - Large bar-counts may take longer and hit API rate limits

<comment>MORE INFORMATION:</comment>
  See the web UI at /tickers for managing tickers and viewing quote data.

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get ticker ID from argument
        $tickerId = (int) $input->getArgument('ticker-id');
        $barCount = $input->getArgument('bar-count') ? (int) $input->getArgument('bar-count') : null;

        $io->title('Quote Fetcher');
        $io->text("Fetching quotes for ticker ID: <info>{$tickerId}</info>");

        if ($barCount !== null) {
            $io->text("Requested bar count: <info>{$barCount}</info>");
        } else {
            $io->text("Bar count: <info>auto-calculate missing days</info>");
        }

        $io->newLine();

        try {
            // Load configuration
            $config = require __DIR__ . '/../../config/config.php';

            // Initialize database and repositories
            $database = Database::getInstance($config['database']['tickers']);
            $tickerRepository = new TickerRepository($database);
            $quoteRepository = new QuoteRepository($database);

            // Get ticker details
            $ticker = $tickerRepository->getTicker($tickerId);
            if ($ticker === null) {
                $io->error("Ticker with ID {$tickerId} not found in database");
                return Command::FAILURE;
            }

            // Display ticker information
            $io->section('Ticker Information');
            $io->definitionList(
                ['Symbol' => $ticker['symbol']],
                ['Exchange' => $ticker['exchange']],
                ['Source' => $ticker['source']],
                ['Enabled' => $ticker['enabled'] ? 'Yes' : 'No']
            );

            // Get existing quote range
            $dateRange = $quoteRepository->getDateRange($tickerId);
            if ($dateRange !== null) {
                $io->section('Existing Quote Data');
                $io->definitionList(
                    ['First Quote' => $dateRange['first_date']],
                    ['Last Quote' => $dateRange['last_date']],
                    ['Total Quotes' => $dateRange['count']]
                );
            } else {
                $io->info('No existing quotes in database - will fetch all available data');
            }

            $io->newLine();

            // Fetch quotes
            $io->text('Fetching quotes from source...');
            $quoteFetcher = new QuoteFetcher($quoteRepository, $tickerRepository);
            $result = $quoteFetcher->fetchQuotes($tickerId, $barCount);

            $io->newLine();

            // Display results
            if ($result['success']) {
                $io->success($result['message']);

                if ($result['count'] > 0 && isset($result['date_range'])) {
                    $io->section('Updated Quote Data');
                    $io->definitionList(
                        ['First Quote' => $result['date_range']['first_date']],
                        ['Last Quote' => $result['date_range']['last_date']],
                        ['Total Quotes' => $result['date_range']['count']],
                        ['Newly Added' => $result['count']]
                    );
                }

                return Command::SUCCESS;
            } else {
                $io->error($result['message']);
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error([
                'An error occurred while fetching quotes:',
                $e->getMessage(),
                '',
                'File: ' . $e->getFile() . ':' . $e->getLine()
            ]);

            if ($output->isVerbose()) {
                $io->section('Stack Trace');
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
