<?php

namespace SimpleTrader;

use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\QuantityType;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\LoggerInterface;

class BaseStrategy
{
    private bool $parentOnOpenCalled = false;
    private bool $parentOnCloseCalled = false;
    protected ?LoggerInterface $logger = null;
    protected DateTime $currentDateTime;
    protected Assets $currentAssets;
    protected array $openTrades = [];
    protected string $openPositionSize = '0';
    protected ?string $capital = null;
    protected ?string $capitalAvailable = null;
    protected int $precision = 2;


    public function __construct(protected QuantityType $qtyType = QuantityType::Percent)
    {
    }

    public function setLogger(LoggerInterface $logger, bool $override = false): void
    {
        if ($override || $this->logger === null) {
            $this->logger = $logger;
        }
    }

    public function setCapital(string $capital, int $precision = 2): void
    {
        $this->precision = $precision;
        $this->capital = $capital;
        $this->capitalAvailable = $capital;
        bcscale($precision);
    }

    public function getCapital($formatted = false): ?string
    {
        return $formatted ? number_format($this->capital, $this->precision) : $this->capital;
    }

    public function getOpenProfitPercent(): string
    {
        return '0';
    }

    public function onOpen(Assets $assets, DateTime $dateTime): void
    {
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
    }

    public function onClose(Assets $assets, DateTime $dateTime): void
    {
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
    }

    public function onStrategyEnd(Assets $assets, DateTime $dateTime): void
    {
        $this->currentAssets = $assets;
        $this->currentDateTime = $dateTime;
    }

    /**
     * @throws StrategyException
     */
    public function entry(Side $side, string $ticker, string $price, string $positionSize = '100', string $comment = ''): Position
    {
        if (empty($price)) {
            throw new StrategyException("Price ($price) cannot be empty or zero");
        }
        $calculatedSize = $this->calculatePositionSize($price, $positionSize, $this->qtyType);
        if ($calculatedSize > $this->capitalAvailable) {
            throw new StrategyException("Position size ($calculatedSize) is greater than the available capital ($this->capitalAvailable)");
        }
        $calculatedQty = Calculator::calculate('$1 / $2', $calculatedSize, $price);

        $position = new Position($side, $ticker, $price, $calculatedQty, $calculatedSize, $comment);
        $this->openTrades[$position->getId()] = $position;
        $this->openPositionSize = Calculator::calculate('$1 + $2', $this->openPositionSize, $calculatedSize);
        $this->capitalAvailable = Calculator::calculate('$1 - $2', $this->capitalAvailable, $calculatedSize);

        $this->logger->logInfo(
            '[' . $this->currentDateTime->getDateTime() . '][' . $position->getId() . '] ' . $side->value . ' @ ' . $price . ', ' .
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
        /** @var Position $position */
        foreach ($this->openTrades as $position) {
            $asset = $this->currentAssets->getAsset($position->getTicker());
            if ($asset === null) {
                throw new StrategyException('Asset not found in the asset list for an open position: ' . $position->getTicker());
            }
            $currentPrice = $asset->getCurrentValue();
            $calculatedSize = $this->calculatePositionSize($currentPrice, $position->getQuantity(), QuantityType::Units);
            $position->closePosition($currentPrice, $calculatedSize);

            $profit = $position->getProfitAmount();
            $this->capital = $profit > 0 ?
                Calculator::calculate('$1 + $2', $this->capital, $profit) :
                Calculator::calculate('$1 - $2', $this->capital, trim($profit, '-'));

            $this->logger->logInfo(
                '[' . $this->currentDateTime->getDateTime() . '][' . $position->getId() . '] CLOSE @ ' . $currentPrice . ', ' .
                'profit: ' . $position->getProfitPercent() . '% == ' . $position->getProfitAmount() . ', equity: ' . $this->getCapital() .
                ($comment ? ' (' . $comment . ')' : '')
            );
        }
        $this->openPositionSize = 0;
        $this->openTrades = [];
        $this->capitalAvailable = $this->capital;
    }

    public function hasOpenTrades()
    {
        return !empty($this->openTrades);
    }

    public function getOpenTrades(): array
    {
        return $this->openTrades;
    }

    /**
     * @throws StrategyException
     */
    protected function calculatePositionSize(string $price, string $qty, QuantityType $qtyType): string
    {
        if ($qtyType === QuantityType::Percent && ($qty > '100' || $qty <= '0')) {
            throw new StrategyException('Quantity percentage must be between 0 and 100');
        }
        return match ($qtyType) {
            QuantityType::Percent => Calculator::calculate('$1 * $2 / 100', $this->capital, $qty),
            QuantityType::Units => Calculator::calculate('$1 * $2', $price, $qty),
            default => throw new StrategyException('Unknown QuantityType'),
        };
    }
}