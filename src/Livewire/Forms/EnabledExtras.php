<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;

class EnabledExtras extends Form
{
    #[Validate]
    public Collection $extras;

    public function extrasToSync(): Collection
    {
        $this->validate();

        return $this->extras->mapWithKeys(function ($extra) {
            return [
                $extra['id'] => [
                    'quantity' => $extra['quantity'],
                    'price' => $extra['price'],
                ],
            ];
        });
    }

    public function rules(): array
    {
        return [
            'extras' => 'nullable|array',
            'extras.*.id' => [
                'required',
                'integer',
            ],
            'extras.*.price' => [
                'required',
                'numeric',
            ],
            'extras.*.quantity' => [
                'required',
                'integer',
            ],
            'extras.*.name' => [
                'required',
                'string',
            ],
        ];
    }
}
