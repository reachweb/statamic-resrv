<?php

namespace Reach\StatamicResrv\Dictionaries;

use Statamic\Dictionaries\BasicDictionary;

class CountryPhoneCodes extends BasicDictionary
{
    protected string $valueKey = 'code';

    protected string $labelKey = 'name';

    protected array $searchable = ['name', 'code'];

    protected function getItems(): array
    {
        return trans('statamic-resrv::country_phone_codes');
    }
}
