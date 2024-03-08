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

    public function generateCapitalGraph(array $capitalLog)
    {
        MtJpGraph::load(['line']);

        $graph = new Graph(1280, 800);
        $graph->SetScale('intlin');

        $graph->SetMargin(40,20,20,40);
        $graph->title->Set('Strategy capital Log');
        $graph->xaxis->title->Set('Trade #');
        $graph->yaxis->title->Set('Capital');

        $linePlot = new LinePlot($capitalLog);
        $graph->Add($linePlot);

        $gdImgHandler = $graph->Stroke(_IMG_HANDLER);
        $graph->img->Stream($this->reportPath . '/capital.png');
    }
}