<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;

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

        $manager = app(PaymentGatewayManager::class);
        $gatewayKey = $reservation->payment_gateway;

        if (! is_string($gatewayKey) || $gatewayKey === '' ||
            ! $manager->has($gatewayKey) ||
            ! $manager->gateway($gatewayKey)->supportsManualConfirmation()) {
            $this->addError('reservation', 'This payment method does not support manual confirmation.');

            return;
        }

        if ($reservation->status === 'confirmed') {
            return redirect()->to($this->checkoutCompletedUrl.'?payment_pending='.$reservation->id);
        }

        $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');

        if ($expireAt < now() || $reservation->status === 'expired') {
            $this->addError('reservation', 'This reservation has expired. Please start over.');

            return;
        }

        ReservationConfirmed::dispatch($reservation);

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
