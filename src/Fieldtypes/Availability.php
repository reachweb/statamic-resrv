<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Availability as EntryAvailability;

class Availability extends Fieldtype
{
 
    public function augment($value)
    {
        $parentId = $this->field->parent()->id();
        $results = EntryAvailability::where('statamic_id', $parentId)
            ->get(['statamic_id', 'date', 'price', 'available'])
            ->sortBy('date')
            ->keyBy('date');
        return $results->toArray();
    }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        return ['parent' => $this->field->parent()->id()];
    }
}