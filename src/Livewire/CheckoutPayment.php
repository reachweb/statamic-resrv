<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Jobs\SendAwaitingPaymentEmails;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ActivityLog;

class CheckoutPayment extends Component
{
    use Traits\HandlesStatamicQueries;

    public string $view = 'checkout-payment';

    #[Locked]
    public string $clientSecret;

    #[Locked]
    public string $publicKey;

    #[Locked]
    public float $amount;

    #[Locked]
    public string $paymentView = '';

    #[Locked]
    public string $checkoutCompletedUrl;

    public function mount(): void
    {
        $this->checkoutCompletedUrl = $this->getCheckoutCompleteEntry()->absoluteUrl();
    }

    /**
     * The offline step's Confirm reservation. Nothing is collected online, so the booking is placed
     * and waits for the payment like a manual reservation: AWAITING_PAYMENT with no deadline, its
     * dates held until an admin marks it as paid or cancels it as unpaid in the Control Panel.
     */
    public function confirmPayment()
    {
        $reservationId = session('resrv_reservation');

        if (! $reservationId) {
            $this->addError('reservation', 'Reservation not found in the session.');

            return;
        }

        $reservation = Reservation::find($reservationId);

        if (! $reservation) {
            $this->addError('reservation', 'Reservation could not be found. Please start over.');

            return;
        }

        if (! $this->paysOffline($reservation)) {
            $this->addError('reservation', 'This payment method does not support manual confirmation.');

            return;
        }

        if ($reservation->isAwaitingPayment()) {
            return $this->redirectToCheckoutComplete($reservation);
        }

        $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');

        if ($expireAt < now() || $reservation->status === ReservationStatus::EXPIRED->value) {
            $this->addError('reservation', 'This reservation has expired. Please start over.');

            return;
        }

        try {
            $placed = $reservation->transitionTo(ReservationStatus::AWAITING_PAYMENT, tolerant: true, inTransaction: function (Reservation $fresh) {
                // Another tab may have switched to an online gateway since this step was shown.
                if (! $this->paysOffline($fresh)) {
                    throw new ReservationNoLongerPayable($fresh->id);
                }

                // The surcharge of the method owed, which a refresh in another tab drops from an intent-less selection.
                $fresh->payment_surcharge = app(PaymentGatewayManager::class)
                    ->calculateSurcharge($fresh->payment_gateway, $fresh->payment)->format();
            });
        } catch (ReservationNoLongerPayable) {
            $this->addError('reservation', 'This payment method does not support manual confirmation.');

            return;
        }

        if ($placed) {
            app(ActivityLog::class)->logReservation($reservation, ReservationStatus::PENDING, ReservationStatus::AWAITING_PAYMENT, ReservationLogReason::CheckoutAwaitingPayment);
            SendAwaitingPaymentEmails::dispatchAfterResponse($reservation);
        }

        // A false transition is a duplicate submit when another request already placed the booking, or
        // a payment the customer completed in another tab confirmed it first.
        if ($placed || $reservation->refresh()->isAwaitingPayment() || $reservation->isLive()) {
            return $this->redirectToCheckoutComplete($reservation);
        }

        $this->addError('reservation', 'This reservation has expired. Please start over.');
    }

    protected function paysOffline(Reservation $reservation): bool
    {
        $manager = app(PaymentGatewayManager::class);
        $gatewayKey = $reservation->payment_gateway;

        return is_string($gatewayKey) && $gatewayKey !== ''
            && $manager->has($gatewayKey)
            && $manager->gateway($gatewayKey)->supportsManualConfirmation();
    }

    protected function redirectToCheckoutComplete(Reservation $reservation)
    {
        return redirect()->to($this->checkoutCompletedUrl.'?payment_pending='.$reservation->id);
    }

    public function render()
    {
        if ($this->paymentView) {
            return view($this->paymentView);
        }

        return view('statamic-resrv::livewire.'.$this->view);
    }
}
