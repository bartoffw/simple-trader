<?php

namespace SimpleTrader\Helpers;

class DateTime
{
    protected string $dateTime;
    protected string $currentDateTime;


    public function __construct(string $dateTime, protected Resolution $resolution = Resolution::Daily)
    {
        $this->dateTime = $this->resolution === Resolution::Daily ?
            date('Y-m-d', strtotime($dateTime)) :
            date('Y-m-d H:i:s', strtotime($dateTime));
        $this->currentDateTime = $this->dateTime;
    }

    public function getDateTime():string
    {
        return $this->dateTime;
    }

    public function getCurrentDateTime():string
    {
        return $this->currentDateTime;
    }

    public function increaseByStep():string
    {
        $this->currentDateTime = match ($this->resolution) {
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