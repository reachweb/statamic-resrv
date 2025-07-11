<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Fields\Fieldtype;

class ResrvCutoff extends Fieldtype
{
    protected $icon = 'time';

    public function augment($value)
    {
        if (! config('resrv-config.enable_cutoff_rules', false)) {
            return false;
        }

        if (! $value || ! is_array($value)) {
            return false;
        }

        try {
            $resrvEntry = Entry::whereItemId($this->field->parent()->id());

            return $resrvEntry->getCutoffRules();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function preload(): array
    {
        $baseData = [
            'server_time' => now()->format('Y-m-d H:i:s'),
            'server_timezone' => config('app.timezone', 'UTC'),
            'cutoff_feature_enabled' => config('resrv-config.enable_cutoff_rules', false),
        ];

        if (class_basename($this->field->parent()) == 'Collection') {
            return array_merge($baseData, [
                'parent' => 'Collection',
            ]);
        }

        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }

        return array_merge($baseData, [
            'parent' => $parent,
        ]);
    }

    public function process($data)
    {
        if (! $data || ! is_array($data)) {
            return null;
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

            return $data['enable_cutoff'] ? $data : null;
        } catch (\Exception $e) {
            return $data;
        }
    }
}
