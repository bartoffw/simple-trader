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
    protected ?LoggerInterface $logger = null;
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

    }

    public function onClose(Assets $assets, DateTime $dateTime): void
    {

    }

    public function onStrategyEnd(Assets $assets, DateTime $dateTime): void
    {

    }

    public function entry(Side $side, string $ticker, string $price, string $positionSize = '100', string $comment = ''): Position
    {
        switch ($this->qtyType) {
            case QuantityType::Percent:
                $calculatedSize = Calculator::calculate('$1 * $2 / 100', $this->capital, $positionSize);
                break;
            case QuantityType::Units:
                $calculatedSize = Calculator::calculate('$1 * $2', $price, $positionSize);
                break;
            default:
                throw new StrategyException('Unknown QuantityType');
        }
        if ($calculatedSize > $this->capitalAvailable) {
            throw new StrategyException("Position size ($calculatedSize) is greater than the available capital ($this->capitalAvailable)");
        }

        $position = new Position($side, $ticker, $price, $calculatedSize, $comment);
        $this->openTrades[] = $position;
        $this->openPositionSize = Calculator::calculate('$1 + $2', $this->openPositionSize, $calculatedSize);
        $this->capitalAvailable = Calculator::calculate('$1 - $2', $this->capitalAvailable, $calculatedSize);
        return $position;
    }

    public function closeAll(string $comment = '')
    {

    }

    public function hasOpenTrades()
    {
        return !empty($this->openTrades);
    }

    public function getOpenTrades(): array
    {
        return $this->openTrades;
    }
}