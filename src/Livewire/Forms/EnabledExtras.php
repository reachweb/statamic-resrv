<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Support\AddonQuote;

class EnabledExtras extends Form
{
    #[Validate]
    public Collection $extras;

    public function extrasToSync(): Collection
    {
        $this->validate();

        // Snapshot both, like the CP (ManualReservationCreator::create()): the unit price listed
        // and the line charged, which Extra::priceFromPivot() renders.
        return $this->extras->mapWithKeys(function ($extra) {
            return [
                $extra['id'] => [
                    'quantity' => $extra['quantity'],
                    'price' => $extra['price'],
                    'total' => $this->lineTotal($extra)->format(),
                ],
            ];
        });
    }

    /** The amount a selected extra adds to the total (AddonQuote::rowTotal()). */
    public function lineTotal(array $extra): PriceClass
    {
        return AddonQuote::rowTotal($extra);
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
            'extras.*.total' => [
                'nullable',
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

                    if ($error = Extra::find($extraId)?->quantityError((int) $value)) {
                        $fail($error);
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
