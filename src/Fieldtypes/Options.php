<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Option;

class Options extends Fieldtype
{
 
    public function augment($value)
    {
        return Option::entry($this->field->parent()->id())            
            ->where('published', true)
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