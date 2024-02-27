<?php

namespace SimpleTrader;

use SimpleTrader\Resolution;

class DateTime
{
    protected string $dateTime;
    protected string $currentDateTime;


    public function __construct(string $dateTime)
    {
        $this->dateTime = date('Y-m-d H:i:s', strtotime($dateTime));
        $this->currentDateTime = $this->dateTime;
    }

    public function getDateTime(?Resolution $resolution = null):string
    {
        if ($resolution === null) {
            return $this->dateTime;
        }
        return match($resolution) {
            Resolution::Daily, Resolution::Weekly, Resolution::Monthly => date('Y-m-d', strtotime($this->dateTime)),
            default => $this->dateTime
        };
    }

    public function getCurrentDateTime():string
    {
        return $this->currentDateTime;
    }

    public function increaseByStep(Resolution $resolution):string
    {
        $this->currentDateTime = match ($resolution) {
            Resolution::Daily => date('Y-m-d', strtotime('+1 day', strtotime($this->currentDateTime))),
            Resolution::Weekly => date('Y-m-d', strtotime('+1 week', strtotime($this->currentDateTime))),
        };
        return $this->currentDateTime;
    }

    public function __toString():string
    {
        return $this->dateTime;
    }
}