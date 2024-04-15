<?php

namespace SimpleTrader\Reporting;

use SimpleTrader\Backtester;
use SimpleTrader\Helpers\Position;

class HtmlReport
{
    protected Graphs $graphs;


    public function __construct(protected string $reportPath)
    {
        $this->graphs = new Graphs($this->reportPath);
    }

    public function generateReport(Backtester $backtester, ?string $tickerForChart = null): void
    {
        $styles = file_get_contents(__DIR__ . '/../assets/styles.min.css');
        $chartJs = file_get_contents(__DIR__ . '/../assets/chart.js');
        $html = file_get_contents(__DIR__ . '/../assets/page.html');
        $title = 'Backtest Results - ' . $backtester->getStrategyName();
        $date = date('Y-m-d H:i');

        $tradeLog = $backtester->getTradeLog();
        $tradeStats = $backtester->getTradeStats($tradeLog);
        $netProfit = number_format((float)$tradeStats['net_profit'], 2) . '<br/>' . $backtester->getProfitPercent() . '%';
        //$capitalGraph = '<img src="data:image/png;base64,' . base64_encode($this->graphs->generateCapitalGraph($tradeStats['capital_log'])) . '" />';
        $stockChart = '<p>None</p>';
        if ($tickerForChart !== null) {
            $asset = $backtester->getAssets()->getAsset($tickerForChart, $backtester->getBacktestStartTime());
            if ($asset) {
                $stockChart = '<img src="data:image/png;base64,' . base64_encode($this->graphs->generateStockChart($asset->getRawData())) . '" />';
            }
        }

        $params = [
            '%title%' => $title,
            '%backtest_date%' => $date,
            '%styles%' => $styles,
            '%chart_js%' => $chartJs,
            "'%capital_labels%'" => implode(',', array_keys($tradeStats['capital_log'])),
            "'%capital_data%'" => implode(',', $tradeStats['capital_log']),
            "'%drawdown_data%'" => implode(',', $tradeStats['drawdown_log']),
            '%net_profit%' => $netProfit,
            '%closed_transactions%' => number_format(count($tradeLog)),
            '%profitable_transactions%' => number_format((float)$tradeStats['profitable_transactions']),
            '%profit_factor%' => number_format($tradeStats['profit_factor'], 2),
            '%max_drawdown%' => number_format((float)$tradeStats['max_drawdown_value'], 2) . '<br/>' . number_format((float)$tradeStats['max_drawdown_percent'], 2),
            '%avg_profit%' => number_format((float)$tradeStats['avg_profit'], 2),
            '%avg_bars%' => number_format(floor((float)$tradeStats['avg_bars'])),
            //'%capital_graph%' => $capitalGraph,
            '%stock_chart%' => $stockChart,

            '%detailed_stats%' => $this->formatDetailedStats($tradeStats),
            '%transactions_history%' => $this->formatTransactionHistory($tradeLog, $tradeStats)
        ];
        $params["'%benchmark_data%'"] = empty($tradeStats['benchmark_log']) ? '' : ", {
                label: 'Benchmark capital log - {$backtester->getBenchmarkTicker()} ($)',
                yAxisID: 'y',
                order: 2,
                data: [" . implode(',', $tradeStats['benchmark_log']) . "]
            }";
        $output = strtr($html, $params);
        file_put_contents($this->reportPath . '/report.html', $output);
    }

    protected function formatDetailedStats(array $tradeStats)
    {
        $stats = [];
        $stats[] = '<tr>' .
            '<th>Net profit</th>' .
            '<td class="text-right">' . number_format($tradeStats['net_profit'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['net_profit_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['net_profit_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Gross profit</th>' .
            '<td class="text-right">' . number_format($tradeStats['gross_profit_longs'] + $tradeStats['gross_profit_shorts'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['gross_profit_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['gross_profit_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Gross loss</th>' .
            '<td class="text-right">' . number_format($tradeStats['gross_loss_longs'] + $tradeStats['gross_loss_shorts'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['gross_loss_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['gross_loss_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Profit factor</th>' .
            '<td class="text-right">' . number_format($tradeStats['profit_factor'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profit_factor_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profit_factor_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>Sharpe ratio</th>' .
            '<td class="text-right">' . number_format($tradeStats['sharpe_ratio'], 2) . '</td>' .
            '<td class="text-right"></td>' .
            '<td class="text-right"></td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Max drawdown</th>' .
            '<td class="text-right">' . number_format($tradeStats['max_drawdown_value'], 2) . '</td>' .
            '<td class="text-right"></td>' .
            '<td class="text-right"></td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Max quantity</th>' .
            '<td class="text-right">' . number_format($tradeStats['max_quantity_longs'] + $tradeStats['max_quantity_shorts'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['max_quantity_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['max_quantity_shorts'], 2) . '</td>' .
        '</tr>';
        $stats[] = '<tr>' .
            '<th>All transactions</th>' .
            '<td class="text-right">' . number_format(
                    $tradeStats['profitable_transactions_long_count'] +
                    $tradeStats['profitable_transactions_short_count'] +
                    $tradeStats['losing_transactions_long_count'] +
                    $tradeStats['losing_transactions_short_count']
                ) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profitable_transactions_long_count'] + $tradeStats['losing_transactions_long_count']) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profitable_transactions_short_count'], $tradeStats['losing_transactions_short_count']) . '</td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Profitable transactions</th>' .
            '<td class="text-right">' . number_format($tradeStats['profitable_transactions_long_count'] + $tradeStats['profitable_transactions_short_count']) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profitable_transactions_long_count']) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['profitable_transactions_short_count']) . '</td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Losing transactions</th>' .
            '<td class="text-right">' . number_format($tradeStats['losing_transactions_long_count'] + $tradeStats['losing_transactions_short_count']) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['losing_transactions_long_count']) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['losing_transactions_short_count']) . '</td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Average transaction</th>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profit'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profit_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profit_shorts'], 2) . '</td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Average profitable transaction</th>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profitable_transaction'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profitable_transaction_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_profitable_transaction_shorts'], 2) . '</td>' .
            '</tr>';
        $stats[] = '<tr>' .
            '<th>Average losing transaction</th>' .
            '<td class="text-right">' . number_format($tradeStats['avg_losing_transaction'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_losing_transaction_longs'], 2) . '</td>' .
            '<td class="text-right">' . number_format($tradeStats['avg_losing_transaction_shorts'], 2) . '</td>' .
            '</tr>';
        return implode("\n", $stats);
    }

    protected function formatTransactionHistory(array $tradeLog, array $tradeStats): string
    {
        $rows = [];
        $i = 1;
        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $row = '<tr>' .
                    '<td rowspan="2" class="text-right"><strong>' . $i . '</strong></td>' .
                    '<td rowspan="2">' . $position->getTicker() . '</td>' .
                    '<td class="text-right">' . $position->getOpenTime()->getDateTime() . '</td>' .
                    '<td>' . $position->getSide()->value . ' OPEN' . ($position->getOpenComment() ? ' - ' . $position->getOpenComment() : '') . '</td>' .
                    '<td class="text-right">' . number_format($position->getOpenPrice(), 2) . '</td>' .
                    '<td rowspan="2" class="text-right">' . $position->getQuantity() . '</td>' .
                    '<td rowspan="2" class="text-right">' . $position->getProfitAmount() . '<br/>' . $this->textColor(
                        $position->getProfitPercent() . '%',
                    $position->getProfitPercent() < 0.00001 ? 'red' : 'green'
                    ) . '</td>' .
                    '<td rowspan="2" class="text-right">' . number_format($position->getPortfolioBalance(), 2) . '</td>' .
                    '<td rowspan="2" class="text-right">' . number_format($position->getMaxDrawdownValue(), 2) . '<br/>' .
                        $this->textColor(
                            number_format($position->getMaxDrawdownPercent(), 2) . '%',
                            $position->getMaxDrawdownPercent() > 0 ? 'red' : null
                        ) .
                    '</td>' .
                '</tr>';
            $row .= '<tr>' .
                    '<td class="text-right">' . $position->getCloseTime()->getDateTime() . '</td>' .
                    '<td>' . $position->getSide()->value . ' CLOSE' . ($position->getCloseComment() ? ' - ' . $position->getCloseComment() : '') . '</td>' .
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