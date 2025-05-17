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
                    'type' => 'grid',
                    'mode' => 'stacked',
                    'add_row' => __('Add rule'),
                    'fields' => [
                        [
                            'handle' => 'connected_availability_type',
                            'field' => [
                                'display' => __('Type'),
                                'type' => 'select',
                                'options' => [
                                    'all' => __('All availabilities of the same entry'),
                                    'same_slug' => __('Same slug (works for multiple entries with the same availability slug)'),
                                    'specific_slugs' => __('Specific slugs (works for multiple entries with the slugs you set)'),
                                    'select' => __('Select manually below (works for the same entry)'),
                                    'entries' => __('Connect specific entries\' availabilities together'),
                                ],
                                'validate' => [
                                    'required',
                                ],
                            ],
                        ],
                        [
                            'handle' => 'block_type',
                            'field' => [
                                'display' => __('Block type'),
                                'instructions' => __('Select how the related availabilities are changed.'),
                                'type' => 'button_group',
                                'options' => [
                                    'sync' => __('Sync'),
                                    'block_availability' => __('Block'),
                                    'change_by_amount' => __('Change by amount'),
                                ],
                                'default' => 'sync',
                            ],
                        ],
                        [
                            'handle' => 'never_unblock',
                            'field' => [
                                'display' => __('Never unblock'),
                                'instructions' => __('If enabled, the engine will never unblock the connected availability, even if the availability is increased.'),
                                'type' => 'array',
                                'type' => 'toggle',
                                'default' => false,
                                'if' => [
                                    'block_type' => 'block_availability',
                                ],
                            ],
                        ],
                        [
                            'handle' => 'slugs_to_sync',
                            'field' => [
                                'display' => __('Slugs to sync'),
                                'instructions' => __('Enter a comma-separated list of slugs to sync.'),
                                'type' => 'text',
                                'if' => [
                                    'connected_availability_type' => 'specific_slugs',
                                ],
                            ],
                        ],
                        [
                            'handle' => 'manually_connected_availabilities',
                            'field' => [
                                'display' => __('Manually connected availabilities'),
                                'instructions' => __('Please enter the slug of the availability and the slug(s) of the other availabilities you want to affect (separated by commas).'),
                                'type' => 'array',
                                'key_header' => __('When availability changes'),
                                'value_header' => __('Also change these availabilities'),
                                'add_button' => __('Add connected availability'),
                                'if' => [
                                    'connected_availability_type' => 'select',
                                ],
                            ],
                        ],
                        [
                            'handle' => 'connected_entries',
                            'field' => [
                                'display' => __('Connected entries'),
                                'instructions' => __('Please select groups of entries that should be connected.'),
                                'type' => 'grid',
                                'add_row' => __('Add group'),
                                'fields' => [
                                    [
                                        'handle' => 'entries',
                                        'field' => [
                                            'display' => __('Entries'),
                                            'type' => 'entries',
                                            'mode' => 'default',
                                        ],
                                    ],
                                ],
                                'if' => [
                                    'connected_availability_type' => 'entries',
                                ],
                            ],
                        ],
                    ],
                ],
                'disable_connected_availabilities_on_cp' => [
                    'display' => __('Disable connected availabilities control panel'),
                    'instructions' => __('If enabled, only apply the rules when the availability is changed from the frontend.'),
                    'type' => 'toggle',
                    'default' => true,
                ],
            ],
        );
    }
}
