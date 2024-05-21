<?php

namespace SimpleTrader\Executor;

use Carbon\Carbon;
use MammothPHP\WoollyM\DataDrivers\DriversExceptions\SortNotSupportedByDriverException;
use MammothPHP\WoollyM\Exceptions\NotYetImplementedException;
use MammothPHP\WoollyM\IO\CSV;
use ReflectionMethod;
use SimpleTrader\Assets;
use SimpleTrader\BaseStrategy;
use SimpleTrader\Event;
use SimpleTrader\Exceptions\ExecutorException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Loggers\LoggerInterface;

class Executor
{
    protected ?LoggerInterface $logger = null;
    protected ?NotifierInterface $notifier = null;
    protected array $execList = [];
    protected ?Carbon $now = null;


    public function __construct(protected string $stateFile, ?Carbon $now = null)
    {
        $this->now = null === $now ? Carbon::now() : $now;
        // TODO: load current state
        // - open trades
        // - trade log
        // - etc.
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setNotifier(NotifierInterface $notifier)
    {
        $this->notifier = $notifier;
    }

    public function addExecution(Execution $execution)
    {
        $this->execList[] = $execution;
    }

    /**
     * @throws ExecutorException
     * @throws NotYetImplementedException
     * @throws SortNotSupportedByDriverException
     * @throws LoaderException
     */
    public function updateSources()
    {
        /** @var Execution $execution */
        foreach ($this->execList as $execution) {
            $strategy = $execution->getStrategy();
            $source = $execution->getSource();
            $this->logger?->logInfo("Executing strategy: " . get_class($strategy));

            $startDate = $this->now->copy()->subDays($strategy->getMaxLookbackPeriod());
            $this->logger?->logInfo('Need data from date: ' . $startDate->toDateString());

            //$tickerData = [];
            $assets = $execution->getAssets();
            foreach ($assets->getAssets() as $tickerName => $tickerDf) {
                $latestEntry = $tickerDf->head(1);
                $latestDate = null;
                if (empty($latestEntry)) {
                    $daysToGet = (int) floor($startDate->diffInDays($this->now));
                } else {
                    $latestDate = Carbon::parse($latestEntry[0]['date']);
                    $daysToGet = (int) floor($latestDate > $startDate ? $latestDate->diffInDays($this->now) - 1 : $startDate->diffInDays($this->now));
                }
                $this->logger?->logInfo('Days to get: ' . $daysToGet);

                if ($daysToGet > 0) {
                    $ohlcQuotes = $source->getQuotes($tickerName, $assets->getExchange($tickerName), '1D', $daysToGet);
                    if (empty($ohlcQuotes)) {
                        throw new ExecutorException('Could not load any data from the source.');
                    }
                    $this->logger?->logInfo('Found ' . count($ohlcQuotes) . ' quotes');
                    /** @var Ohlc $quote */
                    foreach ($ohlcQuotes as $quote) {
                        if ($latestDate && $quote->getDateTime()->toDateString() <= $latestDate->toDateString()) {
                            continue;
                        }
                        $tickerDf->addRecord($quote->toArray());
                    }
                    $tickerDf = Assets::validateAndSortAsset($tickerDf, $tickerName);
                    CSV::fromDataFrame($tickerDf->sortRecordsByColumns('date'))->toFile($assets->getPath($tickerName), true);
                }

                //$tickerData[$tickerName] = $tickerDf;
            }
        }
    }

    /**
     * @throws \ReflectionException
     * @throws ExecutorException
     */
    public function execute(Event $event)
    {
        if ($this->notifier === null) {
            throw new ExecutorException('Notifier is not set.');
        }

        /** @var Execution $execution */
        foreach ($this->execList as $execution) {
            $strategy = $execution->getStrategy();
            $onOpenExists = (new ReflectionMethod($strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $onCloseExists = (new ReflectionMethod($strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $assets = $execution->getAssets();

            $strategy->setOnOpenEvent(function(Position $position) {
                $this->logger?->logInfo('Open event: ' . print_r($position, true));
            });
            $strategy->setOnCloseEvent(function(Position $position) {
                $this->logger?->logInfo('Close event: ' . print_r($position, true));
            });

            if ($event == Event::OnOpen && $onOpenExists) {
                $strategy->onOpen($assets, $this->now);
            }
            if ($event == Event::OnClose && $onCloseExists) {
                $strategy->onClose($assets, $this->now);
            }
        }

        // TODO: save current state
    }
}