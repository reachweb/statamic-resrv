<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Validation\Rule;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Support\CheckoutFormResolver;

class StoreManualReservationRequest extends QuoteManualReservationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $formRules = $this->checkoutFormRules();

        return array_merge(parent::rules(), $formRules, [
            // Nullable: zero-amount bookings need no gateway. "Gateway required to collect a
            // payment" is enforced in ManualReservationCreator::create(), which knows the amount.
            'payment_gateway' => [
                'nullable',
                'string',
                Rule::in(array_keys(app(PaymentGatewayManager::class)->all())),
            ],
            // Nullable even in custom mode: a zero total makes an omitted amount a comped
            // booking. "Amount required for a nonzero total" is enforced in
            // ManualReservationCreator::requestedAmount(), which knows the total.
            'custom_amount' => 'nullable|numeric',
            'affects_availability' => 'sometimes|boolean',
            // Optional explicit switch: when sent and on, a blank day count is an error rather
            // than silently meaning "no hold".
            'hold_enabled' => 'sometimes|boolean',
            'hold_days' => 'nullable|integer|min:1|max:365|required_if_accepted:hold_enabled',
            'send_payment_request_email' => 'sometimes|boolean',
            'affiliate_id' => 'nullable|integer',
            'customer' => 'required|array',
            // The form's own email rules, hardened by checkoutFormRules(); a form without an
            // email field still gets the mandatory pair.
            'customer.email' => $formRules['customer.email'] ?? 'required|email',
            // Retries with the same key (same admin) return the first reservation instead of a duplicate.
            'idempotency_key' => 'nullable|string|max:100',
            // The figures the admin was shown; a recomputed quote that differs is refused.
            'expected_total' => 'nullable|numeric',
            'expected_amount' => 'nullable|numeric',
            'expected_surcharge' => 'nullable|numeric',
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'payment_gateway.in' => __('Choose a valid payment method.'),
            'hold_days.integer' => __('Enter the hold as a whole number of days.'),
            'hold_days.min' => __('Hold the reservation for at least 1 day.'),
            'hold_days.max' => __('Hold the reservation for at most :max days.'),
            'hold_days.required_if_accepted' => __('Enter the number of days to hold the reservation.'),
            'customer.required' => __('Enter the customer details.'),
            'customer.email.required' => __("Enter the customer's email address."),
            // Every email-rule form field (email, repeat_email, …) shares one wording.
            'customer.*.email' => __('Enter a valid email address.'),
        ]);
    }

    /**
     * The checkout form's validate rules applied to the customer payload, derived as
     * CheckoutForm::rules() derives them.
     *
     * @return array<string, string>
     */
    protected function checkoutFormRules(): array
    {
        $itemId = $this->input('item_id');

        if (! is_string($itemId) || $itemId === '') {
            return [];
        }

        return app(CheckoutFormResolver::class)
            ->resolveForEntryId($itemId)
            ->fields()
            ->values()
            ->mapWithKeys(function ($field) {
                $validate = $field->config()['validate'] ?? null;

                if ($field->handle() === 'email') {
                    $validate = $this->mandatoryEmailRules($validate ?? []);
                }

                return ['customer.'.$field->handle() => $validate ? $this->namespacedRules($validate) : 'nullable'];
            })
            ->all();
    }

    /**
     * The customer email is always required and valid. A custom form may add constraints
     * (ends_with:@company.com) but must not weaken that, so rules that let a blank or missing
     * address skip validation (nullable, sometimes, exclude_*) are dropped.
     *
     * @param  array<int, mixed>  $validate
     * @return array<int, mixed>
     */
    protected function mandatoryEmailRules(array $validate): array
    {
        $kept = collect($validate)->reject(
            fn ($rule) => is_string($rule) && preg_match('/^(nullable|sometimes|exclude\w*)(:|$)/', $rule)
        );

        return collect(['required', 'email'])->merge($kept)->unique()->values()->all();
    }

    /**
     * Checkout rules reference sibling fields as form.<handle> (Livewire\CheckoutForm nests
     * them under `form`). Here the fields live under `customer`, so dependent-rule parameters
     * (required_if:form.country,US etc.) must be re-pointed or they resolve to nothing and
     * silently pass.
     *
     * @param  array<int, mixed>  $validate
     */
    protected function namespacedRules(array $validate): string
    {
        return collect($validate)
            ->map(function ($rule) {
                if (! is_string($rule) || str_starts_with($rule, 'regex:') || str_starts_with($rule, 'not_regex:')) {
                    return $rule;
                }

                return preg_replace('/(?<=[:,])form\./', 'customer.', $rule);
            })
            ->implode('|');
    }
}
