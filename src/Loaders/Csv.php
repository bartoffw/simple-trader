<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\Event;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Ohlc;

class Csv extends BaseLoader implements LoaderInterface
{
    protected array $columns = [];
    protected array $data = [];
    protected ?DateTime $fromDate = null;


    private function __construct(protected string $ticker, protected ?string $filePath = null,
                                 protected ?array $sourceData = null, protected string $dateField = 'Date',
                                 protected ?DateTime $limitToDate = null, protected ?Event $event = null) {}

    public static function fromFile(string $ticker, string $filePath, string $dateField = 'Date'): static
    {
        return new static($ticker, $filePath, null, $dateField);
    }

    public static function fromLoaderLimited(LoaderInterface $loader, DateTime $limitToDate, Event $event): static
    {
        return new static($loader->getTicker(), null, $loader->getData(), $loader->getDateField(), $limitToDate, $event);
    }


    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getFromDate(): ?DateTime
    {
        return $this->fromDate;
    }

    public function getDateField(): string
    {
        return $this->dateField;
    }

    /**
     * @throws LoaderException
     */
    public function loadData(?DateTime $fromDate = null): bool
    {
        if ($this->isLoaded) {
            return true;
        }
        if ($this->sourceData === null) {
            $this->loadDataFromFile($fromDate);
        } else {
            $this->loadDataLimited($this->limitToDate);
        }

        return true;
    }

    public function getData(?string $column = null): array
    {
        if ($column !== null) {
            return $this->data[$column] ?? [];
        }
        return $this->data;
    }

    public function getCurrentValues(DateTime $dateTime): Ohlc
    {
        $datePosition = array_search($dateTime->getDateTime(), $this->getData($this->getDateField()));
        if ($datePosition === false) {
            // TODO: add searching for the nearest date
            throw new LoaderException('Date ' . $dateTime->getDateTime() . ' not found in ' . $this->getTicker());
        }
        $open = array_slice($this->getData('Open'), $datePosition, 1)[0];
        return new Ohlc(
            $dateTime,
            $open,
            $this->event === Event::OnOpen ? $open : array_slice($this->getData('High'), $datePosition, 1)[0],
            $this->event === Event::OnOpen ? $open : array_slice($this->getData('Low'), $datePosition, 1)[0],
            $this->event === Event::OnOpen ? $open : array_slice($this->getData('Close'), $datePosition, 1)[0],
            $this->event === Event::OnOpen ? 0 : array_slice($this->getData('Volume'), $datePosition, 1)[0]
        );
    }


    protected function setData(string $column, array $data)
    {
        $this->data[$column] = $data;
    }

    protected function loadDataFromFile($fromDate): void
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
    }

    protected function loadDataLimited(DateTime $limitToDate): void
    {
        $this->columns = array_keys($this->sourceData);
        $dateColumnData = $this->sourceData[$this->getDateField()];
        $datePosition = array_search($limitToDate->getDateTime(), $dateColumnData);
        if ($datePosition === false) {
            // TODO: add searching for the nearest date
            throw new LoaderException('Date ' . $limitToDate->getDateTime() . ' not found in ' . $this->getTicker());
        }
        foreach ($this->columns as $column) {
            $colData = $this->sourceData[$column];
            $this->setData($column, array_slice($colData, 0, $datePosition + 1));
        }
        $this->isLoaded = true;
    }
}