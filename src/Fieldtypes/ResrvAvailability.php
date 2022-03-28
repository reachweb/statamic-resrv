<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\AdvancedAvailability;

class ResrvAvailability extends Fieldtype
{
    protected $icon = 'calendar';

    public function augment($value)
    {   
        if ($value != 'disabled') {
            $availability_data = Availability::entry($value)->where('available', '>', '0')->get();

            // Retry for advanced availability
            if ($availability_data->count() == 0 && config('resrv-config.enable_advanced_availability')) {
                $availability_data = AdvancedAvailability::entry($value)->where('available', '>', '0')->get();
            }
            
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
        if (config('resrv-config.enable_advanced_availability') == false) {
            return [];
        }
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