<?php

namespace SimpleTrader;

use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\QuantityType;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\LoggerInterface;

class BaseStrategy
{
    protected string $strategyName = 'Base Strategy';
    private bool $parentOnOpenCalled = false;
    private bool $parentOnCloseCalled = false;
    protected ?LoggerInterface $logger = null;
    protected DateTime $startDate;
    protected DateTime $currentDateTime;
    protected Assets $currentAssets;
    protected ?Event $currentEvent = null;

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

    protected int $precision = 2;


    public function __construct(protected QuantityType $qtyType = QuantityType::Percent)
    {
    }

    public function getStrategyName(): string
    {
        return $this->strategyName;
    }

    public function setLogger(LoggerInterface $logger, bool $override = false): void
    {
        if ($override || $this->logger === null) {
            $this->logger = $logger;
        }
    }

    public function setStartDate(DateTime $dateTime): void
    {
        $this->startDate = $dateTime;
    }

    public function getStartDate(): DateTime
    {
        return $this->startDate;
    }

    public function getMaxLookbackPeriod(): int
    {
        return 0;
    }

    public function getStartDateForCalculations(Assets $assets, DateTime $startDate): DateTime
    {
        $oldestDate = null;
        foreach ($this->tickers as $ticker) {
            $asset = $assets->getAsset($ticker);
            $record = $asset
                ->select('date')
                ->where(fn($record, $recordKey) => $record['date'] <= $startDate->getDateTime())
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
        return $oldestDate ? new DateTime($oldestDate) : $startDate;
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
        if ($this->capital !== null) {
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

    public function onOpen(Assets $assets, DateTime $dateTime): void
    {
        $this->currentEvent = Event::OnOpen;
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
        $this->updateDrawdown();
    }

    public function onClose(Assets $assets, DateTime $dateTime): void
    {
        $this->currentEvent = Event::OnClose;
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
        $this->updateDrawdown();
    }

    public function onStrategyEnd(Assets $assets, DateTime $dateTime): void
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
            throw new StrategyException("Position size ($calculatedSize) is greater than the available capital ($this->capitalAvailable)");
        }
        $calculatedQty = $calculatedSize / $currentPrice;

        $position = new Position($this->currentDateTime, $side, $ticker, $currentPrice, $calculatedQty, $calculatedSize, $comment);
        $this->openTrades[$position->getId()] = $position;
        $this->tradeLog[$position->getId()] = $position;

        $this->openPositionSize = $this->openPositionSize + $calculatedSize;
        $this->capitalAvailable = $this->capitalAvailable - $calculatedSize;

        $this->logger->logInfo(
            '[' . $this->currentDateTime->getDateTime() . '][' . $position->getId() . '] ' . $side->value . ' @ ' . $currentPrice . ', ' .
            'total size: ' . $calculatedSize . ', equity: ' . $this->getCapital() .
            ($comment ? ' (' . $comment . ')' : '')
        );

        return $position;
    }

    /**
     * @throws StrategyException
     */
    public function closeAll(string $comment = '')
    {
        if ($this->currentEvent === null) {
            throw new StrategyException('Current event not known. Are you calling parent onOpen/onClose/onStrategyEnd in your strategy?');
        }
        /** @var Position $position */
        foreach ($this->openTrades as $position) {
            if (!$this->currentAssets->hasAsset($position->getTicker())) {
                throw new StrategyException('Asset not found in the asset list for an open position: ' . $position->getTicker());
            }
            $currentPrice = $this->currentAssets->getCurrentValue($position->getTicker(), $this->currentDateTime, $this->currentEvent);
            $calculatedSize = $this->calculatePositionSize($currentPrice, $position->getQuantity(), QuantityType::Units);
            $position->closePosition($this->currentDateTime, $currentPrice, $calculatedSize, $comment);

            $this->tradeLog[$position->getId()] = $position;

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

            $this->logger->logInfo(
                '[' . $this->currentDateTime->getDateTime() . '][' . $position->getId() . '] CLOSE @ ' . $currentPrice . ', ' .
                'profit: ' . $position->getProfitPercent() . '%' /*. '% == ' . $position->getProfitAmount() . ', equity: ' . $this->getCapital()*/ .
                ($comment ? ' (' . $comment . ')' : '')
            );
        }
        $this->openPositionSize = 0;
        $this->openTrades = [];
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
            $closeTimeA = $a->getCloseTime()->getDateTime();
            $closeTimeB = $b->getCloseTime()->getDateTime();
            return $closeTimeA <=> $closeTimeB;
        });
        return $tradeLog;
    }

    /**
     * @throws StrategyException
     */
    protected function calculatePositionSize(float $price, float $qty, QuantityType $qtyType): float
    {
        if ($qtyType === QuantityType::Percent && ($qty > 100 || $qty <= 0)) {
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