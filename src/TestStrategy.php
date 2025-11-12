<?php

namespace SimpleTrader;

use Carbon\Carbon;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\Level;

class TestStrategy extends BaseStrategy
{
    protected string $strategyName = 'SMA Baseline Strategy';
    protected string $strategyDescription = 'A simple moving average (SMA) based strategy that enters long positions when price crosses above the SMA and exits when price falls below it. Uses a configurable baseline period and selects the best ticker based on SMA momentum. Suitable for trending markets.';

    protected array $strategyParameters = [
        'length' => 30
    ];

    protected string|bool $buyTicker = false;
    protected bool $closeFlag = false;
    protected string $openComment = '';
    protected string $closeComment = '';


    public function getMaxLookbackPeriod(): int
    {
        // tells the backtester how far in the past we need data
        return $this->strategyParameters['length'];
    }

    /**
     * @throws StrategyException
     */
    public function onOpen(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        parent::onOpen($assets, $dateTime, $isLive);

        // buy or sell on open whatever was set based on the close prices
        if ($this->buyTicker) {
            $position = $this->entry(Side::Long, $this->buyTicker, comment: $this->openComment);
            $this->currentPositions[$position->getId()] = $position;
            $this->buyTicker = false;
        }
        if ($this->closeFlag) {
            $this->closeAll($this->closeComment);
            $this->currentPositions = [];
            $this->closeFlag = false;
        }
    }

    public function onClose(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        parent::onClose($assets, $dateTime, $isLive);

        $smaValues = [];
        foreach ($this->tickers as $ticker) {
            $asset = $assets->getAsset($ticker);
            if ($asset->count() < $this->getMaxLookbackPeriod()) {
                $this->strategyLog($isLive, "[{$ticker}] Not enough history ({$asset->count()} vs {$this->getMaxLookbackPeriod()}), skipping...");
                return;
            }

            $closes = [];
            $closesDf = $asset->col('close')->export();
            foreach ($closesDf as $df) {
                $closes[] = $df['close'];
            }

            $sma = array_values(trader_sma($closes, $this->strategyParameters['baselineLength']));
            if (count($sma) < 2) {
                $this->strategyLog($isLive, "[{$ticker}] Not enough SMA data (" . count($sma) . " vs 2), skipping...");
                return;
            }
            $smaValues[$ticker] = $sma;

            $this->strategyLog($isLive,
                "[{$ticker}] SMA: " . round($smaValues[$ticker][0], 2) . " vs price: " . round($assets->getCurrentValue($ticker, $dateTime), 2)
            );
        }

        if (!empty($this->currentPositions)) {
            /** @var Position $currentPosition */
            $currentPosition = $this->currentPositions[0];
            // looking for exit
            $ticker = $currentPosition->getTicker();
            $assetVal = $assets->getCurrentValue($ticker, $dateTime);
            $smaVal = $smaValues[$ticker][0];

            if ($assetVal < $smaVal) {
                $this->closeFlag = true;
                $this->closeComment = "Baseline stop, SMA: {$smaVal} vs. price: {$assetVal}";
            }
            if ($this->closeFlag && $isLive) {
                $this->notifier->addSummary("<h4>Action: OnOpen CLOSE {$ticker}</h4>");
                $this->notifier->addSummary("<p>{$this->closeComment}</p>");
                $this->strategyLog($isLive, "[{$ticker}] --== Want to sell ==-- " . $this->closeComment);
            }
        } else {
            // looking for entry
            $bestTicker = null;
            $bestSma = null;
            foreach ($smaValues as $ticker => $sma) {
                $smaDiff = $sma[0] - $sma[1];
                if ($smaDiff > 0.00001 && (!$bestTicker && !$bestSma || $bestSma < $smaDiff)) {
                    $bestTicker = $ticker;
                    $bestSma = $smaDiff;
                }
            }
            if ($bestTicker && $bestSma) {
                $assetVal = $assets->getCurrentValue($bestTicker, $dateTime);
                $smaVal = $smaValues[$bestTicker][0];
                if ($assetVal > $smaVal) {
                    $this->openComment = 'SMA Diff: ' . number_format($bestSma, 1) . '%';
                    $this->buyTicker = $bestTicker;
                    if ($isLive) {
                        $this->notifier->addSummary("<h4>Action: OnOpen BUY {$bestTicker}</h4>");
                        $this->notifier->addSummary("<p>SMA: {$smaVal} vs. price: {$assetVal}</p>");
                        $this->strategyLog($isLive, "[{$bestTicker}] --== Want to buy ==-- SMA: {$smaVal} vs. price: {$assetVal}");
                    }
                }
            }
        }
    }

    /**
     * @throws StrategyException
     */
    public function onStrategyEnd(Assets $assets, Carbon $dateTime, bool $isLive = false): void
    {
        parent::onStrategyEnd($assets, $dateTime, $isLive);
        $this->closeAll('Strategy end');
    }

    protected function strategyLog(bool $isLive, string $message): void
    {
        if ($isLive) {
            $this->notifier->notifyInfo($message);
            $this->logger?->logInfo($message);
        }
    }
}