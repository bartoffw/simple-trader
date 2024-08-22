<?php

namespace SimpleTrader;

use Carbon\Carbon;
use Closure;
use MammothPHP\WoollyM\DataFrame;
use MammothPHP\WoollyM\Exceptions\NotYetImplementedException;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\QuantityType;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Investor\NotifierInterface;
use SimpleTrader\Loggers\LoggerInterface;

class BaseStrategy
{
    protected string $strategyName = 'Base Strategy';
    protected ?LoggerInterface $logger = null;
    protected NotifierInterface $notifier;
    protected Carbon $startDate;
    protected Carbon $currentDateTime;
    protected Assets $currentAssets;
    protected ?Event $currentEvent = null;
    protected array $strategyParameters = [];
    protected ?array $optimizationParameters = null;
    protected array $currentPositions = [];
    protected array $currentAssetValues = [];

    protected ?Closure $onOpenEvent = null;
    protected ?Closure $onCloseEvent = null;

    protected array $tickers = [];
    protected array $tradeLog = [];
    protected array $openTrades = [];
    protected float $openPositionSize = 0;

    protected ?float $initialCapital = null;
    protected ?float $capital = null;
    protected ?float $capitalAvailable = null;

    protected float $grossProfit = 0.00;
    protected float $grossProfitLongs = 0.00;
    protected float $grossProfitShorts = 0.00;

    protected float $grossLoss = 0.00;
    protected float $grossLossLongs = 0.00;
    protected float $grossLossShorts = 0.00;

    protected int $profitableTransactions = 0;
    protected int $profitableTransactionsLongs = 0;
    protected int $profitableTransactionsShorts = 0;

    protected int $losingTransactions = 0;
    protected int $losingTransactionsLongs = 0;
    protected int $losingTransactionsShorts = 0;

    protected int $barsProfitableTransactions = 0;
    protected int $barsProfitableTransactionsLongs = 0;
    protected int $barsProfitableTransactionsShorts = 0;

    protected int $barsLosingTransactions = 0;
    protected int $barsLosingTransactionsLongs = 0;
    protected int $barsLosingTransactionsShorts = 0;

    protected float $maxProfitableTransaction = 0.00;
    protected float $maxProfitableTransactionLongs = 0.00;
    protected float $maxProfitableTransactionShorts = 0.00;

    protected float $maxLosingTransaction = 0.00;
    protected float $maxLosingTransactionLongs = 0.00;
    protected float $maxLosingTransactionShorts = 0.00;

    protected float $maxQuantityLongs = 0.00;
    protected float $maxQuantityShorts = 0.00;
    protected float $maxStrategyDrawdownValue = 0.00;
    protected float $maxStrategyDrawdownPercent = 0.00;
    protected float $maxPositionDrawdownValue = 0.00;
    protected float $maxPositionDrawdownPercent = 0.00;
    protected int $maxBarsInDrawdown = 0;
    protected float $peakValue = 0.00;
    protected ?Carbon $lastPeakDate = null;
    protected array $capitalLog = [];
    protected array $positionDrawdownLog = [];

    protected Assets $assets;
    protected ?string $benchmarkTicker = null;
    protected ?Assets $benchmark = null;

    protected int $precision = 2;

    protected array $ignoreAttributes = [
        'ignoreAttributes',
        'logger',
        'notifier',
        'assets',
        'currentAssets',
        'benchmark',
        'currentPositions',
        'onOpenEvent',
        'onCloseEvent',
        'strategyParameters',
        'optimizationParameters',
        'tickers'
    ];


    public function __construct(protected QuantityType $qtyType = QuantityType::Percent, array $paramsOverrides = [])
    {
        if (!empty($paramsOverrides)) {
            $this->strategyParameters = array_merge($this->strategyParameters, $paramsOverrides);
        }
    }

    public function getStrategyName(): string
    {
        return $this->strategyName;
    }

    public function setNotifier(NotifierInterface $notifier): void
    {
        $this->notifier = $notifier;
    }

