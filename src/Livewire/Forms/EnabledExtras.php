<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Models\Extra;

class EnabledExtras extends Form
{
    #[Validate]
    public Collection $extras;

    /**
     * Build the pivot snapshot (quantity + per-unit price) for each selected extra from the
     * authoritative server-priced extras — never the client-submitted price. The extras-updated event
     * is client-dispatchable, so trusting its price would let a forged payload redistribute prices
     * between extras (preserving the validated aggregate) and corrupt the per-extra price shown by
     * priceFromPivot() in the CP reservation detail. Quantity stays client-driven — it is validated
     * against the extra's maximum.
     *
     * @param  Collection<int, Extra>  $serverExtras
     */
    public function extrasToSync(Collection $serverExtras): Collection
    {
        $this->validate();

        return $this->extras->mapWithKeys(function ($extra) use ($serverExtras) {
            $serverExtra = $serverExtras->firstWhere('id', (int) $extra['id']);

            if (! $serverExtra) {
                throw new ExtrasException(__('The selected extra is not valid.'));
            }

            return [
                $extra['id'] => [
                    'quantity' => $extra['quantity'],
                    'price' => $serverExtra->price->format(),
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
                // Reject duplicate ids: extrasToSync() collapses them to one pivot while the total sums every row.
                'distinct',
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
