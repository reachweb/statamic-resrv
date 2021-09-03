<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Options as OptionsModel;

class Options extends Fieldtype
{
 
    public function augment($value)
    {
        return OptionsModel::entry($this->field->parent()->id())            
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