    public function setLogger(LoggerInterface $logger, bool $override = false): void
    {
        if ($override || $this->logger === null) {
            $this->logger = $logger;
        }
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function setOnOpenEvent(Closure $callback): void
    {
        $this->onOpenEvent = $callback;
    }

    public function setOnCloseEvent(Closure $callback): void
    {
        $this->onCloseEvent = $callback;
    }

    public function setStartDate(Carbon $dateTime): void
    {
        $this->startDate = $dateTime;
    }

    public function getStartDate(): Carbon
    {
        return $this->startDate;
    }

    /**
     * @throws StrategyException
     */
    public function setCurrentPositions(array $positions): void
    {
        if ($this->currentPositions !== []) {
            throw new StrategyException('Current positions already set.');
        }
        $this->currentPositions = $positions;
    }

    public function getCurrentPositions(): array
    {
        return $this->currentPositions;
    }

    public function getCurrentAssetValues(): array
    {
        return $this->currentAssetValues;
    }

    public function getMaxLookbackPeriod(): int
    {
        return 0;
    }

    public function getParameters($formatted = false): array
    {
        if ($formatted) {
            $result = [];
            array_walk($this->strategyParameters, function($value, $key) use (&$result) {
                $result[] = "{$key}: {$value}";
            });
            return $result;
        }
        return $this->strategyParameters;
    }

    public function getOptimizationParameters(): ?array
    {
        return $this->optimizationParameters;
    }

    /**
     * @throws StrategyException
     */
    public function setParameters(array $newParameters): void
    {
        $this->optimizationParameters = $newParameters;
        foreach ($newParameters as $name => $value) {
            if (isset($this->strategyParameters[$name])) {
                $this->strategyParameters[$name] = $value;
            } else {
                throw new StrategyException('Invalid parameter name: ' . $name);
            }
        }
    }

    public function setBenchmark(DataFrame $asset, string $ticker): void
    {
        $assets = new Assets();
        $assets->addAsset($asset, $ticker);
        $this->benchmark = $assets;
        $this->benchmarkTicker = $ticker;
    }

    public function setTickers(array $tickers): void
    {
        $this->tickers = $tickers;
    }

    public function getTickers(): array
    {
        return $this->tickers;
    }

    public function setAssets(Assets $assets): void
    {
        $this->assets = $assets;
    }

    /**
     * @throws NotYetImplementedException
     */
    public function getStartDateForCalculations(Assets $assets, Carbon $startDate): Carbon
    {
        $oldestDate = null;
        foreach ($this->tickers as $ticker) {
            $asset = $assets->getAsset($ticker);
            $record = $asset
                ->select('date')
                ->where(fn($record, $recordKey) => (new Carbon($record['date'])) <= $startDate)
                ->limit(1)
                ->offset($this->getMaxLookbackPeriod())
                ->toArray();
            if (!empty($record)) {
                $date = array_values($record)[0]['date'];
                if ($oldestDate === null || $date < $oldestDate) {
                    $oldestDate = $date;
                }
            }
        }
        return $oldestDate ? new Carbon($oldestDate) : $startDate;
    }

    public function getPrecision(): int
    {
        return $this->precision;
    }

    /**
     * @throws StrategyException
     */
    public function setCapital(float $capital, int $precision = 2): void
    {
        if (!empty($this->capital)) {
            throw new StrategyException('Capital already set to: ' . $this->capital);
        }
        $this->precision = $precision;
        $this->capital = $capital;
        $this->capitalAvailable = $capital;
        $this->initialCapital = $capital;
//        bcscale($precision);
    }

    public function getCapital($formatted = false): ?float
    {
        return $formatted ? number_format($this->capital, $this->precision) : round($this->capital, $this->precision);
    }

    public function getInitialCapital(): ?float
    {
        return $this->initialCapital;
    }

    /**
     * @throws StrategyException
     */
    public function getOpenProfitPercent(Position $position): float
    {
        if ($this->currentEvent === null) {
            throw new StrategyException('Current event not known. Are you calling parent onOpen/onClose/onStrategyEnd in your strategy?');
        }
        if (!$this->currentAssets->hasAsset($position->getTicker())) {
            throw new StrategyException('Asset not found in the asset list for an open position: ' . $position->getTicker());
        }
        $currentPrice = $this->currentAssets->getCurrentValue($position->getTicker(), $this->currentDateTime, $this->currentEvent);
        $calculatedSize = $this->calculatePositionSize($currentPrice, $position->getQuantity(), QuantityType::Units);
        $position->updatePosition($currentPrice, $calculatedSize);
        return $position->getProfitPercent();
    }

    public function onOpen(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        $this->currentEvent = Event::OnOpen;
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
        $this->updateDrawdown();
    }

    public function onClose(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        $this->currentEvent = Event::OnClose;
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
        $this->updateDrawdown();
    }

    public function onStrategyEnd(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        $this->currentEvent = Event::OnClose;
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
        $this->updateDrawdown();
    }

    /**
     * @throws StrategyException
     */
    public function entry(Side $side, string $ticker, string $positionSize = '100', string $comment = ''): Position
    {
        if ($this->currentEvent === null) {
            throw new StrategyException('Current event not known. Are you calling parent onOpen/onClose/onStrategyEnd in your strategy?');
        }
        if (!$this->currentAssets->hasAsset($ticker)) {
            throw new StrategyException('Asset not found in the asset list for an open position: ' . $ticker);
        }
        $currentPrice = $this->currentAssets->getCurrentValue($ticker, $this->currentDateTime, $this->currentEvent);
        if (empty($currentPrice)) {
            throw new StrategyException("Price ($currentPrice) cannot be empty or zero");
        }
        $calculatedSize = $this->calculatePositionSize($currentPrice, $positionSize, $this->qtyType);
        if ($calculatedSize > $this->capitalAvailable + 0.00001) {
            //throw new StrategyException("Position size ($calculatedSize) is greater than the available capital ($this->capitalAvailable)");
            $this->logger?->logWarning("Position size ($calculatedSize) is greater than the available capital ($this->capitalAvailable), reducing");
            $calculatedSize = $this->capitalAvailable;
        }
        $calculatedQty = $calculatedSize / $currentPrice;

        $position = new Position($this->currentDateTime, $side, $ticker, $currentPrice, $calculatedQty, $calculatedSize, $comment);
        $this->openTrades[$position->getId()] = $position;
        $this->tradeLog[$position->getId()] = $position;

        $this->openPositionSize += $calculatedSize;
        $this->capitalAvailable -= $calculatedSize;

        $this->logger?->logInfo(
            '[' . $this->currentDateTime->toDateString() . ']' . $position->toString() . ', equity: ' . $this->getCapital()
        );
        $this->onOpenEvent?->call($this, $position);

        return $position;
    }

    /**
     * @throws StrategyException
     */
    public function close(string $positionId, string $comment = '')
    {
        if ($this->currentEvent === null) {
            throw new StrategyException('Current event not known. Are you calling parent onOpen/onClose/onStrategyEnd in your strategy?');
        }
        if (!isset($this->openTrades[$positionId])) {
            throw new StrategyException("Position ($positionId) not found in open positions.");
        }
        $position = $this->openTrades[$positionId];
        if (!$this->currentAssets->hasAsset($position->getTicker())) {
            throw new StrategyException('Asset not found in the asset list for an open position: ' . $position->getTicker());
        }

        $calculatedSize = $this->closePosition($position, $comment);

        $this->openTrades[$positionId] = null;
        unset($this->openTrades[$positionId]);

        $this->openPositionSize -= $calculatedSize;
        $this->capitalAvailable += $calculatedSize;
    }

    /**
     * @throws StrategyException
     */
    public function closeAll(string $comment = ''): void
    {
        if ($this->currentEvent === null) {
            throw new StrategyException('Current event not known. Are you calling parent onOpen/onClose/onStrategyEnd in your strategy?');
        }
        /** @var Position $position */
        foreach ($this->openTrades as $position) {
            if (!$this->currentAssets->hasAsset($position->getTicker())) {
                throw new StrategyException('Asset not found in the asset list for an open position: ' . $position->getTicker());
            }
            $this->closePosition($position, $comment);
        }
        $this->openTrades = [];
        $this->openPositionSize = 0;
        $this->capitalAvailable = $this->capital;
    }

    public function getOpenPosition(string $positionId): ?Position
    {
        if (array_key_exists($positionId, $this->openTrades)) {
            return $this->openTrades[$positionId];
        }
        return null;
    }

    public function hasOpenTrades()
    {
        return !empty($this->openTrades);
    }

    public function getOpenTrades(): array
    {
        return $this->openTrades;
    }

    public function setOpenTradesFromArray(array $openTrades): void
    {
        foreach ($openTrades as $id => $position) {
            $this->openTrades[$id] = unserialize($position);
        }
    }

    public function getOpenTradesAsArray(): array
    {
        $result = [];
        /** @var Position $position */
        foreach ($this->openTrades as $position) {
            $result[$position->getId()] = serialize($position);
        }
        return $result;
    }

    public function getGrossProfit(): float
    {
        return $this->grossProfit;
    }

    public function getGrossLoss(): float
    {
        return $this->grossLoss;
    }

    public function getGrossProfitLongs(): float
    {
        return $this->grossProfitLongs;
    }

    public function getGrossProfitShorts(): float
    {
        return $this->grossProfitShorts;
    }

    public function getGrossLossLongs(): float
    {
        return $this->grossLossLongs;
    }

    public function getGrossLossShorts(): float
    {
        return $this->grossLossShorts;
    }

    public function getNetProfitLongs(): float
    {
        return $this->grossProfitLongs - $this->grossLossLongs;
    }

    public function getNetProfitShorts(): float
    {
        return $this->grossProfitShorts - $this->grossLossShorts;
    }

    public function getProfitFactor(): float
    {
        return $this->grossLoss > 0.00001 ? $this->grossProfit / $this->grossLoss : 0.00;
    }

    public function getProfitFactorLongs(): float
    {
        return $this->grossLossLongs > 0.00001 ? $this->grossProfitLongs / $this->grossLossLongs : 0.00;
    }

    public function getProfitFactorShorts(): float
    {
        return $this->grossLossShorts > 0.00001 ? $this->grossProfitShorts / $this->grossLossShorts : 0.00;
    }

    public function getProfitableTransactions(): int
    {
        return $this->profitableTransactions;
    }

    public function getProfitableTransactionsLongs(): int
    {
        return $this->profitableTransactionsLongs;
    }

    public function getProfitableTransactionsShorts(): int
    {
        return $this->profitableTransactionsShorts;
    }

    public function getLosingTransactions(): int
    {
        return $this->losingTransactions;
    }

    public function getLosingTransactionsLongs(): int
    {
        return $this->losingTransactionsLongs;
    }

    public function getLosingTransactionsShorts(): int
    {
        return $this->losingTransactionsShorts;
    }

    public function getAvgProfitableTransaction(): float
    {
        return $this->profitableTransactions > 0 ? $this->grossProfit / (float)$this->profitableTransactions : 0.00;
    }

    public function getAvgProfitableTransactionLongs(): float
    {
        return $this->profitableTransactionsLongs > 0 ? $this->grossProfitLongs / (float)$this->profitableTransactionsLongs : 0.00;
    }

    public function getAvgProfitableTransactionShorts(): float
    {
        return $this->profitableTransactionsShorts > 0 ? $this->grossProfitShorts / (float)$this->profitableTransactionsShorts : 0.00;
    }

    public function getAvgLosingTransaction(): float
    {
        return $this->losingTransactions > 0 ? $this->grossLoss / (float)$this->losingTransactions : 0.00;
    }

    public function getAvgLosingTransactionLongs(): float
    {
        return $this->losingTransactionsLongs > 0 ? $this->grossLossLongs / (float)$this->losingTransactionsLongs : 0.00;
    }

    public function getAvgLosingTransactionShorts(): float
    {
        return $this->losingTransactionsShorts > 0 ? $this->grossLossShorts / (float)$this->losingTransactionsShorts : 0.00;
    }

    public function getProfit(): float
    {
        return $this->capital - $this->initialCapital;
        //Calculator::calculate('$1 - $2', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getProfitPercent(): float
    {
        return $this->capital * 100 / $this->initialCapital - 100;
        //Calculator::calculate('$1 * 100 / $2 - 100', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getAvgProfit(): float
    {
        $transactionCount = $this->profitableTransactions + $this->losingTransactions;
        return $transactionCount > 0 ? $this->getNetProfitLongs() / (float)$transactionCount : 0.00;
        //$transactionCount > 0 ? Calculator::calculate('$1 / $2', $profit, $transactionCount) : '0';
    }

    public function getAvgProfitLongs(): float
    {
        $transactionCount = $this->profitableTransactionsLongs + $this->losingTransactionsLongs;
        return $transactionCount > 0 ? $this->getNetProfitLongs() / (float)$transactionCount : 0.00;
        //$transactionCount > 0 ? Calculator::calculate('$1 / $2', $profit, $transactionCount) : '0';
    }

    public function getAvgProfitShorts(): float
    {
        $transactionCount = $this->profitableTransactionsShorts + $this->losingTransactionsShorts;
        return $transactionCount > 0 ? $this->getNetProfitShorts() / (float)$transactionCount : 0.00;
        //$transactionCount > 0 ? Calculator::calculate('$1 / $2', $profit, $transactionCount) : '0';
    }

    public function getMaxProfitableTransaction(): float
    {
        return $this->maxProfitableTransaction;
    }

    public function getMaxProfitableTransactionLongs(): float
    {
        return $this->maxProfitableTransactionLongs;
    }

    public function getMaxProfitableTransactionShorts(): float
    {
        return $this->maxProfitableTransactionShorts;
    }

    public function getMaxLosingTransaction(): float
    {
        return $this->maxLosingTransaction;
    }

    public function getMaxLosingTransactionLongs(): float
    {
        return $this->maxLosingTransactionLongs;
    }

    public function getMaxLosingTransactionShorts(): float
    {
        return $this->maxLosingTransactionShorts;
    }

    public function getAvgOpenBars(): int
    {
        $transactionCount = $this->profitableTransactions + $this->losingTransactions;
        return $transactionCount > 0 ?
            (int) (($this->barsProfitableTransactions + $this->barsLosingTransactions) / $transactionCount) : 0;
    }

    public function getAvgOpenBarsLongs(): int
    {
        $transactionCount = $this->profitableTransactionsLongs + $this->losingTransactionsLongs;
        return $transactionCount > 0 ?
            (int) (($this->barsProfitableTransactionsLongs + $this->barsLosingTransactionsLongs) / $transactionCount) : 0;
    }

    public function getAvgOpenBarsShorts(): int
    {
        $transactionCount = $this->profitableTransactionsShorts + $this->losingTransactionsShorts;
        return $transactionCount > 0 ?
            (int) (($this->barsProfitableTransactionsShorts + $this->barsLosingTransactionsShorts) / $transactionCount) : 0;
    }

    public function getAvgProfitableOpenBars(): int
    {
        $transactionCount = $this->profitableTransactions;
        return $transactionCount > 0 ?
            (int) ($this->barsProfitableTransactions / $transactionCount) : 0;
    }

    public function getAvgProfitableOpenBarsLongs(): int
    {
        $transactionCount = $this->profitableTransactionsLongs;
        return $transactionCount > 0 ?
            (int) ($this->barsProfitableTransactionsLongs / $transactionCount) : 0;
    }

    public function getAvgProfitableOpenBarsShorts(): int
    {
        $transactionCount = $this->profitableTransactionsShorts;
        return $transactionCount > 0 ?
            (int) ($this->barsProfitableTransactionsShorts / $transactionCount) : 0;
    }

    public function getAvgLosingOpenBars(): int
    {
        $transactionCount = $this->losingTransactions;
        return $transactionCount > 0 ?
            (int) ($this->barsLosingTransactions / $transactionCount) : 0;
    }

    public function getAvgLosingOpenBarsLongs(): int
    {
        $transactionCount = $this->losingTransactionsLongs;
        return $transactionCount > 0 ?
            (int) ($this->barsLosingTransactionsLongs / $transactionCount) : 0;
    }

    public function getAvgLosingOpenBarsShorts(): int
    {
        $transactionCount = $this->losingTransactionsShorts;
        return $transactionCount > 0 ?
            (int) ($this->barsLosingTransactionsShorts / $transactionCount) : 0;
    }

    public function getTradeLog(): array
    {
        $tradeLog = $this->tradeLog;
        // order by close time
        uasort($tradeLog, function($a, $b) {
            /** @var Position $a */
            /** @var Position $b */
            $closeTimeA = ($a->getCloseTime() ?? $a->getOpenTime())->toDateString();
            $closeTimeB = ($b->getCloseTime() ?? $b->getOpenTime())->toDateString();
            return $closeTimeA <=> $closeTimeB;
        });
        return $tradeLog;
    }

    public function getTradeLogAsArray(): array
    {
        $result = [];
        /** @var Position $position */
        foreach ($this->getTradeLog() as $position) {
            $result[$position->getId()] = serialize($position);
        }
        return $result;
    }

    public function setTradeLogFromArray(array $tradeLog): void
    {
        foreach ($tradeLog as $id => $position) {
            $this->tradeLog[$id] = unserialize($position);
        }
    }

    /**
     * @throws BacktesterException
     */
    public function setStrategyPeakValue(float $peakValue): void
    {
        if ($this->peakValue > 0.00001) {
            throw new BacktesterException('Strategy peak value already set.');
        }
        $this->peakValue = $peakValue;
    }

    /**
     * @throws BacktesterException
     * @throws NotYetImplementedException
     */
    public function getTradeStats(array $tradeLog): array
    {
        $currentCapital = $this->getInitialCapital();

        $this->capitalLog = [ $currentCapital ];
        $this->positionDrawdownLog = [ 0.00 ];
        $benchmarkLog = [ $currentCapital ];

        $benchmarkQty = $this->benchmark !== null ? $this->calcStatBenchmarkQty($currentCapital) : null;
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;
        $this->setStrategyPeakValue($currentCapital);

        $avgProfit = $this->getAvgProfit();
        $resultLog = [];

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();

            $this->calcStatMaxQty($position);
            $currentCapital = $this->calcStatProfitLoss($profit, $currentCapital);
            $position->setPortfolioBalance($currentCapital);
            $this->calcStatStrategyDrawdown($currentCapital, $position);
            $this->calcStatPositionDrawdown($position);

            $resultLog[] = $profit;
            $this->capitalLog[] = $currentCapital;
            $this->positionDrawdownLog[] = empty($position->getMaxDrawdownPercent()) ? 0 : 0 - round($position->getMaxDrawdownPercent(), 1);
            if ($this->benchmark !== null) {
                $benchmarkLog[] = $this->calcStatBenchmarkLogEntry($position, $benchmarkQty);
            }
        }

        $params = [
            'capital_log' => $this->capitalLog,
            'position_drawdown_log' => $this->positionDrawdownLog,
            'net_profit' => $this->getProfit(),
            'net_profit_percent' => $this->getProfitPercent(),
            'net_profit_longs' => $this->getNetProfitLongs(),
            'net_profit_shorts' => $this->getNetProfitShorts(),
            'avg_profit' => $avgProfit,
            'avg_profit_longs' => $this->getAvgProfitLongs(),
            'avg_profit_shorts' => $this->getAvgProfitShorts(),
            'gross_profit' => $this->getGrossProfit(),
            'gross_profit_longs' => $this->getGrossProfitLongs(),
            'gross_profit_shorts' => $this->getGrossProfitShorts(),
            'gross_loss' => $this->getGrossLoss(),
            'gross_loss_longs' => $this->getGrossLossLongs(),
            'gross_loss_shorts' => $this->getGrossLossShorts(),
            'profitable_transactions' => count($tradeLog) > 0 ? $this->getProfitableTransactions() * 100 / count($tradeLog) -1 : 0,
            'profit_factor' => $this->getProfitFactor(),
            'profit_factor_longs' => $this->getProfitFactorLongs(),
            'profit_factor_shorts' => $this->getProfitFactorShorts(),
            'volatility' => $this->calcStatVolatility($resultLog, count($tradeLog)),
            'sharpe_ratio' => $this->calcStatSharpeRatio($resultLog, $avgProfit, count($tradeLog)),
            'max_quantity_longs' => $this->maxQuantityLongs,
            'max_quantity_shorts' => $this->maxQuantityShorts,
            'max_strategy_drawdown_value' => $this->maxStrategyDrawdownValue,
            'max_strategy_drawdown_percent' => $this->maxStrategyDrawdownPercent,
            'max_position_drawdown_value' => $this->maxPositionDrawdownValue,
            'max_position_drawdown_percent' => $this->maxPositionDrawdownPercent,
            'max_bars_in_drawdown' => $this->maxBarsInDrawdown,
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'profitable_transactions_count' => $this->getProfitableTransactions(),
            'profitable_transactions_long_count' => $this->getProfitableTransactionsLongs(),
            'profitable_transactions_short_count' => $this->getProfitableTransactionsShorts(),
            'losing_transactions_count' => $this->getLosingTransactions(),
            'losing_transactions_long_count' => $this->getLosingTransactionsLongs(),
            'losing_transactions_short_count' => $this->getLosingTransactionsShorts(),
            'avg_profitable_transaction' => $this->getAvgProfitableTransaction(),
            'avg_profitable_transaction_longs' => $this->getAvgProfitableTransactionLongs(),
            'avg_profitable_transaction_shorts' => $this->getAvgProfitableTransactionShorts(),
            'avg_losing_transaction' => $this->getAvgLosingTransaction(),
            'avg_losing_transaction_longs' => $this->getAvgLosingTransactionLongs(),
            'avg_losing_transaction_shorts' => $this->getAvgLosingTransactionShorts(),
            'max_profitable_transaction' => $this->getMaxProfitableTransaction(),
            'max_profitable_transaction_longs' => $this->getMaxProfitableTransactionLongs(),
            'max_profitable_transaction_shorts' => $this->getMaxProfitableTransactionShorts(),
            'max_losing_transaction' => $this->getMaxLosingTransaction(),
            'max_losing_transaction_longs' => $this->getMaxLosingTransactionLongs(),
            'max_losing_transaction_shorts' => $this->getMaxLosingTransactionShorts(),
            'avg_bars_transaction' => $this->getAvgOpenBars(),
            'avg_bars_transaction_longs' => $this->getAvgOpenBarsLongs(),
            'avg_bars_transaction_shorts' => $this->getAvgOpenBarsShorts(),
            'avg_bars_profitable_transaction' => $this->getAvgProfitableOpenBars(),
            'avg_bars_profitable_transaction_longs' => $this->getAvgProfitableOpenBarsLongs(),
            'avg_bars_profitable_transaction_shorts' => $this->getAvgProfitableOpenBarsShorts(),
            'avg_bars_losing_transaction' => $this->getAvgLosingOpenBars(),
            'avg_bars_losing_transaction_longs' => $this->getAvgLosingOpenBarsLongs(),
            'avg_bars_losing_transaction_shorts' => $this->getAvgLosingOpenBarsShorts()
        ];
        if ($this->benchmark !== null) {
            $params['benchmark_log'] = $benchmarkLog;
            $params['benchmark_profit'] = $this->calcStatBenchmarkProfit($benchmarkLog);
        }
        return $params;
    }

    public function getStrategyVariables(): string
    {
        $vars = get_object_vars($this);
        foreach ($this->ignoreAttributes as $attrib) {
            unset($vars[$attrib]);
        }
        return serialize($vars);
    }

    public function setStrategyVariables(string $data): void
    {
        $data = unserialize($data);
        foreach ($data as $name => $value) {
            if (!in_array($name, $this->ignoreAttributes)) {
                $this->$name = $value;
            }
        }
    }

    public function getStrategyParameters(): array
    {
        return $this->strategyParameters;
    }

    /**
     * @throws StrategyException
     */
    protected function closePosition(Position $position, string $comment): float
    {
        $currentPrice = $this->currentAssets->getCurrentValue($position->getTicker(), $this->currentDateTime, $this->currentEvent);
        $calculatedSize = $this->calculatePositionSize($currentPrice, $position->getQuantity(), QuantityType::Units);
        $position->closePosition($this->currentDateTime, $currentPrice, $calculatedSize, $comment);

        $this->tradeLog[$position->getId()] = $position;
        $this->updateStatsAfterClosedPosition($position);

        $this->logger?->logInfo(
            '[' . $this->currentDateTime->toDateString() . '][' . $position->getId() . '] CLOSE @ ' . $currentPrice . ', ' .
            'profit: ' . $position->getProfitPercent() . '%' /*. '% == ' . $position->getProfitAmount() . ', equity: ' . $this->getCapital()*/ .
            ($comment ? ' (' . $comment . ')' : '')
        );
        $this->onCloseEvent?->call($this, $position);

        return $calculatedSize;
    }

    protected function updateStatsAfterClosedPosition(Position $position): void
    {
        $profit = $position->getProfitAmount();
        $openBars = $position->getOpenBars();
        if ($profit > 0.00001) {
            $this->grossProfit += $profit;
            $this->capital += $profit;
            $this->profitableTransactions++;
            $this->barsProfitableTransactions += $openBars;
            if ($profit > $this->maxProfitableTransaction) {
                $this->maxProfitableTransaction = $profit;
            }
            if ($position->getSide() === Side::Long) {
                $this->grossProfitLongs += $profit;
                $this->profitableTransactionsLongs++;
                $this->barsProfitableTransactionsLongs += $openBars;
                if ($profit > $this->maxProfitableTransactionLongs) {
                    $this->maxProfitableTransactionLongs = $profit;
                }
            } else {
                $this->grossProfitShorts += $profit;
                $this->profitableTransactionsShorts++;
                $this->barsProfitableTransactionsShorts += $openBars;
                if ($profit > $this->maxProfitableTransactionShorts) {
                    $this->maxProfitableTransactionShorts = $profit;
                }
            }
        } else {
            $loss = abs($profit);
            $this->grossLoss += $loss;
            $this->capital -= $loss;
            $this->losingTransactions++;
            $this->barsLosingTransactions += $openBars;
            if ($loss > $this->maxLosingTransaction) {
                $this->maxLosingTransaction = $loss;
            }
            if ($position->getSide() === Side::Long) {
                $this->grossLossLongs += $loss;
                $this->losingTransactionsLongs++;
                $this->barsLosingTransactionsLongs += $openBars;
                if ($loss > $this->maxLosingTransactionLongs) {
                    $this->maxLosingTransactionLongs = $loss;
                }
            } else {
                $this->grossLossShorts += $loss;
                $this->losingTransactionsShorts++;
                $this->barsLosingTransactionsShorts += $openBars;
                if ($loss > $this->maxLosingTransactionShorts) {
                    $this->maxLosingTransactionShorts = $loss;
                }
            }
        }
    }

    /**
     * @throws BacktesterException
     */
    protected function calcStatStrategyDrawdown(float $currentCapital, Position $position)
    {
        if ($currentCapital > $this->peakValue) {
            $this->peakValue = $currentCapital;
            $this->lastPeakDate = $position->getCloseTime();
        }
        if ($currentCapital < $this->peakValue) {
            $currentDrawdownValue = $this->peakValue - $currentCapital;
            $currentDrawdownPercent = ($this->peakValue - $currentCapital) * 100 / $this->peakValue;
            if ($this->lastPeakDate) {
                $barsInDrawdown = $this->lastPeakDate->diffInDays($position->getCloseTime());
                if ($barsInDrawdown > $this->maxBarsInDrawdown) {
                    $this->maxBarsInDrawdown = $barsInDrawdown;
                }
            }
        } else {
            $currentDrawdownValue = 0.00;
            $currentDrawdownPercent = 0.00;
        }
        if ($currentDrawdownValue > $this->maxStrategyDrawdownValue) {
            $this->maxStrategyDrawdownValue = $currentDrawdownValue;
            $this->maxStrategyDrawdownPercent = $currentDrawdownPercent;
        }
        $position->setStrategyDrawdown($currentDrawdownValue, $currentDrawdownPercent);
    }

    protected function calcStatPositionDrawdown(Position $position): void
    {
        if ($position->getMaxDrawdownValue() > $this->maxPositionDrawdownValue) {
            $this->maxPositionDrawdownValue = $position->getMaxDrawdownValue();
            $this->maxPositionDrawdownPercent = $position->getMaxDrawdownPercent();
        }
    }

    protected function calcStatBenchmarkProfit(array $benchmarkLog): float
    {
        return empty($benchmarkLog) ? 0.00 : $benchmarkLog[count($benchmarkLog) - 1] - $benchmarkLog[0];
    }

    protected function calcStatProfitLoss(float $profit, float $currentCapital): float
    {
        if ($profit > 0.00001) {
            $currentCapital += $profit; // Calculator::calculate('$1 + $2', $currentCapital, $profit);
        } else {
            $loss = abs($profit);
            $currentCapital -= $loss; // Calculator::calculate('$1 - $2', $currentCapital, $loss);
        }
        return $currentCapital;
    }

    protected function calcStatVolatility(array $resultLog, int $tradeCount): float
    {
        // TODO: review this in the future
        if (count($resultLog) > 2) {
            $mean = array_sum($resultLog) / count($resultLog);
            return Calculator::stdDev($resultLog) * 100 / $mean;
        }
        return 0.00;
    }

    protected function calcStatSharpeRatio(array $resultLog, float $avgProfit, int $tradeCount): float
    {
        if (count($resultLog) > 2) {
            $stdDev = Calculator::stdDev($resultLog);
            return sqrt($tradeCount) * $avgProfit / $stdDev;
            //Calculator::calculate('sqrt($1) * $2 / $3', count($tradeLog), $avgProfit, $stdDev);
        }
        return 0.00;
    }

    protected function calcStatMaxQty(Position $position): void
    {
        $quantity = $position->getQuantity();
        if ($position->getSide() === Side::Long && $quantity > $this->maxQuantityLongs /*Calculator::compare($quantity, $maxQuantityLongs) > 0*/) {
            $this->maxQuantityLongs = $position->getQuantity();
        }
        if ($position->getSide() === Side::Short && $quantity > $this->maxQuantityShorts /*Calculator::compare($quantity, $maxQuantityShorts) > 0*/) {
            $this->maxQuantityShorts = $position->getQuantity();
        }
    }

    /**
     * @throws NotYetImplementedException
     */
    protected function calcStatBenchmarkQty($currentCapital): float
    {
        $benchmarkLoaded = $this->benchmark->cloneToDate(
            $this->getStartDateForCalculations($this->assets, $this->getStartDate()),
            $this->getStartDate()
        );
        $benchmarkValue = $benchmarkLoaded->getCurrentValue($this->benchmarkTicker);
        $benchmarkQty = $benchmarkValue ? $currentCapital / $benchmarkValue : 0;
        unset($benchmarkLoaded);
        return $benchmarkQty;
    }

    protected function calcStatBenchmarkLogEntry(Position $position, float $benchmarkQty): float
    {
        $benchmarkLoaded = $this->benchmark->cloneToDate($this->getStartDate(), $position->getCloseTime());
        $benchmarkValue = $benchmarkLoaded->getCurrentValue($this->benchmarkTicker);
        $benchmarkResult = round($benchmarkValue * $benchmarkQty, 2);
        unset($benchmarkLoaded);
        $this->logger?->logDebug('Benchmark [' . $position->getCloseTime()->toDateString() . ']: ' . $benchmarkResult);
        return $benchmarkResult;
    }

    /**
     * @throws StrategyException
     */
    protected function calculatePositionSize(float $price, float $qty, QuantityType $qtyType): float
    {
        if ($qtyType === QuantityType::Percent && ($qty > 100 || $qty <= 0.00001)) {
            throw new StrategyException('Quantity percentage must be between 0 and 100');
        }
        return match ($qtyType) {
            QuantityType::Percent => $this->capital * $qty / 100,
            QuantityType::Units => $price * $qty,
            default => throw new StrategyException('Unknown QuantityType'),
        };
    }

    protected function updateDrawdown(): void
    {
        /** @var Position $position */
        foreach ($this->openTrades as $position) {
            $position->calculateDrawdown($this->currentAssets->getLatestOhlc($position->getTicker(), $this->currentDateTime));
        }
    }
}