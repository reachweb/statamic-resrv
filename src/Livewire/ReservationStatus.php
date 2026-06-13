<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\CancellationNotAllowed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

class ReservationStatus extends Component
{
    #[Locked]
    public ?int $reservationId = null;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string|max:255')]
    public string $reference = '';

    public bool $cancelled = false;

    public function mount(): void
    {
        $this->loadReservationFromUri();
    }

    /**
     * Deep-link entry: ?ref=&hash=<customerLookupHash()>; the HMAC authenticates the link so no email entry is needed.
     */
    protected function loadReservationFromUri(): void
    {
        $reference = request()->query('ref');
        $hash = request()->query('hash');

        if (! is_string($reference) || ! is_string($hash) || strlen($hash) !== 64) {
            return;
        }

        // Shares the lookup form's per-(IP, reference) budget so mount can't brute-force the hash.
        if (RateLimiter::tooManyAttempts($this->rateLimiterKey($reference), 10)) {
            return;
        }

        $reservation = Reservation::findForCustomerLookup($reference, $hash, $this->visibleStatuses());

        if ($reservation === null) {
            RateLimiter::hit($this->rateLimiterKey($reference), 600);

            return;
        }

        $this->reservationId = $reservation->id;
    }

    public function lookup(): void
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->rateLimiterKey($this->reference), 10)) {
            $this->addError('lookup', trans('statamic-resrv::frontend.tooManyLookupAttempts'));

            return;
        }

        $reservation = Reservation::findByReferenceForCustomer($this->reference, $this->email, $this->visibleStatuses());

        // Generic error so responses don't reveal whether a reference exists. Success never clears
        // the bucket, so a valid email can't reset a reference's budget; failures decay after 10 min.
        if (! $reservation) {
            RateLimiter::hit($this->rateLimiterKey($this->reference), 600);
            $this->addError('lookup', trans('statamic-resrv::frontend.reservationNotFound'));

            return;
        }

        $this->reservationId = $reservation->id;
    }

    /**
     * Throttle per (IP, reference), not per IP, so shared egress IPs aren't exhausted by one user
     * while still capping guesses per reference. Reference is normalized to match the lookup path.
     */
    protected function rateLimiterKey(string $reference): string
    {
        return 'resrv-status-lookup:'.sha1((string) request()->ip().'|'.strtoupper(trim($reference)));
    }

    public function cancel(): void
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return;
        }

        try {
            app(ReservationRefundProcessor::class)->cancelByCustomer($reservation);
        } catch (CancellationNotAllowed) {
            $this->addError('cancellation', trans('statamic-resrv::frontend.cancellationNotAllowed'));

            return;
        } catch (RefundFailedException|InvalidStateTransition $e) {
            // Gateway errors stay internal; log for owner follow-up, show a generic message.
            Log::warning('Customer cancellation failed.', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
            $this->addError('cancellation', trans('statamic-resrv::frontend.cancellationFailed'));

            return;
        } catch (\Throwable $e) {
            // Unmapped errors must not 500; the transition already rolled back, so report and show generic message.
            report($e);
            $this->addError('cancellation', trans('statamic-resrv::frontend.cancellationFailed'));

            return;
        }

        $this->cancelled = true;

        unset($this->reservation);
    }

    public function startOver(): void
    {
        $this->reservationId = null;
        $this->cancelled = false;
        $this->reset('email', 'reference');
        $this->resetErrorBag();

        unset($this->reservation);
    }

    #[Computed]
    public function reservation(): ?Reservation
    {
        if (! $this->reservationId) {
            return null;
        }

        return Reservation::query()
            ->with(['customer', 'rate', 'extras', 'options.values', 'childs.rate'])
            ->find($this->reservationId);
    }

    #[Computed]
    public function statusLabel(): string
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return '';
        }

        if ($reservation->isLive()) {
            return trans('statamic-resrv::frontend.statusConfirmed');
        }

        if ($reservation->status !== ReservationStatusEnum::REFUNDED->value) {
            return '';
        }

        // No-charge cancellations also end in REFUNDED; don't claim a refund when no money moved.
        return $reservation->hasGatewayPayment()
            ? trans('statamic-resrv::frontend.statusCancelled')
            : trans('statamic-resrv::frontend.statusCancelledNoRefund');
    }

    /**
     * Lookable statuses: live and cancelled. PENDING/EXPIRED were never confirmed, so no reference was issued.
     */
    protected function visibleStatuses(): array
    {
        return [
            ...ReservationStatusEnum::live(),
            ReservationStatusEnum::REFUNDED->value,
        ];
    }

    public function render()
    {
        return view('statamic-resrv::livewire.reservation-status');
    }
}
