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
            $availability_data = Availability::where('statamic_id', $value)->where('available', '>', '0')->get();

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

        $config = [
            'advanced_availability' => [
                'display' => __('Advanced availability'),
                'instructions' => __('Add properties to create advanced availability rules. <em>(please avoid using reserved slugs "any" or "none")</em>'),
                'type' => 'array',
                'key_header' => __('Slug'),
                'value_header' => __('Label'),
                'add_button' => __('Add property'),
            ],
        ];

        if (config('resrv-config.enable_connected_availabilities', false) == false) {
            return $config;
        }

        return array_merge($config,
            [
                'connected_availabilities' => [
                    'display' => __('Connected availabilities'),
                    'instructions' => __('Here you can "connect" <em>advanced</em> availabilities, so that any operations on one of them will be reflected on the others.'),
                    'type' => 'select',
                    'options' => [
                        'none' => __('None'),
                        'all' => __('All availabilities of the same entry'),
                        'same_slug' => __('Same slug (works for multiple entries with the same availability slug)'),
                        'select' => __('Select manually below (works for the same entry)'),
                    ],
                    'default' => 'none',
                ],
                'manual_connected_availabilities' => [
                    'display' => __('Manually connected availabilities'),
                    'instructions' => __('Please enter the slug of the availability and the slug(s) of the other availabilities you want to affect (seperated by commas).'),
                    'type' => 'array',
                    'key_header' => __('When availability changes'),
                    'value_header' => __('Also change these availabilities'),
                    'add_button' => __('Add connected availability'),
                    'if' => [
                        'connected_availabilities' => 'select',
                    ],
                ],
            ]);
    }
}
