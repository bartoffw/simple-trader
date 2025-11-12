<?php

namespace SimpleTrader\Services;

use SimpleTrader\Backtester;
use SimpleTrader\BaseStrategy;
use SimpleTrader\Reporting\HtmlReport;

/**
 * Embedded Report Generator
 *
 * Generates standalone HTML reports with all assets (CSS, JS) embedded
 * Reports work offline without external dependencies
 */
class EmbeddedReportGenerator extends HtmlReport
{
    public function __construct()
    {
        // Use a temporary path, we'll return the HTML directly
        parent::__construct(sys_get_temp_dir());
    }

    /**
     * Generate and return HTML report as string
     *
     * @param Backtester $backtester
     * @param array $tickers
     * @return string HTML report content
     */
    public function generateReport(Backtester $backtester, array $tickers): string
    {
        $title = 'Backtest Results - ' . $backtester->getStrategyName();
        $date = date('Y-m-d H:i:s');

        if ($backtester->getStrategies()) {
            // Optimization report
            return $this->generateOptimizationReport($date, $title, $tickers, $backtester, $backtester->getStrategies());
        } else {
            // Simple report
            return $this->generateSimpleReport($date, $title, $tickers, $backtester, $backtester->getStrategy());
        }
    }

    /**
     * Override parent to return HTML instead of writing to file
     */
    protected function generateSimpleReport(string $date, string $title, array $tickers, Backtester $backtester,
                                            BaseStrategy $strategy): string
    {
        // Load embedded assets
        $styles = file_get_contents(__DIR__ . '/../assets/styles.min.css');
        $scripts = file_get_contents(__DIR__ . '/../assets/chart.js');
        $html = file_get_contents(__DIR__ . '/../assets/page.html');

        $tradeLog = $strategy->getTradeLog();
        $tradeStats = $strategy->getTradeStats($tradeLog);

        // Prepare benchmark data if available
        $benchmarkData = '';
        if ($backtester->getBenchmarkName()) {
            $benchmarkData = ", {
                label: 'Benchmark: " . $backtester->getBenchmarkName() . " (%)',
                yAxisID: 'y1',
                order: 2,
                type: 'line',
                data: [" . implode(',', $tradeStats['benchmark_log'] ?? []) . "]
            }";
        }

        $params = [
            '%title%' => htmlspecialchars($title),
            '%backtest_date%' => htmlspecialchars($date),
            '%tickers%' => htmlspecialchars(implode(', ', $tickers)),
            '%period%' => $backtester->getBacktestStartTime()->toDateString() . ' to ' . $backtester->getBacktestEndTime()->toDateString(),
            '%backtest_parameters%' => implode('', $this->formatStrategyParams($strategy->getParameters())),
            '%styles%' => $styles,
            '%scripts%' => $scripts,
            "'%capital_labels%'" => "'" . implode("','", array_keys($tradeStats['capital_log'])) . "'",
            "'%capital_data%'" => implode(',', $tradeStats['capital_log']),
            "'%drawdown_data%'" => implode(',', $tradeStats['position_drawdown_log']),
            "'%benchmark_data%" => $benchmarkData,
            '%net_profit%' => number_format($tradeStats['net_profit'], 2) . '<br/>' . number_format($tradeStats['net_profit_percent'], 2) . '%',
            '%closed_transactions%' => number_format(count($tradeLog)),
            '%profitable_transactions%' => number_format((float)$tradeStats['profitable_transactions']),
            '%profit_factor%' => number_format($tradeStats['profit_factor'], 2),
            '%max_strategy_drawdown%' => number_format((float)$tradeStats['max_strategy_drawdown_value'], 2) . '<br/>' . number_format((float)$tradeStats['max_strategy_drawdown_percent'], 2) . '%',
            '%avg_profit%' => number_format((float)$tradeStats['avg_profit'], 2),
            '%avg_bars%' => number_format(floor((float)$tradeStats['avg_bars_transaction'])),
            '%detailed_stats%' => $this->formatDetailedStats($tradeStats),
            '%transactions_history%' => $this->formatTransactionsHistory($tradeLog, $tradeStats),
            '%backtest_time%' => 'Generated on ' . $date
        ];

