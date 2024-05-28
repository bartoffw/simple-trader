<?php

namespace SimpleTrader;

use Carbon\Carbon;
use MammothPHP\WoollyM\DataFrame;
use ReflectionException;
use ReflectionMethod;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\OptimizationParam;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Loggers\LoggerInterface;

class Backtester
{
    protected ?LoggerInterface $logger = null;
    protected BaseStrategy $strategy;
    protected ?array $strategiesOptimized = null;
    protected Assets $assets;
    protected ?string $benchmarkTicker = null;
    protected ?Assets $benchmark = null;
    protected Carbon $backtestStartTime;
    protected ?Carbon $backtestEndTime;
    protected string $backtestStarted;
    protected string $backtestFinished;
    protected string $lastBacktestTime;


    public function __construct(protected Resolution $resolution)
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setStrategy(BaseStrategy $strategy): void
    {
        if (empty($strategy->getTickers())) {
            throw new BacktesterException('Strategy tickers not set.');
        }
        $this->strategy = $strategy;
    }

    public function setBenchmark(DataFrame $asset, string $ticker): void
    {
        $assets = new Assets();
        $assets->addAsset($asset, $ticker);
        $this->benchmark = $assets;
        $this->benchmarkTicker = $ticker;
    }

    public function getStrategy(): BaseStrategy
    {
        return $this->strategy;
    }

    public function getStrategyName(): string
    {
        return $this->strategy->getStrategyName();
    }

    public function getStrategies(): ?array
    {
        return $this->strategiesOptimized;
    }

    public function getBenchmarkTicker(): string
    {
        return $this->benchmarkTicker;
    }

    public function getBacktestStartTime(): Carbon
    {
        return $this->backtestStartTime;
    }

    public function getBacktestEndTime(): Carbon
    {
        return $this->backtestEndTime;
    }

    public function getLastBacktestTime(): string
    {
        return $this->lastBacktestTime;
    }

    public function getAssets(): Assets
    {
        return $this->assets;
    }

    protected function combinations(array $arrays): array
    {
        $result = [];
        $iterations = 1;
        foreach ($arrays as $paramValues) {
            $iterations *= count($paramValues);
        }
        uasort($arrays, function($a, $b) {
            return -(count($a) <=> count($b));
        });
        $limit = $iterations;
        foreach ($arrays as $paramName => $paramValues) {
            $limit /= count($paramValues);
            for ($i = 0; $i < $iterations; $i++) {
                $result[$i][$paramName] = $paramValues[floor($i / $limit) % count($paramValues)];
            }
        }
        return $result;
    }

    protected function createStrategyParams(array $optimizationParams): array
    {
        if (empty($optimizationParams)) {
            // just use the default parameters
            return $this->strategy->getParameters();
        } else {
            $paramsList = [];
            /** @var OptimizationParam $param */
            foreach ($optimizationParams as $param) {
                $paramsList[$param->getParamName()] = $param->getValues();
            }
            return $this->combinations($paramsList);
        }
    }

    /**
     * @throws ReflectionException
     * @throws LoaderException
     * @throws BacktesterException
     * @throws StrategyException
     */
    public function runBacktest(Assets $assets, Carbon $startTime, ?Carbon $endTime = null, array $strategyParameters = [],
                                array $optimizationParameters = []): void
    {
        if (!isset($this->strategy)) {
            throw new BacktesterException('Strategy is not set');
        }
        if ($assets->isEmpty()) {
            throw new BacktesterException('No assets defined');
        }
        if (empty($this->strategy->getCapital())) {
            throw new BacktesterException('No capital set');
        }
        $this->assets = $assets;
        $this->strategy->setLogger($this->logger);
        $this->strategy->setTickers($assets->getTickers());
        $this->strategy->setAssets($assets);
        $this->strategy->setStartDate($startTime);
        if ($this->benchmark) {
            $this->strategy->setBenchmark($this->benchmark->getAsset($this->benchmarkTicker), $this->benchmarkTicker);
        }
        $this->backtestStartTime = $startTime;
        $this->backtestEndTime = $endTime;
        $this->backtestStarted = microtime(true);
        if (!empty($strategyParameters)) {
            $this->strategy->setParameters($strategyParameters);
        }

        $backtestStartTime = $this->strategy->getStartDateForCalculations($this->assets, $startTime);

        $onOpenExists = (new ReflectionMethod($this->strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
        $onCloseExists = (new ReflectionMethod($this->strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;

        $this->logger?->logInfo('Starting the backtest. Start date: ' . $startTime->toDateString() . ', end date: ' . ($endTime ? $endTime->toDateString() : 'none'));

        if (empty($optimizationParameters)) {
            $this->runBacktestPass($backtestStartTime, $this->strategy, $onOpenExists, $onCloseExists, $startTime, $endTime);
        } else {
            $strategyParams = $this->createStrategyParams($optimizationParameters);
            $this->logger?->logInfo('Backtesting ' . count($strategyParams) . ' iterations');
            foreach ($strategyParams as $i => $paramGroup) {
                $this->logger?->logInfo('Running iteration #' . ($i + 1) . ', params: ' . implode(', ', $paramGroup));
                $strategy = clone $this->strategy;
                $startTimePass = clone $startTime;
                $strategy->setParameters($paramGroup);
                $this->runBacktestPass($backtestStartTime, $strategy, $onOpenExists, $onCloseExists, $startTimePass, $endTime);
                $this->strategiesOptimized[] = $strategy;
            }
        }

        $this->backtestFinished = microtime(true);
        $this->lastBacktestTime = $this->backtestFinished - $this->backtestStarted;
    }

    protected function runBacktestPass(Carbon $backtestStartTime, BaseStrategy $strategy, bool $onOpenExists, bool $onCloseExists,
                                       Carbon $startTime, ?Carbon $endTime = null): void
    {
        $currentDateTime = $startTime->copy();
        while ($endTime === null || $currentDateTime <= $endTime) {
            $this->logger?->logDebug('Backtest day: ' . $currentDateTime->toDateString());
            $currentAssets = $this->assets->cloneToDate($backtestStartTime, $currentDateTime);

            if ($onOpenExists) {
                $strategy->onOpen($currentAssets, $currentDateTime);
            }
            if ($onCloseExists) {
                $strategy->onClose($currentAssets, $currentDateTime);
            }
            unset($currentAssets);
            /** @var Position $position */
            foreach ($strategy->getOpenTrades() as $position) {
                $position->incrementOpenBars();
            }
            // TODO: add support for periods other than daily
            $currentDateTime->addDay();
        }
        $strategy->onStrategyEnd($this->assets->cloneToDate($backtestStartTime, $currentDateTime), $currentDateTime);
    }
}