<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;

class Availability extends Fieldtype
{
    public function preload()
    {
        return ['parent' => $this->field->parent()->id()];
    }
}