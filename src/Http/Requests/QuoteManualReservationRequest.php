<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Reach\StatamicResrv\Enums\ManualPaymentMode;

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
            // The availability engine rejects past and over-quantity bookings too, but with
            // customer-facing wording — catch both here with admin wording.
            'date_start' => 'required|date|after_or_equal:today',
            'date_end' => 'required|date|after:date_start',
            'quantity' => 'required|integer|min:1|max:'.max(1, (int) config('resrv-config.maximum_quantity')),
            'rate_id' => 'nullable|integer',
            'extras' => 'nullable|array',
            'extras.*.id' => 'required|integer',
            'extras.*.quantity' => 'required|integer|min:1',
            'options' => 'nullable|array',
            'options.*.id' => 'required|integer',
            'options.*.value' => 'required|integer',
            'total_override' => 'nullable|numeric|min:0',
            'payment_mode' => ['required', Rule::enum(ManualPaymentMode::class)],
            'custom_amount' => 'nullable|numeric',
            'payment_gateway' => 'nullable|string',
            // Shape-only: lets custom-priced extras preview with creation's multiplier.
            // The store request layers the checkout form's real rules on top.
            'customer' => 'nullable|array',
        ];
    }

    /**
     * Explicit messages: on CP routes Statamic's translator serves its own validation strings,
     * which carry no :attribute, so a generic rule message would not say which field failed.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'item_id.required' => __('Choose an entry.'),
            'date_start.required' => __('Choose the start date.'),
            'date_start.date' => __('The start date is not a valid date.'),
            'date_start.after_or_equal' => __('The start date is in the past.'),
            'date_end.required' => __('Choose the end date.'),
            'date_end.date' => __('The end date is not a valid date.'),
            'date_end.after' => __('The end date must be after the start date.'),
            'quantity.required' => __('Enter a quantity.'),
            'quantity.integer' => __('Quantity must be a whole number.'),
            'quantity.min' => __('Quantity must be at least 1.'),
            'quantity.max' => __('Maximum quantity per reservation is :max.'),
            'rate_id.integer' => __('Choose a valid rate.'),
            'extras.*.quantity.min' => __('Extra quantities must be at least 1.'),
            'total_override.numeric' => __('The new total must be a number.'),
            'total_override.min' => __('The new total cannot be negative.'),
            'payment_mode.required' => __('Choose the amount to request.'),
            'custom_amount.numeric' => __('The custom amount must be a number.'),
        ];
    }
}
