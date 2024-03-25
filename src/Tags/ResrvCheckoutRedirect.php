<?php

namespace Reach\StatamicResrv\Tags;

use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Statamic\Tags\Tags;

class ResrvCheckoutRedirect extends Tags
{
    public function index(): array
    {
        $payment = app(PaymentInterface::class);

        $status = $payment->handleRedirectBack();

        session()->forget('resrv-search');
        session()->forget('resrv_reservation');

        if ($status === false) {
            return $this->statusFailed();
        }

        return $this->statusSuccess();
    }

    protected function statusSuccess(): array
    {
        return [
            'status' => 'success',
            'title' => __('Payment successful'),
            'message' => __('Your payment has been processed successfully. You will receive an email confirmation shortly.'),
        ];
    }

    protected function statusFailed(): array
    {
        return [
            'status' => 'success',
            'title' => __('Payment failed'),
            'message' => __('Your payment was not successful. Please contact us or try again.'),
        ];
    }
}
