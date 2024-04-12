<?php

namespace SimpleTrader;

use MammothPHP\WoollyM\DataFrame;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Ohlc;

class Assets
{
    protected static array $columns = [
        'date', 'open', 'high', 'low', 'close', 'volume'
    ];
    protected array $assetList = [];


    public function __construct()
    {
    }

    public function addAsset(DataFrame $asset, string $ticker, bool $replace = false): void
    {
        if (isset($this->assetList[$ticker]) && !$replace) {
            throw new LoaderException('This asset is already loaded: ' . $ticker);
        }
        // check required columns
        $columns = $asset->columnsNames();
        foreach ($columns as $colName) {
            if (!in_array($colName, self::$columns)) {
                throw new LoaderException('Column name invalid for ' . $ticker . ': ' . $colName . '. Allowed columns: ' . implode(', ', self::$columns));
            }
        }
        if (count($columns) !== count(self::$columns)) {
            throw new LoaderException('Column count does not match for ' . $ticker . ': ' . count($columns) . ' vs ' . count(self::$columns));
        }
        $this->assetList[$ticker] = $asset->sortRecordsByColumns(by: 'date', ascending: false);
    }

    public function getAsset(string $ticker): ?DataFrame
    {
        return $this->hasAsset($ticker) ? $this->assetList[$ticker] : null;
    }

    public function getAssets(): array
    {
        return $this->assetList;
    }

    public function hasAsset(string $ticker): bool
    {
        return array_key_exists($ticker, $this->assetList);
    }

    public function isEmpty(): bool
    {
        return empty($this->assetList);
    }

    public function cloneToDate(DateTime $fromDate, DateTime $toDate, ?Assets $existingAssets = null): Assets
    {
        $assets = new Assets();
        foreach ($this->assetList as $ticker => $df) {
//            if ($existingAssets) {
//                $latestExistingDate = $existingAssets->getLatestDate($ticker);
//                if ($latestExistingDate->getDateTime() === $da)
//            }
            $assets->addAsset(
                $df->selectAll()
                    ->where(fn($record, $recordKey) => $record['date'] >= $fromDate->getDateTime() && $record['date'] <= $toDate->getDateTime())
                    ->export(),
                $ticker
            );
        }
        return $assets;
    }

    public function getLatestOhlc(string $ticker, ?DateTime $forDate = null): ?Ohlc
    {
        $asset = $this->getAsset($ticker);
        if ($asset === null) {
            throw new LoaderException('Asset not found in the asset list for an open position: ' . $ticker);
        }
        $latestFrame = $forDate ?
            $asset->selectAll()->where(fn($record, $recordKey) => $record['date'] <= $forDate->getDateTime())->limit(1)->toArray() :
            $asset->head(1);
        return $latestFrame ?
            new Ohlc(new DateTime($latestFrame[0]['date']), $latestFrame[0]['open'], $latestFrame[0]['high'], $latestFrame[0]['low'], $latestFrame[0]['close'], $latestFrame[0]['volume']) :
            null;
    }

    public function getCurrentValue(string $ticker, ?DateTime $forDate = null, Event $event = Event::OnClose): ?string
    {
        $ohlc = $this->getLatestOhlc($ticker, $forDate);
        return $ohlc ?
            match($event) {
                Event::OnOpen => $ohlc->getOpen(),
                Event::OnClose => $ohlc->getClose()
            } :
            null;
    }

    public function getLatestDate(string $ticker): ?DateTime
    {
        $asset = $this->getAsset($ticker);
        if ($asset === null) {
            throw new LoaderException('Asset not found in the asset list for an open position: ' . $ticker);
        }
        $df = $asset->head(1);
        return $df ? new DateTime($df[0]['date']) : null;
    }
}