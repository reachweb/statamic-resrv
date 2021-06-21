<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\FixedPricing as Fixed;

class FixedPricing extends Fieldtype
{
 
    public function augment($value)
    {
        return Fixed::entry($this->field->parent()->id())            
            ->orderBy('days')
            ->get()            
            ->toArray();
    }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        return ['parent' => $this->field->parent()->id()];
    }
}