<?php

namespace SimpleTrader\Reporting;

use Graph;
use LinePlot;
use mitoteam\jpgraph\MtJpGraph;

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
}