<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Extra;

class Extras extends Fieldtype
{
 
    public function augment($value)
    {
        return Extra::entry($this->field->parent()->id())
            ->where('published', true)
            ->get()
            ->keyBy('slug')
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