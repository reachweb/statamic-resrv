<?php

namespace Reach\StatamicResrv\Helpers;

use Carbon\Carbon;
use Spatie\SimpleExcel\SimpleExcelReader;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class DataImport
{
    private $path;

    private $collection;

    private $delimiter;

    private $identifier;

    public function __construct($path, $delimiter, $collection, $identifier)
    {
        $this->path = $path;
        $this->collection = Collection::findByHandle($collection);
        $this->delimiter = $delimiter;
        $this->identifier = $identifier;
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

        $import = $reader
            ->getRows()
            ->mapWithKeys(function ($row) {
                $id = $this->getId($row[$this->identifier]);
                if ($id == false) {
                    $id = 'not-found';
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
                        if (array_key_exists('advanced', $row)) {
                            $arrayToPush['advanced'] = $row['advanced'];
                        }
                        $data->push($arrayToPush);
                    }
                    $index++;
                }

                return [$id => $data];
            })->reject(fn ($item, $id) => $id == 'not-found');

        if ($sample) {
            return $import->take(1);
        }               

        return $import;
    }

    private function getDatesFromHeader($header)
    {
        $dates = explode('|', explode(':', $header)[1]);

        return [
            'date_start' => Carbon::create($dates[0]),
            'date_end' => Carbon::create($dates[1]),
        ];
    }

    private function collectionHasIdentifier()
    {
        if ($this->identifier == 'id') {
            return false;
        }
        foreach ($this->collection->entryBlueprints() as $blueprint) {
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

        $entry = $this->collection
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
