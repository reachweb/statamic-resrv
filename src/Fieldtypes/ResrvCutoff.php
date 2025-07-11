<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Entry;
use Statamic\Fields\Fieldtype;

class ResrvCutoff extends Fieldtype
{
    protected $icon = 'time';

    public function augment($value)
    {
        if (!$value || !is_array($value)) {
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
            return [
                'parent' => 'Collection',
                'server_time' => now()->format('Y-m-d H:i:s'),
                'server_timezone' => config('app.timezone', 'UTC'),
            ];
        }

        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }

        return [
            'parent' => $parent,
            'server_time' => now()->format('Y-m-d H:i:s'),
            'server_timezone' => config('app.timezone', 'UTC'),
        ];
    }

    public function process($data)
    {
        if (!$data || !is_array($data)) {
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
