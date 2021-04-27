<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Extra;

class Extras extends Fieldtype
{
 
    // public function augment($value)
    // {
    //     $extras = Extra::entry($this->field->parent()->id())->get();
    //     return $extras->toArray();
    // }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        return ['parent' => $this->field->parent()->id()];
    }
}