<?php

namespace Reach\StatamicResrv\Helpers;

use Spatie\SimpleExcel\SimpleExcelReader;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class DataImport
{
    private $path;

    private $collectionHandle;

    private $delimiter;

    private $identifier;

    public function __construct($path, $delimiter, $collection, $identifier)
    {
        $this->path = $path;
        $this->collectionHandle = $collection;
        $this->delimiter = $delimiter;
        $this->identifier = $identifier;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function checkForErrors()
    {
        $errors = collect();

        $identifier = $this->collectionHasIdentifier();
        if ($identifier) {
            $errors->push($identifier);
        }

        $headers = $this->headersHaveCorrectFormat();
        if ($headers) {
            $errors->push($headers);
        }

        return $errors;
    }

    public function prepare($sample = false)
    {
        $reader = SimpleExcelReader::create($this->path)
            ->useDelimiter($this->delimiter);

        if ($sample) {
            $reader = SimpleExcelReader::create($this->path)
                ->useDelimiter($this->delimiter)->take(3);
        }

        // Aggregate by entry id: multiple CSV rows for the same entry (e.g. one row per rate)
        // must accumulate. mapWithKeys would keep only the last row, silently dropping the rest.
        $import = $reader
            ->getRows()
            ->reduce(function ($import, $row) {
                $id = $this->getId($row[$this->identifier]);
                if ($id == false) {
                    return $import;
                }
                $data = collect();
                $index = 0;
                foreach ($row as $header => $value) {
                    if (strpos($header, 'price') !== false) {
                        $arrayToPush = [];
                        $dates = $this->getDatesFromHeader($header);
                        $arrayToPush['date_start'] = $dates['date_start'];
                        $arrayToPush['date_end'] = $dates['date_end'];
                        $arrayToPush['available'] = $row[array_keys($row)[$index + 1]];
                        $arrayToPush['price'] = $value;
                        if (array_key_exists('rate_id', $row)) {
                            $arrayToPush['rate_id'] = $row['rate_id'];
                        }
                        $data->push($arrayToPush);
                    }
                    $index++;
                }

                return $import->put($id, $import->get($id, collect())->concat($data));
            }, collect());

        if ($sample) {
            return $import->take(1);
        }

        return $import;
    }

    private function getDatesFromHeader($header)
    {
        // Parse "price:2024-01-01|2024-01-10" headers without throwing; blanks are passed through
        // so ProcessDataImport's per-row validation can skip them rather than aborting the import.
        $afterColon = explode(':', $header, 2)[1] ?? '';
        $dates = explode('|', $afterColon);

        return [
            'date_start' => trim($dates[0] ?? ''),
            'date_end' => trim($dates[1] ?? ''),
        ];
    }

    // Resolve the collection on demand rather than storing the resolved object. The controller
    // caches this DataImport (file/redis/database drivers serialize the value), so keeping only
    // the handle avoids serializing the whole Statamic Collection object graph.
    private function collection(): ?\Statamic\Contracts\Entries\Collection
    {
        return Collection::findByHandle($this->collectionHandle);
    }

    private function collectionHasIdentifier()
    {
        if ($this->identifier == 'id') {
            return false;
        }
        foreach ($this->collection()->entryBlueprints() as $blueprint) {
            if ($blueprint->fields()->all()->has($this->identifier)) {
                return false;
            }
        }

        return 'The identifier "'.$this->identifier.'" cannot be found in the collection you selected.';
    }

    private function getId($value)
    {
        if ($this->identifier == 'id') {
            return $value;
        }

        $entry = $this->collection()
            ->queryEntries()
            ->where($this->identifier, $value)
            ->where('site', Site::default())
            ->first();
        if ($entry) {
            return $entry->id();
        }

        return false;
    }

    private function headersHaveCorrectFormat()
    {
        $headers = SimpleExcelReader::create($this->path)->useDelimiter($this->delimiter)->getHeaders();
        $priceHeaderFound = false;
        $availabilityHeaderFound = false;
        foreach ($headers as $header) {
            if (strpos($header, 'price') !== false) {
                $priceHeaderFound = true;
                // A price header drives the date range, so it must carry one (price:date_start|date_end).
                // Without this, a header literally named "price" would import every row as a skipped no-op.
                $dates = $this->getDatesFromHeader($header);
                if ($dates['date_start'] === '' || $dates['date_end'] === '') {
                    return 'A price header is missing its date range, expected e.g. "price:2024-01-01|2024-01-10".';
                }
            }
            if (strpos($header, 'availability') !== false) {
                $availabilityHeaderFound = true;
            }
        }
        if ($availabilityHeaderFound && $priceHeaderFound) {
            return false;
        }

        return 'The headers are not properly formatted';
    }
}
