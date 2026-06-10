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
     * Deep-link entry point: emails can link straight to the status page with
     * ?ref=ABC123&hash=<customerLookupHash()>. The HMAC proves the link came from an email
     * we sent, so no manual email entry is needed — same scheme as the reservation_from_uri tag.
     */
    protected function loadReservationFromUri(): void
    {
        $reference = request()->query('ref');
        $hash = request()->query('hash');

        if (! is_string($reference) || ! is_string($hash) || strlen($hash) !== 64) {
            return;
        }

        // Failed deep links draw from the same attempt budget as the lookup form, so the
        // mount path can't be used to enumerate references past the limiter.
        if (RateLimiter::tooManyAttempts($this->rateLimiterKey(), 10)) {
            return;
        }

        $reservation = Reservation::findForCustomerLookup($reference, $hash, $this->visibleStatuses());

        if ($reservation === null) {
            RateLimiter::hit($this->rateLimiterKey(), 600);

            return;
        }

        $this->reservationId = $reservation->id;
    }

    public function lookup(): void
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->rateLimiterKey(), 10)) {
            $this->addError('lookup', trans('statamic-resrv::frontend.tooManyLookupAttempts'));

            return;
        }

        $reservation = Reservation::findByReferenceForCustomer($this->reference, $this->email, $this->visibleStatuses());

        // One generic error for every failure mode so responses don't reveal whether a
        // reference exists — only a matching reference + email pair learns anything.
        // Successful lookups never clear the bucket: an attacker holding one valid booking
        // could otherwise reset the IP-wide budget after every nine guesses at other
        // references. Failed attempts simply decay after ten minutes.
        if (! $reservation) {
            RateLimiter::hit($this->rateLimiterKey(), 600);
            $this->addError('lookup', trans('statamic-resrv::frontend.reservationNotFound'));

            return;
        }

        $this->reservationId = $reservation->id;
    }

    protected function rateLimiterKey(): string
    {
        return 'resrv-status-lookup:'.sha1((string) request()->ip());
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
            // Gateway errors stay internal — the customer gets a generic "contact us" message,
            // and the failed refund is logged so the site owner can follow up.
            Log::warning('Customer cancellation failed.', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
            $this->addError('cancellation', trans('statamic-resrv::frontend.cancellationFailed'));

            return;
        } catch (\Throwable $e) {
            // Anything unexpected (gateway SDK connection/auth/rate-limit errors not mapped
            // to RefundFailedException, listener bugs) must not bubble up as a 500 — the
            // transition already rolled back, so report and show the same generic message.
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

        // No-charge cancellations (partner / zero-payment) end in REFUNDED too, but
        // claiming "& refunded" would tell the customer money moved when none did.
        return $reservation->hasGatewayPayment()
            ? trans('statamic-resrv::frontend.statusCancelled')
            : trans('statamic-resrv::frontend.statusCancelledNoRefund');
    }

    /**
     * Statuses a customer can legitimately look up: live bookings and cancelled ones.
     * PENDING rows are mid-checkout and EXPIRED ones were never confirmed — the customer
     * never received a reference for those.
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
