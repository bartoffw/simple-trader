<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\StrategyException;

class Position
{
    protected string $id;
    protected PositionStatus $status;
    protected int $openBars = 0;
    protected string $openComment = '';
    protected string $closeComment = '';
    protected DateTime $openTime;
    protected DateTime $closeTime;
    protected string $openPrice;
    protected string $closePrice;
    protected string $openPositionSize;
    protected string $closePositionSize;
    protected string $peakValue = '0';
    protected ?string $portfolioBalance = '0';
    protected string $maxDrawdownValue = '0';
    protected ?string $maxDrawdownPercent = '0';


    public function __construct(DateTime $currentTime, protected Side $side, protected string $ticker,
                                protected string $price, protected string $quantity,
                                protected string $positionSize, string $comment = '')
    {
        $this->id = uniqid($this->ticker . '-' . $this->side->value . '-');
        $this->status = PositionStatus::Open;
        $this->openTime = $currentTime;
        $this->openPrice = $this->price;
        $this->openPositionSize = $this->positionSize;
        $this->openComment = $comment;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getPositionSize(): string
    {
        return $this->positionSize;
    }

    public function getOpenPrice(): string
    {
        return $this->openPrice;
    }

    public function getSide(): Side
    {
        return $this->side;
    }

    public function getOpenComment(): string
    {
        return $this->openComment;
    }

    public function getCloseComment(): string
    {
        return $this->closeComment;
    }

    public function getClosePrice(): string
    {
        return $this->closePrice;
    }

    public function getOpenTime(): DateTime
    {
        return $this->openTime;
    }

    public function getCloseTime(): DateTime
    {
        return $this->closeTime;
    }

    public function setPortfolioBalance(string $balance): void
    {
        if ($this->portfolioBalance !== '0') {
            throw new BacktesterException('Portfolio balance is already set for this position: ' . $this->getId());
        }
        $this->portfolioBalance = $balance;
    }

    public function getPortfolioBalance(): ?string
    {
        return $this->portfolioBalance;
    }

    public function calculateDrawdown(Ohlc $ohlc)
    {
        $maxValue = max((float) $ohlc->getOpen(), (float) $ohlc->getLow(), (float) $ohlc->getHigh(), (float) $ohlc->getClose());
        $minValue = min((float) $ohlc->getOpen(), (float) $ohlc->getLow(), (float) $ohlc->getHigh(), (float) $ohlc->getClose());

        if ($this->side == Side::Long) {
            if (Calculator::compare($maxValue, $this->peakValue) > 0) {
//                echo "[" . $ohlc->getDateTime()->getDateTime() . "] PEAK: {$maxValue}\n";
                $this->peakValue = $maxValue;
            }
            if (Calculator::compare($minValue, $this->peakValue) < 0) {
                $currentDrawdownValue = $this->quantity * ($this->openPrice - $minValue);
                $currentDrawdownPercent = $currentDrawdownValue * 100 / ($this->openPrice * $this->quantity);
            } else {
                $currentDrawdownValue = '0';
                $currentDrawdownPercent = '0';
            }
            if (Calculator::compare($currentDrawdownValue, $this->maxDrawdownValue) > 0) {
//                echo "[" . $ohlc->getDateTime()->getDateTime() . "] TROUGH: {$currentDrawdownValue}\n";
                $this->maxDrawdownValue = $currentDrawdownValue;
                $this->maxDrawdownPercent = $currentDrawdownPercent;
            }
        } else {
            // TODO: calculate for SHORTs
        }
    }

    public function getMaxDrawdownValue(): ?string
    {
        return $this->maxDrawdownValue;
    }

    public function getMaxDrawdownPercent(): ?string
    {
        return $this->maxDrawdownPercent;
    }

    public function updatePosition(string $price, string $positionSize): void
    {
        $this->price = $price;
        $this->positionSize = $positionSize;
    }

    /**
     * @throws StrategyException
     */
    public function closePosition(DateTime $currentTime, string $price, string $positionSize, string $comment = ''): void
    {
        if ($this->status === PositionStatus::Closed) {
            throw new StrategyException('Position is already closed');
        }
        $this->updatePosition($price, $positionSize);
        $this->closeComment = $comment;
        $this->closeTime = $currentTime;
        $this->closePrice = $price;
        $this->closePositionSize = $positionSize;
        $this->status = PositionStatus::Closed;
    }

    /**
     * @throws StrategyException
     */
    public function incrementOpenBars(): int
    {
        if ($this->status === PositionStatus::Closed) {
            throw new StrategyException('Tried to increase open bars for a closed position');
        }
        return ++$this->openBars;
    }

    public function getOpenBars(): int
    {
        return $this->openBars;
    }

    public function getProfitPercent(): string
    {
        return Calculator::calculate('$1 * 100 / $2 - 100', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }

    public function getProfitAmount(): string
    {
        return Calculator::calculate('$1 - $2', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }
}