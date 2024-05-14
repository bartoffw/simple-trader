<?php

namespace SimpleTrader\Helpers;

use Carbon\Carbon;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\StrategyException;

class Position
{
    protected string $id;
    protected PositionStatus $status;
    protected int $openBars = 0;
    protected string $openComment = '';
    protected string $closeComment = '';
    protected Carbon $openTime;
    protected Carbon $closeTime;
    protected float $openPrice;
    protected float $closePrice;
    protected float $openPositionSize;
    protected float $closePositionSize;
    protected float $peakValue = 0;
    protected float $portfolioBalance = 0;
    protected float $maxDrawdownValue = 0;
    protected float $maxDrawdownPercent = 0;
    protected ?float $strategyDrawdownValue = null;
    protected ?float $strategyDrawdownPercent = null;


    public function __construct(Carbon $currentTime, protected Side $side, protected string $ticker,
                                protected float $price, protected float $quantity,
                                protected float $positionSize, string $comment = '')
    {
        $this->id = uniqid($this->ticker . '-' . $this->side->value . '-');
        $this->status = PositionStatus::Open;
        $this->openTime = $currentTime->copy();
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

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getPositionSize(): float
    {
        return $this->positionSize;
    }

    public function getOpenPrice(): float
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

    public function getClosePrice(): float
    {
        return $this->closePrice;
    }

    public function getOpenTime(): Carbon
    {
        return $this->openTime;
    }

    public function getCloseTime(): Carbon
    {
        return $this->closeTime;
    }

    public function setPortfolioBalance(float $balance): void
    {
        if (abs($this->portfolioBalance) > 0.00001) {
            throw new BacktesterException('Portfolio balance is already set for this position: ' . $this->getId());
        }
        $this->portfolioBalance = $balance;
    }

    public function getPortfolioBalance(): ?float
    {
        return $this->portfolioBalance;
    }

    public function setStrategyDrawdown(float $value, float $percent)
    {
        if ($this->strategyDrawdownValue !== null || $this->strategyDrawdownPercent !== null) {
            throw new BacktesterException('Strategy drawdown already set for this position: ' . $this->getId());
        }
        $this->strategyDrawdownValue = $value;
        $this->strategyDrawdownPercent = $percent;
    }

    public function getStrategyDrawdownValue(): float
    {
        return $this->strategyDrawdownValue;
    }

    public function getStrategyDrawdownPercent(): float
    {
        return $this->strategyDrawdownPercent;
    }

    public function calculateDrawdown(Ohlc $ohlc)
    {
        $maxValue = max((float) $ohlc->getOpen(), (float) $ohlc->getLow(), (float) $ohlc->getHigh(), (float) $ohlc->getClose());
        $minValue = min((float) $ohlc->getOpen(), (float) $ohlc->getLow(), (float) $ohlc->getHigh(), (float) $ohlc->getClose());

        if ($this->side == Side::Long) {
            if ($maxValue > $this->peakValue) {
//                echo "[" . $ohlc->getDateTime()->getDateTime() . "] PEAK: {$maxValue}\n";
                $this->peakValue = $maxValue;
            }
            if ($minValue < $this->peakValue) {
                $currentDrawdownValue = $this->quantity * ($this->openPrice - $minValue);
                $currentDrawdownPercent = $currentDrawdownValue * 100 / ($this->openPrice * $this->quantity);
            } else {
                $currentDrawdownValue = 0;
                $currentDrawdownPercent = 0;
            }
            if ($currentDrawdownValue > $this->maxDrawdownValue) {
//                echo "[" . $ohlc->getDateTime()->getDateTime() . "] TROUGH: {$currentDrawdownValue}\n";
                $this->maxDrawdownValue = $currentDrawdownValue;
                $this->maxDrawdownPercent = $currentDrawdownPercent;
            }
        } else {
            // TODO: calculate for SHORTs
        }
    }

    public function getMaxDrawdownValue(): ?float
    {
        return $this->maxDrawdownValue;
    }

    public function getMaxDrawdownPercent(): ?float
    {
        return $this->maxDrawdownPercent;
    }

    public function updatePosition(float $price, float $positionSize): void
    {
        $this->price = $price;
        $this->positionSize = $positionSize;
    }

    /**
     * @throws StrategyException
     */
    public function closePosition(Carbon $currentTime, float $price, float $positionSize, string $comment = ''): void
    {
        if ($this->status === PositionStatus::Closed) {
            throw new StrategyException('Position is already closed');
        }
        $this->updatePosition($price, $positionSize);
        $this->closeComment = $comment;
        $this->closeTime = $currentTime->copy();
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

    public function getProfitPercent(): float
    {
        return ($this->closePositionSize ?? $this->positionSize) * 100 / $this->openPositionSize - 100;
        //Calculator::calculate('$1 * 100 / $2 - 100', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }

    public function getProfitAmount(): float
    {
        return ($this->closePositionSize ?? $this->positionSize) - $this->openPositionSize;
        //Calculator::calculate('$1 - $2', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }
}