<?php

namespace Reach\StatamicResrv\Tags;

use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Tags\Tags;

class ResrvCheckoutRedirect extends Tags
{
    public function index(): array
    {
        $payment = $this->resolveGateway();

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

    protected function resolveGateway(): PaymentInterface
    {
        $manager = app(PaymentGatewayManager::class);
        $gatewayName = request()->input('resrv_gateway');

        // Try to resolve from query parameter (set during checkout redirect)
        if ($gatewayName) {
            try {
                return $manager->gateway($gatewayName);
            } catch (\InvalidArgumentException) {
                // Fall through to reservation/default resolution
            }
        }

        // Try to resolve from the reservation's stored gateway
        if ($reservationId = session('resrv_reservation')) {
            $reservation = Reservation::find($reservationId);
            if ($reservation?->payment_gateway) {
                try {
                    return $manager->forReservation($reservation);
                } catch (\InvalidArgumentException) {
                    // Fall through to default
                }
            }
        }

        return $manager->gateway();
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
