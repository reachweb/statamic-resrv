<?php

namespace Reach\StatamicResrv\Tags;

use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Statamic\Tags\Tags;

class ResrvCheckoutRedirect extends Tags
{
    public function index(): array
    {
        $payment = app(PaymentInterface::class);
        $redirectData = $payment->handleRedirectBack();

        session()->forget('resrv-search');
        session()->forget('resrv_reservation');

        if ($redirectData['status'] === false) {
            return $this->makeResponse('failed', __('Payment failed'), __('Your payment was not successful. Please contact us or try again.'));
        }
        if ($redirectData['status'] === true) {
            return $this->makeResponse('success', __('Payment successful'), __('Your payment has been processed successfully. You will receive an email confirmation shortly.'), $redirectData);
        }
        if ($redirectData['status'] === 'pending') {
            return $this->makeResponse('pending', __('Reservation confirmed successfully'), __('Your reservation is not confirmed, pending payment. You will receive an email confirmation shortly.'), $redirectData);
        }
    }

    protected function makeResponse(string $status, string $title, string $message, array $redirectData = []): array
    {
        return [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'reservation' => $redirectData['reservation'] ?? [],
        ];
    }
}
