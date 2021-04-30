<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Availability as EntryAvailability;

class Availability extends Fieldtype
{
    public function augment($value)
    {   
        if ($value != 'disabled') {
            $availability_data = EntryAvailability::entry($value)->get();

            if ($availability_data->count() == 0) {
                return false;
            }
            $data = $availability_data->sortBy('date')->keyBy('date')->toArray();
            $cheapest = $availability_data->sortBy('price')->firstWhere('available', '>', '0')->price;
            return compact('data', 'cheapest');
        }
        return false;
    }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        return ['parent' => $this->field->parent()->id()];
    }

}