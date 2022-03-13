<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Availability as EntryAvailability;

class ResrvAvailability extends Fieldtype
{

    protected $icon = 'calendar';

    public function augment($value)
    {   
        if ($value != 'disabled') {
            $availability_data = EntryAvailability::entry($value)->where('available', '>', '0')->get();

            if ($availability_data->count() == 0) {
                return false;
            }
            $data = $availability_data->sortBy('date')->keyBy('date')->toArray();
            $cheapest = $availability_data->sortBy('price')->firstWhere('available', '>', '0')->price->format();
            return compact('data', 'cheapest');
        }
        return false;
    }

    public function preload(): array
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection', 'options' => $this->field->get('options')];
        }
        
        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }
        return ['parent' => $parent, 'options' => $this->field->get('options')];
    }

    protected function configFieldItems(): array
    {
        return [
            'options' => [
                'display' => __('Advanced availability'),
                'instructions' => __('Add properties to create advanced availability rules. <em>(please avoid using reserved slug "any")</em>'),
                'type' => 'array',
                'key_header' => __('Slug'),
                'value_header' => __('Label'),
                'add_button' => __('Add property'),
            ],
        ];
    }

}