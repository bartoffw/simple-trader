<?php

namespace SimpleTrader\Helpers;

class OptimizationParam
{
    public function __construct(protected string $paramName, protected float|int $from, protected float|int $to, protected float|int $step) {}

    public function getParamName(): string
    {
        return $this->paramName;
    }

    public function getFrom(): float|int
    {
        return $this->from;
    }

    public function getTo(): float|int
    {
        return $this->to;
    }

    public function getStep(): float|int
    {
        return $this->step;
    }

    public function getValues(): array
    {
        $values = [];
        for ($v = $this->from; $v <= $this->to; $v += $this->step) {
            $values[] = $v;
        }
        return $values;
    }
}