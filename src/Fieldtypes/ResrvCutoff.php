<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Fields\Fieldtype;

class ResrvCutoff extends Fieldtype
{
    protected $icon = 'time';

    public function augment($value)
    {
        if (!$value || $value === 'disabled') {
            return false;
        }

        // Return the cutoff rules for frontend use
        try {
            $resrvEntry = Entry::whereItemId($this->field->parent()->id());
            return $resrvEntry->getCutoffRules();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function preload(): array
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }

        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }

        // Load existing cutoff rules if they exist
        try {
            $resrvEntry = Entry::whereItemId($parent);
            $cutoffRules = $resrvEntry->getCutoffRules();
        } catch (\Exception $e) {
            $cutoffRules = null;
        }

        return [
            'parent' => $parent,
            'existing_rules' => $cutoffRules
        ];
    }

    public function process($data)
    {
        if (!$data || $data === 'disabled') {
            return 'disabled';
        }

        // Store cutoff rules in resrv_entries.options
        try {
            $resrvEntry = Entry::whereItemId($this->field->parent()->id());
            
            $options = $resrvEntry->options ?? [];
            if ($data['enable_cutoff'] ?? false) {
                $options['cutoff_rules'] = $data;
            } else {
                unset($options['cutoff_rules']);
            }
            
            $resrvEntry->options = $options;
            $resrvEntry->save();
            
            return $data['enable_cutoff'] ? $this->field->parent()->id() : 'disabled';
        } catch (\Exception $e) {
            return $data;
        }
    }

    protected function configFieldItems(): array
    {
        return [
            'enable_cutoff' => [
                'display' => __('Enable Cutoff Rules'),
                'instructions' => __('Enable time-based booking restrictions for this entry.'),
                'type' => 'toggle',
                'default' => false,
            ],
            'default_starting_time' => [
                'display' => __('Default Starting Time'),
                'instructions' => __('The default time when the activity/service starts (e.g., 16:00).'),
                'type' => 'time',
                'default' => '16:00',
                'if' => [
                    'enable_cutoff' => true,
                ],
            ],
            'default_cutoff_hours' => [
                'display' => __('Default Cutoff Hours'),
                'instructions' => __('How many hours before the starting time should booking be cutoff.'),
                'type' => 'integer',
                'default' => 3,
                'validate' => ['min:0', 'max:72'],
                'if' => [
                    'enable_cutoff' => true,
                ],
            ],
            'seasonal_schedules' => [
                'display' => __('Seasonal Schedules'),
                'instructions' => __('Configure different starting times and cutoff periods for specific date ranges.'),
                'type' => 'grid',
                'mode' => 'stacked',
                'add_row' => __('Add Schedule'),
                'fields' => [
                    [
                        'handle' => 'name',
                        'field' => [
                            'display' => __('Schedule Name'),
                            'type' => 'text',
                            'validate' => ['required'],
                            'width' => 100,
                        ],
                    ],
                    [
                        'handle' => 'date_start',
                        'field' => [
                            'display' => __('Start Date'),
                            'type' => 'date',
                            'validate' => ['required', 'date'],
                            'width' => 50,
                        ],
                    ],
                    [
                        'handle' => 'date_end',
                        'field' => [
                            'display' => __('End Date'),
                            'type' => 'date',
                            'validate' => ['required', 'date', 'after:date_start'],
                            'width' => 50,
                        ],
                    ],
                    [
                        'handle' => 'starting_time',
                        'field' => [
                            'display' => __('Starting Time'),
                            'type' => 'time',
                            'validate' => ['required'],
                            'width' => 50,
                        ],
                    ],
                    [
                        'handle' => 'cutoff_hours',
                        'field' => [
                            'display' => __('Cutoff Hours'),
                            'type' => 'integer',
                            'validate' => ['required', 'min:0', 'max:72'],
                            'width' => 50,
                        ],
                    ],
                ],
                'if' => [
                    'enable_cutoff' => true,
                ],
            ],
        ];
    }
}
