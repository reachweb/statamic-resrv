<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Reach\StatamicResrv\Models\Extra;

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
                'min:1',
                function ($attribute, $value, $fail) {
                    $key = explode('.', $attribute)[1];
                    $extraId = data_get($this->extras, $key.'.id');

                    if (! $extraId) {
                        return;
                    }

                    $extra = Extra::find($extraId);

                    if ($extra && $extra->maximum > 0 && $value > $extra->maximum) {
                        $fail(__('The selected quantity exceeds the maximum allowed for this extra.'));
                    }
                },
            ],
            'extras.*.name' => [
                'required',
                'string',
            ],
        ];
    }
}
