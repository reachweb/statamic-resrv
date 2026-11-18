<?php

namespace Reach\StatamicResrv\Tags;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Http\Middleware\MarkCheckoutCompleteUncacheable;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Http\Payment\PendingPayment;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Tags\Tags;

class ResrvCheckoutRedirect extends Tags
{
    public function index(): array
    {
        // What this tag renders belongs to one visitor's session: never let it be static cached.
        request()->attributes->set(MarkCheckoutCompleteUncacheable::ATTRIBUTE, true);

        $payment = $this->resolveGateway();

        // payment_pending is visitor input, so the gateway's answer is filtered here as well:
        // a gateway that still looks the id up unchecked must not reach the template.
        $redirectData = PendingPayment::guard($payment->handleRedirectBack());

        // A failed payment leaves the checkout open, search included, so "try again" works: the
        // customer can go back to the checkout and pay, and their next search releases the
        // reservation's hold (ExpireReservations) instead of the dates staying blocked until the
        // hold lapses. The checkout's Extras component reads the search when a coupon changes.
        if ($redirectData['status'] === false) {
            return $this->makeResponse('failed', __('Payment failed'), __('Your payment was not successful. Please contact us or try again.'));
        }

        session()->forget('resrv-search');
        PendingPayment::endCheckout();

        if ($redirectData['status'] === true) {
            return $this->makeResponse('success', __('Payment successful'), __('Your payment has been processed successfully. You will receive an email confirmation shortly.'), $redirectData);
        }
        if ($redirectData['status'] === 'pending') {
            // An offline booking: placed and held, but not confirmed until its payment arrives.
            $message = ($redirectData['reservation']['status'] ?? null) === ReservationStatus::AWAITING_PAYMENT->value
                ? __('Your reservation has been received and is awaiting payment. We have emailed you the payment details, and we will confirm your reservation once your payment arrives.')
                : __('Your reservation has been received and held. It will be confirmed upon payment. You will receive an email with further details shortly.');

            return $this->makeResponse('pending', __('Reservation received'), $message, $redirectData);
        }
    }

    protected function resolveGateway(): PaymentInterface
    {
        $manager = app(PaymentGatewayManager::class);

        // Try to resolve from the reservation's stored gateway (trusted)
        if ($reservationId = session('resrv_reservation')) {
            $reservation = Reservation::find($reservationId);
            if ($reservation?->payment_gateway) {
                try {
                    return $manager->forReservation($reservation);
                } catch (\InvalidArgumentException) {
                    // Fall through to query param / default
                }
            }
        }

        // Fall back to query parameter (set during checkout redirect)
        $gatewayName = request()->input('resrv_gateway');
        if ($gatewayName) {
            try {
                return $manager->gateway($gatewayName);
            } catch (\InvalidArgumentException) {
                // Fall through to default
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
