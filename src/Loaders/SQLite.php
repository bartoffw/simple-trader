<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\Event;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Asset;
use SQLite3;

class SQLite
{
    protected SQLite3 $db;


    public function __construct(protected string $filePath)
    {
        $this->db = new SQLite3($filePath);
    }

    public function importData(string $ticker, array $data, array $columns = [
        'date' => 'Date', 'open' => 'Open', 'high' => 'High', 'low' => 'Low', 'close' => 'Close', 'volume' => 'Volume'
    ]): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$ticker} (
                id INTEGER PRIMARY KEY,
                date_time DATETIME UNIQUE,
                open DECIMAL(12,4),
                high DECIMAL(12,4),
                low DECIMAL(12,4),
                close DECIMAL(12,4),
                volume INT UNSIGNED NULL DEFAULT NULL
            );
        ");
        if (!empty($data[$columns['date']])) {
            for ($i = 0; $i < count($data[$columns['date']]); $i++) {
                $date = $data[$columns['date']][$i];
                $open = $data[$columns['open']][$i];
                $high = $data[$columns['high']][$i];
                $low = $data[$columns['low']][$i];
                $close = $data[$columns['close']][$i];
                $volume = $data[$columns['volume']][$i] ?? null;
                if (!empty($date) && !empty($open) && !empty($high) && !empty($low) && !empty($close)) {
                    $stmt = $this->db->prepare("
                        INSERT OR IGNORE INTO {$ticker}
                        (
                            date_time, open, high, low, close, volume
                        )
                        VALUES
                        (
                            :date, :open, :high, :low, :close, :volume
                        );
                    ");
                    $stmt->bindValue(':table', $ticker);
                    $stmt->bindValue(':date', $date);
                    $stmt->bindValue(':open',$open);
                    $stmt->bindValue(':high',$high);
                    $stmt->bindValue(':low',$low);
                    $stmt->bindValue(':close',$close);
                    $stmt->bindValue(':volume',$volume);
                    $stmt->execute();
                }
            }
        }
        $this->db->close();
    }

    public function loadAsset(Asset $baseAsset, DateTime $fromDate, ?DateTime $toDate = null, ?Event $event = null): Asset
    {
        $ticker = $baseAsset->getTicker();
        $startDate = $fromDate->getDateTime();
        $newStartDate = $baseAsset->getLastDateTime();
        $data = [];
        if ($newStartDate && $newStartDate > $startDate) {
            $startDate = $newStartDate;
            $data = $baseAsset->getRawData();
        }

        $stmt = $this->db->prepare("
            SELECT
                *
            FROM
                {$ticker}
            WHERE
                date_time >= :start_date
                " . ($toDate ?
                'AND date_time <= :end_date' : '') . "
            ORDER BY
                date_time ASC
            ;
        ");
        $stmt->bindValue(':start_date', $startDate);
        if ($toDate) {
            $stmt->bindValue(':end_date', $toDate->getDateTime());
        }
        $results = $stmt->execute();
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $data[$row['date_time']] = $row;
        }
        return new Asset($ticker, $data, $event);
    }
}