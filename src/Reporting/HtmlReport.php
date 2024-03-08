<?php

namespace SimpleTrader\Reporting;

use SimpleTrader\Backtester;

class HtmlReport
{
    protected Graphs $graphs;


    public function __construct(protected string $reportPath)
    {
        $this->graphs = new Graphs($this->reportPath);
    }

    public function generateReport(Backtester $backtester)
    {
        $this->graphs->generateCapitalGraph($backtester->getCapitalLog());
    }
}