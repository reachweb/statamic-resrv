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
            return ['parent' => 'Collection'];
        }

        $parent = $this->field->parent()->id();
        if ($this->field->parent()->hasOrigin()) {
            $parent = $this->field->parent()->origin()->id();
        }

        return [
            'parent' => $parent,
            'currency_symbol' => config('resrv-config.currency_symbol'),
        ];
    }

    protected function configFieldItems(): array
    {
        return [
            'enable_multi_rate_booking' => [
                'display' => __('Multi-rate booking'),
                'instructions' => __('Allow customers to book multiple rates in a single reservation.'),
                'type' => 'toggle',
                'default' => false,
            ],
        ];
    }
}
