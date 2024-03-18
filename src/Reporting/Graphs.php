<?php

namespace SimpleTrader\Reporting;

use Graph;
use LinePlot;
use mitoteam\jpgraph\MtJpGraph;
use StockPlot;

class Graphs
{
    public function __construct(protected string $reportPath)
    {

    }

    public function generateCapitalGraph(array $capitalLog): string
    {
        MtJpGraph::load(['line']);

        $graph = new Graph(1280, 800);
        $graph->SetScale('intlin');

        $graph->SetMargin(80,20,20,40);
        $graph->title->Set('Strategy capital Log');
        $graph->xaxis->title->Set('Trade #');
        $graph->yaxis->title->Set('Capital');
        //$graph->SetScale('linlin', 8000, 40000);

        $capitalPlot = new LinePlot($capitalLog);
        $capitalPlot->SetColor('black');
        $capitalPlot->AddArea(0, count($capitalLog) - 1, '#f00');
        $graph->Add($capitalPlot);

        $graph->Stroke(_IMG_HANDLER);
        ob_start();
        $graph->img->Stream();
        return ob_get_clean();
    }

    public function generateStockChart(array $stockData): string
    {
        $plotData = [];
        foreach ($stockData as $bar) {
            $plotData[] = $bar['open'];
            $plotData[] = $bar['high'];
            $plotData[] = $bar['low'];
            $plotData[] = $bar['close'];
        }

        MtJpGraph::load(['stock']);

        $graph = new Graph(1280, 800);
        $graph->SetScale("textlin");
        $graph->SetMarginColor('lightblue');
        $graph->SetMargin(80,20,20,40);
        $graph->title->Set('Stock chart');
        $graph->xaxis->title->Set('Date');
        $graph->yaxis->title->Set('Price');

        $stockPlot = new StockPlot($plotData);
        $stockPlot->SetWidth(4);
        $stockPlot->HideEndLines();
        $graph->Add($stockPlot);

        $graph->Stroke(_IMG_HANDLER);
        ob_start();
        $graph->img->Stream();
        return ob_get_clean();
    }
}