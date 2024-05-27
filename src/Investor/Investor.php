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
use SimpleTrader\Exceptions\ShutdownException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\ShutdownScheduler;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Loggers\LoggerInterface;

class Investor
{
    protected ?LoggerInterface $logger = null;
    protected ?NotifierInterface $notifier = null;
    protected array $investmentsList = [];
    protected ?Carbon $now = null;
    protected float $equity = 0.0;
    protected ShutdownScheduler $scheduler;


    /**
     * @throws ShutdownException
     */
    public function __construct(protected string $stateFile, ?Carbon $now = null)
    {
        $this->now = null === $now ? Carbon::now() : $now;
        $this->scheduler = new ShutdownScheduler();
        $this->scheduler->registerShutdownEvent([$this, 'sendNotifications']);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setNotifier(NotifierInterface $notifier): void
    {
        $this->notifier = $notifier;
    }

    public function setEquity(float $equity): void
    {
        $this->equity = $equity;
    }

    /**
     * @throws StrategyException
     * @throws InvestorException
     */
    public function addInvestment(string $id, Investment $investment): void
    {
        if (isset($this->investmentsList[$id])) {
            throw new InvestorException('Investment already exists.');
        }
        if ($this->logger) {
            $investment->getStrategy()->setLogger($this->logger);
        }
        if ($this->equity && empty($investment->getStrategy()->getCapital())) {
            $investment->getStrategy()->setCapital($this->equity);
        }
        $this->investmentsList[$id] = $investment;
    }

    /**
     * @throws InvestorException
     * @throws NotYetImplementedException
     * @throws SortNotSupportedByDriverException
     * @throws LoaderException
     */
    public function updateSources(): void
    {
        /** @var Investment $investment */
        foreach ($this->investmentsList as $investment) {
            $strategy = $investment->getStrategy();
            $source = $investment->getSource();
            $this->logAndNotify("Executing strategy: " . get_class($strategy));

            $startDate = $this->now->copy()->subDays($strategy->getMaxLookbackPeriod());
            $this->logAndNotify('Need data from date: ' . $startDate->toDateString());

            $updateAssets = false;
            $newAssets = new Assets();
            $assets = $investment->getAssets();
            foreach ($assets->getAssets() as $tickerName => $tickerDf) {
                $latestEntry = $tickerDf->head(1);
                $latestDate = null;
                if (empty($latestEntry)) {
                    $daysToGet = (int) floor($startDate->diffInDays($this->now));
                } else {
                    $latestDate = Carbon::parse($latestEntry[0]['date']);
                    $daysToGet = (int) floor($latestDate > $startDate ? $latestDate->diffInDays($this->now) - 1 : $startDate->diffInDays($this->now));
                }
                $this->logAndNotify("Days to get for {$tickerName}: {$daysToGet}");

                if ($daysToGet > 0) {
                    $ohlcQuotes = $source->getQuotes($tickerName, $assets->getExchange($tickerName), '1D', $daysToGet);
                    if (empty($ohlcQuotes)) {
                        throw new InvestorException('Could not load any data from the source.');
                    }
                    $this->logAndNotify('Found ' . count($ohlcQuotes) . ' quotes');
                    /** @var Ohlc $quote */
                    foreach ($ohlcQuotes as $quote) {
                        if ($latestDate && $quote->getDateTime()->toDateString() <= $latestDate->toDateString()) {
                            continue;
                        }
                        $tickerDf->addRecord($quote->toArray());
                    }
                    $tickerDf = Assets::validateAndSortAsset($tickerDf, $tickerName);
                    CSV::fromDataFrame($tickerDf->sortRecordsByColumns('date'))->toFile($assets->getPath($tickerName), true);

                    $newAssets->addAsset(CSV::fromFilePath($assets->getPath($tickerName))->import(), $tickerName, false, $assets->getExchange($tickerName), $assets->getPath($tickerName));
                    $updateAssets = true;
                }
            }
            if ($updateAssets) {
                $investment->setAssets($newAssets);
            }
        }
    }

    /**
     * @throws \ReflectionException
     * @throws InvestorException
     */
    public function execute(Event $event): void
    {
        if ($this->notifier === null) {
            throw new InvestorException('Notifier is not set.');
        }

        /** @var Investment $investment */
        foreach ($this->investmentsList as $id => $investment) {
            $that = $this;
            $strategy = $investment->getStrategy();
            $currentPosition = $strategy->getCurrentPosition();
            $this->logAndNotify("Executing the '{$id}' investment, starting capital: {$strategy->getCapital(true)}.");
            $this->logAndNotify($currentPosition ? 'Open position: ' . $currentPosition->toString() : 'No open positions.');

            $onOpenExists = (new ReflectionMethod($strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $onCloseExists = (new ReflectionMethod($strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;
            $assets = $investment->getAssets();

            $strategy->setOnOpenEvent(function(Position $position) use ($that) {
                $that->logAndNotify('Open event: ' . $position->toString());
            });
            $strategy->setOnCloseEvent(function(Position $position) use ($that) {
                $that->logAndNotify('Close event: ' . $position->toString(true));
            });

            if ($event == Event::OnOpen && $onOpenExists) {
                $this->logAndNotify("OnOpen event triggered for {$this->now->toDateString()}.");
                $strategy->onOpen($assets, $this->now, true);
            }
            if ($event == Event::OnClose && $onCloseExists) {
                $this->logAndNotify("OnClose event triggered for {$this->now->toDateString()}.");
                $strategy->onClose($assets, $this->now, true);
            }
        }

        $this->notifier->notifyInfo("[$this->now] Investor script executed");

        // save current state
        $this->saveCurrentState();
    }

    /**
     * @throws StrategyException
     */
    public function loadCurrentState(): void
    {
        // load current state
        // - open trades
        // - trade log
        // - etc.
        $state = [];
        if (file_exists($this->stateFile)) {
            $stateData = file_get_contents($this->stateFile);
            if (!empty($stateData)) {
                $state = json_decode($stateData, true);
            }
        }
        $this->logAndNotify('Loading state for ' . count($state) . ' investments.');
        foreach ($state as $id => $investmentState) {
            if (isset($this->investmentsList[$id])) {
                /** @var Investment $investment */
                $investment = $this->investmentsList[$id];
                $strategy = $investment->getStrategy();
                if (!empty($investmentState['capital'])) {
                    $strategy->setCapital($investmentState['capital']);
                }
                if (!empty($investmentState['current_position'])) {
                    $strategy->setCurrentPosition(unserialize($investmentState['current_position']));
                }
                $strategy->setOpenTradesFromArray($investmentState['open_trades']);
                $strategy->setTradeLogFromArray($investmentState['trade_log']);
            }
        }
    }

    public function saveCurrentState(): void
    {
        $state = [];
        /** @var Investment $investment */
        foreach ($this->investmentsList as $id => $investment) {
            $strategy = $investment->getStrategy();
            $state[$id] = [
                'name' => get_class($strategy),
                'capital' => $strategy->getCapital(),
                'current_position' => $strategy->getCurrentPosition() ? serialize($strategy->getCurrentPosition()) : null,
                'open_trades' => $strategy->getOpenTradesAsArray(),
                'trade_log' => $strategy->getTradeLogAsArray(),
            ];
        }
        $this->logAndNotify('Saving state for ' . count($state) . ' investments.');
        file_put_contents($this->stateFile, json_encode($state));
    }

    public function logAndNotify($message, Level $level = Level::Info): void
    {
        $this->notifier->notify($level, $message);
        $this->logger?->log($level, $message);
    }

    public function sendNotifications()
    {
        $this->notifier->sendAllNotifications();
    }
}