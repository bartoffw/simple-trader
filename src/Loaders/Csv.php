<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\DateTime;
use SimpleTrader\Exceptions\LoaderException;

class Csv extends BaseLoader implements LoaderInterface
{
    protected array $columns = [];
    protected array $data = [];

    public function __construct(protected string $filePath, protected string $dateField = 'Date') { }

    /**
     * @throws LoaderException
     */
    public function loadData(?DateTime $fromDate = null): bool
    {
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
}