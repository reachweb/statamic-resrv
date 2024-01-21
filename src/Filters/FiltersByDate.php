<?php

namespace Reach\StatamicResrv\Filters;

use Illuminate\Support\Carbon;
use Statamic\Support\Arr;

trait FiltersByDate
{
    public function fieldItems()
    {
        return [
            'operator' => [
                'type' => 'select',
                'placeholder' => __('Select Operator'),
                'options' => [
                    '<' => __('Before'),
                    '>' => __('After'),
                    'between' => __('Between'),
                ],
            ],
            'value' => [
                'type' => 'date',
                'inline' => true,
                'full_width' => true,
                'if' => [
                    'operator' => 'contains_any >, <',
                ],
                'required' => false,
            ],
            'range_value' => [
                'type' => 'date',
                'inline' => true,
                'mode' => 'range',
                'full_width' => true,
                'if' => [
                    'operator' => 'between',
                ],
                'required' => false,
            ],
        ];
    }

    public function apply($query, $values)
    {
        $operator = $values['operator'];

        if ($operator == 'between') {
            if (! isset($values['range_value']['date']['start']) || ! isset($values['range_value']['date']['end'])) {
                return;
            }

            $query->whereDate(self::$handle, '>=', Carbon::parse($values['range_value']['date']['start']));
            $query->whereDate(self::$handle, '<=', Carbon::parse($values['range_value']['date']['end']));

            return;
        }

        if (! isset($values['value'])) {
            return;
        }

        $value = Carbon::parse($values['value']['date']);

        $query->where(self::$handle, $operator, $value);
    }

    public function badge($values)
    {
        $operator = $values['operator'];
        $translatedOperator = Arr::get($this->fieldItems(), "operator.options.{$operator}");

        if ($operator == 'between') {
            if (! isset($values['range_value']['date']['start']) || ! isset($values['range_value']['date']['end'])) {
                return;
            }

            return self::title().' '.strtolower($translatedOperator).' '.$values['range_value']['date']['start'].' '.__('and').' '.$values['range_value']['date']['end'];
        }
        if (! isset($values['value'])) {
            return;
        }

        return self::title().' '.strtolower($translatedOperator).' '.$values['value']['date'];
    }
}
