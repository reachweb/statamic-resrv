<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteManualReservationRequest extends FormRequest
{
    /**
     * Shape validation only — every money figure is recomputed server-side by
     * ManualReservationCreator, which enforces the domain invariants.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => 'required|string',
            'date_start' => 'required|date',
            'date_end' => 'required|date|after:date_start',
            'quantity' => 'required|integer|min:1',
            'rate_id' => 'nullable|integer',
            'extras' => 'nullable|array',
            'extras.*.id' => 'required|integer',
            'extras.*.quantity' => 'required|integer|min:1',
            'options' => 'nullable|array',
            'options.*.id' => 'required|integer',
            'options.*.value' => 'required|integer',
            'total_override' => 'nullable|numeric|min:0',
            'payment_mode' => 'required|in:standard,full,custom',
            'custom_amount' => 'nullable|numeric|required_if:payment_mode,custom',
            'payment_gateway' => 'nullable|string',
        ];
    }
}
