<?php

namespace SimpleTrader\Helpers;

class Position
{
    protected string $id;


    public function __construct(protected Side $side, protected string $ticker, protected string $price,
                                protected string $positionSize, protected string $comment = '')
    {
        $this->id = uniqid($this->side->value . '_');
    }

    public function getId()
    {
        return $this->id;
    }
}