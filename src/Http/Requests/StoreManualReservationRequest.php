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
        // checkoutFormRules() merges BEFORE the explicit rules so customer.email =>
        // required|email always wins — a custom form must not weaken it.
        return array_merge(parent::rules(), $this->checkoutFormRules(), [
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
            'hold_days' => 'nullable|integer|min:1',
            'send_payment_request_email' => 'sometimes|boolean',
            'affiliate_id' => 'nullable|integer',
            'customer' => 'required|array',
            'customer.email' => 'required|email',
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

                return ['customer.'.$field->handle() => $validate ? implode('|', $validate) : 'nullable'];
            })
            ->all();
    }
}
