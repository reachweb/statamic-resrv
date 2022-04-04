<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Option;
use Statamic\Fields\Fieldtype;

class ResrvOptions extends Fieldtype
{
    protected $icon = '<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(1,0,0,1,0,0)"><path d="M4.5 2.498L18.5 2.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M4.5 7.498L18.5 7.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M4.5 12.498L10.5 12.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M4.5 17.498L10.5 17.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 2.498L1.5 2.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 7.498L1.5 7.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 12.498L1.5 12.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M0.5 17.498L1.5 17.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.500 18.498 A5.000 5.000 0 1 0 23.500 18.498 A5.000 5.000 0 1 0 13.500 18.498 Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21 18.498L16 18.498" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18.5 20.998L18.5 15.998" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>';

    public function augment($value)
    {
        return Option::entry($this->field->parent()->id())
            ->where('published', true)
            ->with('values')
            ->get()
            ->keyBy('slug')
            ->toArray();
    }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        if ($this->field->parent()->hasOrigin()) {
            return ['parent' => $this->field->parent()->origin()->id()];
        }

        return ['parent' => $this->field->parent()->id()];
    }
}
