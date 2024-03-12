<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\StrategyException;

class Position
{
    protected string $id;
    protected PositionStatus $status;
    protected int $openBars = 0;
    protected DateTime $openTime;
    protected DateTime $closeTime;
    protected string $openPrice;
    protected string $closePrice;
    protected string $openPositionSize;
    protected string $closePositionSize;
    protected ?string $portfolioBalance = null;
    protected ?string $portfolioDrawdown = null;


    public function __construct(DateTime $currentTime, protected Side $side, protected string $ticker,
                                protected string $price, protected string $quantity,
                                protected string $positionSize, protected string $comment = '')
    {
        $this->id = uniqid($this->ticker . '-' . $this->side->value . '-');
        $this->status = PositionStatus::Open;
        $this->openTime = $currentTime;
        $this->openPrice = $this->price;
        $this->openPositionSize = $this->positionSize;
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

    public function getComment(): string
    {
        return $this->comment;
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
        if ($this->portfolioBalance !== null) {
            throw new BacktesterException('Portfolio balance is already set for this position: ' . $this->getId());
        }
        $this->portfolioBalance = $balance;
    }

    public function getPortfolioBalance(): ?string
    {
        return $this->portfolioBalance;
    }

    public function setPortfolioDrawdown(string $drawdown): void
    {
        if ($this->portfolioDrawdown !== null) {
            throw new BacktesterException('Portfolio drawdown is already set for this position: ' . $this->getId());
        }
        $this->portfolioDrawdown = $drawdown;
    }

    public function getPortfolioDrawdown(): ?string
    {
        return $this->portfolioDrawdown;
    }

    public function updatePosition(string $price, string $positionSize): void
    {
        $this->price = $price;
        $this->positionSize = $positionSize;
    }

    /**
     * @throws StrategyException
     */
    public function closePosition(DateTime $currentTime, string $price, string $positionSize): void
    {
        if ($this->status === PositionStatus::Closed) {
            throw new StrategyException('Position is already closed');
        }
        $this->updatePosition($price, $positionSize);
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