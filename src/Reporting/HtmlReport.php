<?php

namespace SimpleTrader\Reporting;

use SimpleTrader\Backtester;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\Position;

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
        $title = 'Backtest Results - ' . $backtester->getStrategyName();
        $date = date('Y-m-d H:i');

        $tradeLog = $backtester->getTradeLog();
        $tradeStats = $backtester->getTradeStats($tradeLog);
        $netProfit = number_format((float)$tradeStats['net_profit'], 2) . '<br/>' . $backtester->getProfitPercent() . '%';
        $avgProfit = $backtester->getAvgProfit($backtester->getProfit(), count($tradeLog));
        $capitalGraph = '<img src="data:image/png;base64,' . base64_encode($this->graphs->generateCapitalGraph($tradeStats['capital_log'])) . '" />';

        $output = strtr($html, [
            '%title%' => $title,
            '%backtest_date%' => $date,
            '%styles%' => $styles,
            '%net_profit%' => $netProfit,
            '%closed_transactions%' => number_format(count($tradeLog)),
            '%profitable_transactions%' => number_format((float)$tradeStats['profitable_transactions']),
            '%profit_factor%' => number_format($tradeStats['profit_factor'], 2),
            '%max_drawdown%' => number_format((float)$tradeStats['max_drawdown_value'], 2) . '<br/>' . number_format((float)$tradeStats['max_drawdown_percent'], 2),
            '%avg_profit%' => number_format((float)$avgProfit, 2),
            '%avg_bars%' => number_format(floor((float)$tradeStats['avg_bars'])),
            '%capital_graph%' => $capitalGraph,

            '%detailed_stats%' => $this->formatDetailedStats($tradeStats),
            '%transactions_history%' => $this->formatTransactionHistory($tradeLog, $tradeStats)
        ]);
        file_put_contents($this->reportPath . '/report.html', $output);
    }

    protected function formatDetailedStats(array $tradeStats)
    {
        $stats = [];
        $stats[] = '<tr>' .
            '<th>Net profit</th>' .
            '<td class="text-right">' . number_format((float)$tradeStats['net_profit'], 2) . '</td>' .
            '<td class="text-right">?</td>' .
            '<td class="text-right">?</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Gross profit</th>' .
            '<td class="text-right">' . number_format((float)Calculator::calculate('$1 + $2', $tradeStats['gross_profit_longs'], $tradeStats['gross_profit_shorts']), 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['gross_profit_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['gross_profit_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Gross loss</th>' .
            '<td class="text-right">' . number_format((float)Calculator::calculate('$1 + $2', $tradeStats['gross_loss_longs'], $tradeStats['gross_loss_shorts']), 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['gross_loss_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['gross_loss_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Profit factor</th>' .
            '<td class="text-right">' . number_format((float)$tradeStats['profit_factor'], 2) . '</td>' .
            '<td class="text-right">?</td>' .
            '<td class="text-right">?</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Max quantity</th>' .
            '<td class="text-right">' . number_format((float)Calculator::calculate('$1 + $2', $tradeStats['max_quantity_longs'], $tradeStats['max_quantity_shorts']), 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['max_quantity_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format((float)$tradeStats['max_quantity_shorts'], 2) . '</td>' .
        '</tr>';
        return implode("\n", $stats);
    }

    protected function formatTransactionHistory(array $tradeLog, array $tradeStats): string
    {
        $rows = [];
        $i = 1;
        /** @var Position $position */
        foreach ($tradeLog as $position) {
//            if (Calculator::compare($position->getPortfolioBalance(), $tradeStats['peak_value']) === 0) {
//                $balance = '<span class="text-green">%balance%</span>';
//            } elseif (Calculator::compare($position->getPortfolioBalance(), $tradeStats['trough_value']) === 0) {
//                $balance = '<span class="text-red">%balance%</span>';
//            } else {
//                $balance = '%balance%';
//            }
//            $balance = strtr($balance, ['%balance%' => number_format((float)$position->getPortfolioBalance(), 2)]);
            $row = '<tr>' .
                    '<td rowspan="2" class="text-right"><strong>' . $i . '</strong></td>' .
                    '<td class="text-right">' . $position->getOpenTime()->getDate() . '</td>' .
                    '<td>' . $position->getSide()->value . ' OPEN</td>' .
                    '<td class="text-right">' . number_format($position->getOpenPrice(), 2) . '</td>' .
                    '<td rowspan="2" class="text-right">' . $position->getQuantity() . '</td>' .
                    '<td rowspan="2" class="text-right">' . $position->getProfitAmount() . '<br/>' . $this->textColor(
                        $position->getProfitPercent() . '%',
                    Calculator::compare($position->getProfitPercent(), '0') < 0 ? 'red' : 'green'
                    ) . '</td>' .
                    '<td rowspan="2" class="text-right">' . number_format((float)$position->getPortfolioBalance(), 2) . '</td>' .
                    '<td rowspan="2" class="text-right">' . $this->textColor(
                        number_format($position->getPortfolioDrawdownPercent(), 2) . '%',
                        Calculator::compare($position->getPortfolioDrawdownPercent(), '0') > 0 ? 'red' : null
                    ) . '</td>' .
                '</tr>';
            $row .= '<tr>' .
                    '<td class="text-right">' . $position->getCloseTime()->getDate() . '</td>' .
                    '<td>' . $position->getSide()->value . ' CLOSE</td>' .
                    '<td class="text-right">' . number_format($position->getClosePrice(), 2) . '</td>' .
                '</tr>';
            $rows[] = $row;
            $i++;
        }
        return implode("\n", $rows);
    }

    protected function textColor(string $text, ?string $color = null): string
    {
        return $color !== null ?
            '<span class="text-' . $color . '">' . $text . '</span>' :
            $text;
    }
}