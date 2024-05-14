<?php

namespace SimpleTrader;

use Carbon\Carbon;
use MammothPHP\WoollyM\DataFrame;
use SimpleTrader\Exceptions\LoaderException;
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
        self::validateAsset($asset, $ticker);
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

    public function cloneToDate(Carbon $fromDate, Carbon $toDate, ?Assets $existingAssets = null): Assets
    {
        $assets = new Assets();
        foreach ($this->assetList as $ticker => $df) {
//            if ($existingAssets) {
//                $latestExistingDate = $existingAssets->getLatestDate($ticker);
//                if ($latestExistingDate->toDateString() === $da)
//            }
            $assets->addAsset(self::cloneAssetToDate($df, $fromDate, $toDate), $ticker);
        }
        return $assets;
    }

    public function getLatestOhlc(string $ticker, ?Carbon $forDate = null): ?Ohlc
    {
        $asset = $this->getAsset($ticker);
        if ($asset === null) {
            throw new LoaderException('Asset not found in the asset list for an open position: ' . $ticker);
        }
        $latestFrame = $forDate ?
            $asset->selectAll()
                ->where(fn($record, $recordKey) => (new Carbon($record['date'])) <= $forDate)
                ->limit(1)
                ->toArray() :
            $asset->head(1);
        return $latestFrame ?
            new Ohlc(new Carbon($latestFrame[0]['date']), $latestFrame[0]['open'], $latestFrame[0]['high'], $latestFrame[0]['low'], $latestFrame[0]['close'], $latestFrame[0]['volume']) :
            null;
    }

    public function getCurrentValue(string $ticker, ?Carbon $forDate = null, Event $event = Event::OnClose): ?string
    {
        $ohlc = $this->getLatestOhlc($ticker, $forDate);
        return $ohlc ?
            match($event) {
                Event::OnOpen => $ohlc->getOpen(),
                Event::OnClose => $ohlc->getClose()
            } :
            null;
    }

    public function getLatestDate(string $ticker): ?Carbon
    {
        $asset = $this->getAsset($ticker);
        if ($asset === null) {
            throw new LoaderException('Asset not found in the asset list for an open position: ' . $ticker);
        }
        $df = $asset->head(1);
        return $df ? new Carbon($df[0]['date']) : null;
    }

    public static function getColumns(): array
    {
        return self::$columns;
    }

    public static function validateAsset(DataFrame $asset, $ticker)
    {
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
    }

    public static function cloneAssetToDate(DataFrame $df, Carbon $fromDate, Carbon $toDate): DataFrame
    {
        return $df->selectAll()
            ->where(fn($record, $recordKey) => (new Carbon($record['date']))->between($fromDate, $toDate))->export();
    }
}