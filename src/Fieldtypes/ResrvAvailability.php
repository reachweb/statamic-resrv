<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Availability;
use Statamic\Fields\Fieldtype;

class ResrvAvailability extends Fieldtype
{
    protected $icon = 'calendar';

    public function augment($value)
    {
        if ($value != 'disabled') {
            $availability_data = Availability::entry($value)->where('available', '>', '0')->get();

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
            return ['parent' => 'Collection', 'advanced_availability' => $this->field->get('advanced_availability')];
        }

        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }

        return ['parent' => $parent, 'advanced_availability' => $this->field->get('advanced_availability')];
    }

    protected function configFieldItems(): array
    {
        if (config('resrv-config.enable_advanced_availability') == false) {
            return [];
        }

        return [
            'advanced_availability' => [
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
