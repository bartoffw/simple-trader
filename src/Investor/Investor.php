<?php

namespace SimpleTrader\Investor;

use Carbon\Carbon;
use MammothPHP\WoollyM\DataDrivers\DriversExceptions\SortNotSupportedByDriverException;
use MammothPHP\WoollyM\Exceptions\NotYetImplementedException;
use MammothPHP\WoollyM\IO\CSV;
use ReflectionMethod;
use SimpleTrader\Assets;
use SimpleTrader\BaseStrategy;
use SimpleTrader\Event;
use SimpleTrader\Exceptions\InvestorException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Loggers\LoggerInterface;

class Investor
{
    protected ?LoggerInterface $logger = null;
    protected ?NotifierInterface $notifier = null;
    protected array $investmentsList = [];
    protected ?Carbon $now = null;


    public function __construct(protected string $stateFile, ?Carbon $now = null)
    {
        $this->now = null === $now ? Carbon::now() : $now;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setNotifier(NotifierInterface $notifier)
    {
        $this->notifier = $notifier;
    }

    public function addInvestment(string $id, Investment $investment)
    {
        if (isset($this->investmentsList[$id])) {
            throw new InvestorException('Investment already exists.');
        }
        $this->investmentsList[$id] = $investment;
    }

    /**
     * @throws InvestorException
     * @throws NotYetImplementedException
     * @throws SortNotSupportedByDriverException
     * @throws LoaderException
     */
    public function updateSources()
    {
        /** @var Investment $execution */
        foreach ($this->investmentsList as $execution) {
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
                $this->logger?->logInfo("Days to get for {$tickerName}: {$daysToGet}");

                if ($daysToGet > 0) {
                    $ohlcQuotes = $source->getQuotes($tickerName, $assets->getExchange($tickerName), '1D', $daysToGet);
                    if (empty($ohlcQuotes)) {
                        throw new InvestorException('Could not load any data from the source.');
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
     * @throws InvestorException
     */
    public function execute(Event $event)
    {
        if ($this->notifier === null) {
            throw new InvestorException('Notifier is not set.');
        }

        /** @var Investment $investment */
        foreach ($this->investmentsList as $id => $investment) {
            $strategy = $investment->getStrategy();
            $this->logger?->logInfo("Executing the '{$id}' investment, starting capital: {$strategy->getCapital(true)}.");

            $onOpenExists = (new ReflectionMethod($strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $onCloseExists = (new ReflectionMethod($strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $assets = $investment->getAssets();

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

        // save current state
        $this->saveCurrentState();
    }

    /**
     * @throws InvestorException
     * @throws StrategyException
     */
    public function loadCurrentState()
    {
        // load current state
        // - open trades
        // - trade log
        // - etc.
        $state = [];
        if (file_exists($this->stateFile)) {
            $stateData = file_get_contents($this->stateFile);
            if (empty($stateData)) {
                throw new InvestorException('Could not read state file.');
            }
            $state = json_decode($stateData, true);
        }
        $this->logger?->logInfo('Loading state for ' . count($state) . ' investments.');
        foreach ($state as $id => $investmentState) {
            if (isset($this->investmentsList[$id])) {
                /** @var Investment $investment */
                $investment = $this->investmentsList[$id];
                $strategy = $investment->getStrategy();
                if (!empty($investmentState['capital'])) {
                    $strategy->setCapital($investmentState['capital']);
                }
                $strategy->setOpenTradesFromArray($investmentState['open_trades']);
                $strategy->setTradeLogFromArray($investmentState['trade_log']);
            }
        }
    }

    public function saveCurrentState()
    {
        $state = [];
        /** @var Investment $investment */
        foreach ($this->investmentsList as $id => $investment) {
            $strategy = $investment->getStrategy();
            $state[$id] = [
                'name' => get_class($strategy),
                'capital' => $strategy->getCapital(),
                'open_trades' => $strategy->getOpenTradesAsArray(),
                'trade_log' => $strategy->getTradeLogAsArray(),
            ];
        }
        $this->logger?->logInfo('Saving state for ' . count($state) . ' investments.');
        file_put_contents($this->stateFile, json_encode($state));
    }
}