        return str_replace(array_keys($params), array_values($params), $html);
    }

    protected function formatDetailedStats(array $stats): string
    {
        $rows = [
            ['Gross profit', $stats['gross_profit'] ?? 0, $stats['gross_profit_longs'] ?? 0, $stats['gross_profit_shorts'] ?? 0],
            ['Gross loss', $stats['gross_loss'] ?? 0, $stats['gross_loss_longs'] ?? 0, $stats['gross_loss_shorts'] ?? 0],
            ['Profitable transactions', $stats['profitable_transactions'] ?? 0, $stats['profitable_transactions_longs'] ?? 0, $stats['profitable_transactions_shorts'] ?? 0],
            ['Losing transactions', $stats['losing_transactions'] ?? 0, $stats['losing_transactions_longs'] ?? 0, $stats['losing_transactions_shorts'] ?? 0]
        ];

        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . $row[0] . '</td>';
            $html .= '<td class="text-right">' . number_format($row[1], 2) . '</td>';
            $html .= '<td class="text-right">' . number_format($row[2], 2) . '</td>';
            $html .= '<td class="text-right">' . number_format($row[3], 2) . '</td>';
            $html .= '</tr>';
        }
        return $html;
    }

    protected function formatTransactionsHistory(array $tradeLog, array $stats): string
    {
        $html = '';
        $counter = 1;
        foreach ($tradeLog as $trade) {
            $profitClass = $trade['profit'] >= 0 ? 'text-green' : 'text-red';
            $html .= '<tr>';
            $html .= '<td>' . $counter++ . '</td>';
            $html .= '<td>' . htmlspecialchars($trade['ticker']) . '</td>';
            $html .= '<td>' . $trade['open_time'] . '<br/>' . $trade['close_time'] . '</td>';
            $html .= '<td>' . $trade['side'] . '</td>';
            $html .= '<td class="text-right">' . number_format($trade['open_price'], 2) . '<br/>' . number_format($trade['close_price'], 2) . '</td>';
            $html .= '<td class="text-right">' . number_format($trade['quantity'], 2) . '</td>';
            $html .= '<td class="text-right ' . $profitClass . '">' . number_format($trade['profit'], 2) . '<br/>' . number_format($trade['profit_percent'], 2) . '%</td>';
            $html .= '<td class="text-right">' . number_format($trade['balance'], 2) . '</td>';
            $html .= '<td class="text-right">' . number_format($trade['position_drawdown_value'], 2) . '<br/>' . number_format($trade['position_drawdown_percent'], 2) . '%</td>';
            $html .= '</tr>';
        }
        return $html;
    }

    /**
     * Generate optimization report with results table
     */
    protected function generateOptimizationReport(string $date, string $title, array $tickers, Backtester $backtester, array $strategies): string
    {
        // For optimization, generate simple report for best strategy + optimization table
        $bestStrategy = $backtester->getBestStrategy();
        $baseReport = $this->generateSimpleReport($date, $title . ' (Best Result)', $tickers, $backtester, $bestStrategy);

        // Insert optimization results table before the detailed stats
        $optimizationTable = $this->generateOptimizationTable($strategies);
        $baseReport = str_replace('<h2>Detailed stats</h2>',
            '<h2>Optimization Results</h2>' . $optimizationTable . '<h2>Detailed stats (Best Strategy)</h2>',
            $baseReport);

        return $baseReport;
    }

    protected function generateOptimizationTable(array $strategies): string
    {
        $html = '<table class="striped"><thead><tr>';
        $html .= '<th>Parameters</th>';
        $html .= '<th class="text-right">Net Profit</th>';
        $html .= '<th class="text-right">Net Profit %</th>';
        $html .= '<th class="text-right">Transactions</th>';
        $html .= '<th class="text-right">Profit Factor</th>';
        $html .= '<th class="text-right">Max Drawdown %</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($strategies as $strategy) {
            $tradeLog = $strategy->getTradeLog();
            $tradeStats = $strategy->getTradeStats($tradeLog);
            $params = $strategy->getParameters();

            $paramStr = [];
            foreach ($params as $key => $value) {
                $paramStr[] = $key . '=' . $value;
            }

            $profitClass = $tradeStats['net_profit'] >= 0 ? 'text-green' : 'text-red';

            $html .= '<tr>';
            $html .= '<td><code>' . implode(', ', $paramStr) . '</code></td>';
            $html .= '<td class="text-right ' . $profitClass . '">' . number_format($tradeStats['net_profit'], 2) . '</td>';
            $html .= '<td class="text-right ' . $profitClass . '">' . number_format($tradeStats['net_profit_percent'], 2) . '%</td>';
            $html .= '<td class="text-right">' . count($tradeLog) . '</td>';
            $html .= '<td class="text-right">' . number_format($tradeStats['profit_factor'], 2) . '</td>';
            $html .= '<td class="text-right">' . number_format($tradeStats['max_strategy_drawdown_percent'], 2) . '%</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }
}
