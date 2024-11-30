<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Fields\Fieldtype;

class ResrvExtras extends Fieldtype
{
    protected $icon = '<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg">,,<g transform="matrix(1,0,0,1,0,0)"><path d="M5.5,7h13a5,5,0,0,1,5,5h0a5,5,0,0,1-5,5H5.5a5,5,0,0,1-5-5h0A5,5,0,0,1,5.5,7Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18 9.501L18 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16 9.501L16 14.501" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>';

    public function augment($value)
    {
        return Entry::whereItemId($this->field->parent()->id())
            ->extras()
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
        if ($this->field->parent()->hasOrigin()) {
            return ['parent' => $this->field->parent()->origin()->id()];
        }

        return ['parent' => $this->field->parent()->id()];
    }
}
