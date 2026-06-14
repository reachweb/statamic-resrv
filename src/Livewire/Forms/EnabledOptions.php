<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;

class EnabledOptions extends Form
{
    #[Validate]
    public Collection $options;

    public function optionsToSync(): Collection
    {
        $this->validate();

        // Snapshot the selected value's name, computed price and price type onto the pivot so a
        // later edit to a (now global, shared) option cannot retroactively change a past reservation.
        return $this->options->mapWithKeys(function ($option) {
            return [
                $option['id'] => [
                    'value' => $option['value'],
                    'value_name' => $option['valueName'] ?? null,
                    'price' => $option['price'] ?? null,
                    'price_type' => $option['priceType'] ?? null,
                ],
            ];
        });
    }

    /**
     * Map of [optionId => selectedValueId] for the current selections — the shape that surcharge
     * matching and pricing consume.
     *
     * @return array<int, int>
     */
    public function selections(): array
    {
        return $this->options
            ->mapWithKeys(fn ($option) => [$option['id'] => $option['value']])
            ->all();
    }

    public function rules(): array
    {
        return [
            'options' => 'nullable|array',
            'options.*.id' => [
                'required',
                'integer',
            ],
            'options.*.price' => [
                'required',
                'numeric',
            ],
            'options.*.value' => [
                'required',
                'integer',
            ],
            'options.*.optionName' => [
                'required',
                'string',
            ],
            'options.*.valueName' => [
                'required',
                'string',
            ],
            'options.*.priceType' => [
                'nullable',
                'string',
            ],
        ];
    }
}
