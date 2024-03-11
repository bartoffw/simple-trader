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
        $styles = file_get_contents(__DIR__ . '/../assets/styles.min.css');
        $html = file_get_contents(__DIR__ . '/../assets/page.html');
        $title = 'Backtest Results - ' . date('Y-m-d H:i');

        $tradeLog = $backtester->getTradeLog();
        $netProfit = $backtester->getProfit() . ' (' . $backtester->getProfitPercent() . '%)';
        $tradeStats = $backtester->getTradeStats($tradeLog);
        $avgProfit = $backtester->getAvgProfit($backtester->getProfit(), count($tradeLog));
        $capitalGraph = '<img src="data:image/png;base64,' . base64_encode($this->graphs->generateCapitalGraph($tradeStats['capital_log'])) . '" />';

        $output = strtr($html, [
            '%title%' => $title,
            '%styles%' => $styles,
            '%net_profit%' => number_format((float)$netProfit, 2),
            '%closed_transactions%' => number_format(count($tradeLog)),
            '%profitable_transactions%' => number_format((float)$tradeStats['profitable_transactions']),
            '%profit_factor%' => number_format($tradeStats['profit_factor'], 2),
            '%max_drawdown%' => '',
            '%avg_profit%' => number_format((float)$avgProfit, 2),
            '%avg_bars%' => '',
            '%capital_graph%' => $capitalGraph,
        ]);
        file_put_contents($this->reportPath . '/report.html', $output);
    }
}