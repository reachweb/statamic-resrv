<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Availability as EntryAvailability;

class Availability extends Fieldtype
{
    // public function augment($value)
    // {   
    //     if ($value == true) {
    //         return EntryAvailability::entry($this->field->parent()->id())
    //             ->get()
    //             ->sortBy('date')
    //             ->keyBy('date')
    //             ->toArray();
    //     }
    //     return false;
    // }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        return ['parent' => $this->field->parent()->id()];
    }

}