<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Reach\StatamicResrv\Exceptions\OptionsException;

class EnabledOptions extends Form
{
    #[Validate]
    public Collection $options;

    /**
     * Build the pivot snapshot (value name, computed price, price type) for each selected option from
     * the authoritative server-resolved options — NEVER the client-submitted valueName/price/priceType.
     * The options-updated event is client-dispatchable, so trusting its fields would let a forged
     * payload persist fake names/types or a redistributed price (one that still clears the aggregate
     * drift check) into reservation history, the CP, exports and emails. Resolving by the selected
     * value id against $serverOptions reuses the exact pricing the live cart shows — including
     * per-child parent aggregation and disabled-value stripping — so the snapshot can never drift
     * from what was actually charged.
     *
     * @param  Collection<int, \Reach\StatamicResrv\Models\Option>  $serverOptions
     */
    public function optionsToSync(Collection $serverOptions): Collection
    {
        $this->validate();

        return $this->options->mapWithKeys(function ($option) use ($serverOptions) {
            $serverOption = $serverOptions->firstWhere('id', (int) $option['id']);
            $serverValue = $serverOption?->values->firstWhere('id', (int) $option['value']);

            if (! $serverValue) {
                throw new OptionsException(__('The selected option value is not valid.'));
            }

            return [
                $option['id'] => [
                    'value' => $serverValue->id,
                    'value_name' => $serverValue->name,
                    'price' => $serverValue->price->format(),
                    'price_type' => $serverValue->price_type,
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
