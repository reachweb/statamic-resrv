<?php

namespace Reach\StatamicResrv\Dictionaries;

use Statamic\Dictionaries\BasicDictionary;
use Statamic\Dictionaries\Item;

class CountryPhoneCodes extends BasicDictionary
{
    protected string $valueKey = 'iso';

    protected string $labelKey = 'name';

    protected array $searchable = ['name', 'code'];

    protected function getItems(): array
    {
        return trans('statamic-resrv::country_phone_codes');
    }

    public function options(?string $search = null): array
    {
        $items = $this->getItems();
        $options = [];

        foreach ($items as $key => $item) {
            $options[] = [
                'value' => $item['iso'] ?? $key,
                'label' => $item['name'] ?? $key,
                'code' => $item['code'] ?? '',
            ];
        }

        return $options;
    }

    public function get(string $key): ?Item
    {
        return new Item($key, $key, []);
    }
}
