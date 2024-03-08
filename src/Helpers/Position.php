<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Exceptions\StrategyException;

class Position
{
    protected string $id;
    protected PositionStatus $status;
    protected DateTime $openTime;
    protected DateTime $closeTime;
    protected string $openPrice;
    protected string $closePrice;
    protected string $openPositionSize;
    protected string $closePositionSize;


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

    public function getOpenTime(): DateTime
    {
        return $this->openTime;
    }

    public function getCloseTime(): DateTime
    {
        return $this->closeTime;
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

    public function getProfitPercent(): string
    {
        return Calculator::calculate('$1 * 100 / $2 - 100', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }

    public function getProfitAmount(): string
    {
        return Calculator::calculate('$1 - $2', $this->closePositionSize ?? $this->positionSize, $this->openPositionSize);
    }
}