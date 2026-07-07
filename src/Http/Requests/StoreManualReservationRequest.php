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
        // checkoutFormRules() is merged BEFORE the explicit rules so the explicit
        // customer.email => required|email always wins. A custom checkout form whose email field
        // is optional (or omits validate) must not weaken the requirement — an online
        // awaiting-payment reservation needs an email for its payment URL and request recipient.
        return array_merge(parent::rules(), $this->checkoutFormRules(), [
            'payment_gateway' => [
                'required',
                'string',
                Rule::in(array_keys(app(PaymentGatewayManager::class)->all())),
            ],
            'custom_amount' => 'nullable|numeric|required_if:payment_mode,custom',
            'affects_availability' => 'sometimes|boolean',
            'hold_days' => 'nullable|integer|min:1',
            'send_payment_request_email' => 'sometimes|boolean',
            'affiliate_id' => 'nullable|integer',
            'customer' => 'required|array',
            'customer.email' => 'required|email',
        ]);
    }

    /**
     * The resolved checkout form's own validate rules applied to the customer payload —
     * derived the way CheckoutForm::rules() derives them, so a manual reservation's
     * customer passes exactly the validation a frontend one would.
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
