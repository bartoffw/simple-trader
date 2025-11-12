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
This command fetches quotation data for a specific ticker from its configured data source.

Usage:
  php commands/fetch-quotes.php <ticker-id>
  php commands/fetch-quotes.php <ticker-id> <bar-count>

Examples:
  # Fetch quotes for ticker with ID 1 (auto-calculates missing days)
  php commands/fetch-quotes.php 1

  # Fetch last 100 bars for ticker with ID 1
  php commands/fetch-quotes.php 1 100

The command will:
1. Load ticker configuration from database
2. Determine how many bars to fetch (or use provided bar-count)
3. Connect to the configured data source
4. Fetch OHLCV data
5. Store quotes in the database
6. Display summary of fetched data
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
            $database = Database::getInstance($config['database']['path']);
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
