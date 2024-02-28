<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\DateTime;
use SimpleTrader\Event;
use SimpleTrader\Exceptions\LoaderException;

class Csv extends BaseLoader implements LoaderInterface
{
    protected array $columns = [];
    protected array $data = [];
    protected ?DateTime $fromDate = null;


    public function __construct(protected string $ticker, protected string $filePath, protected string $dateField = 'Date') { }

    public function getTicker():string
    {
        return $this->ticker;
    }

    public function getFromDate():?DateTime
    {
        return $this->fromDate;
    }

    /**
     * @throws LoaderException
     */
    public function loadData(?DateTime $fromDate = null): bool
    {
        $this->fromDate = $fromDate;
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new LoaderException('Could not load the CSV file');
        }
        while (($line = fgetcsv($handle)) !== false) {
            if (empty($this->data)) {
                $this->columns = $line;
                foreach ($line as $column) {
                    $this->data[$column] = [];
                }
                continue;
            }

            $lineData = [];
            $hasData = false;
            foreach ($line as $colId => $value) {
                $colName = $this->columns[$colId];
                $parsedValue = $value !== 'null' ? $value : null;
                if ($colName !== $this->dateField && $parsedValue !== null) {
                    $hasData = true;
                }
                if ($fromDate !== null && $colName === $this->dateField && $parsedValue < $fromDate->getDateTime()) {
                    continue 2;
                }
                // TODO
                $this->data[$colName][] = $parsedValue;
            }
        }
        fclose($handle);
        $this->isLoaded = true;

        return true;
    }

    public function getData(?string $column = null): array
    {
        if ($column !== null) {
            return $this->data[$column] ?? [];
        }
        return $this->data;
    }

    public function limitToDate(DateTime $dateTime, Event $event): LoaderInterface
    {
        $limitedLoader = new Csv($this->filePath, $this->dateField);
        $dateColumnData = $this->getData($this->dateField);
        $datePosition = array_search($dateTime->getDateTime(), $dateColumnData);
        if ($datePosition === false) {
            // TODO: add searching for the nearest date
            throw new LoaderException('Date ' . $dateTime->getDateTime() . ' not found in ' . $this->ticker);
        }
        foreach ($this->columns as $column) {
            $colData = $this->getData($column);
            $limitedLoader->setData($column, array_slice($colData, 0, $datePosition + 1));
        }
        $limitedLoader->isLoaded = true;
        return $limitedLoader;
    }

    protected function setData(string $column, array $data)
    {
        $this->data[$column] = $data;
    }
}