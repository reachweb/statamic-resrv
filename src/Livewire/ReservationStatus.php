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

    /**
     * Set when an attempted deep link (?ref=&hash=) failed to resolve for any reason, so the
     * blade can show a neutral "this link didn't work, use the form" notice instead of an
     * unexplained empty form. Never reveals which failure occurred (preserves the rate-limit
     * and reference-enumeration posture).
     */
    public bool $linkFailed = false;

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

        // No deep link supplied — render the normal lookup form.
        if (! is_string($reference) || $reference === '' || ! is_string($hash) || $hash === '') {
            return;
        }

        // A link was attempted: on any failure surface a single neutral notice (never the cause,
        // to preserve the rate-limit/enumeration posture) so the customer knows the link — not the
        // page — is at fault. Shares the lookup form's per-(IP, reference) budget so mount can't
        // brute-force the hash.
        if (strlen($hash) !== 64 || $this->tooManyLookupAttempts($reference)) {
            $this->linkFailed = true;

            return;
        }

        $reservation = Reservation::findForCustomerLookup($reference, $hash, $this->visibleStatuses());

        if ($reservation === null) {
            $this->recordFailedLookup($reference);
            $this->linkFailed = true;

            return;
        }

        $this->reservationId = $reservation->id;
    }

    public function lookup(): void
    {
        $this->linkFailed = false;

        $this->validate();

        if ($this->tooManyLookupAttempts($this->reference)) {
            $this->addError('lookup', trans('statamic-resrv::frontend.tooManyLookupAttempts'));

            return;
        }

        $reservation = Reservation::findByReferenceForCustomer($this->reference, $this->email, $this->visibleStatuses());

        // Generic error so responses don't reveal whether a reference exists. Success never clears
        // the buckets, so a valid email can't reset a reference's budget; failures decay after 10 min.
        if (! $reservation) {
            $this->recordFailedLookup($this->reference);
            $this->addError('lookup', trans('statamic-resrv::frontend.reservationNotFound'));

            return;
        }

        $this->reservationId = $reservation->id;
    }

    /**
     * Two buckets guard every lookup path. The tight per-(IP, reference) bucket caps guesses
     * per reference without letting one user exhaust a shared egress IP; on its own it is
     * bypassable by varying the reference (every guess gets a fresh bucket), so a looser
     * IP-wide bucket caps total failed lookups from one address across all references.
     */
    protected function tooManyLookupAttempts(string $reference): bool
    {
        return RateLimiter::tooManyAttempts($this->rateLimiterKey($reference), 10)
            || RateLimiter::tooManyAttempts($this->ipRateLimiterKey(), 30);
    }

    protected function recordFailedLookup(string $reference): void
    {
        RateLimiter::hit($this->rateLimiterKey($reference), 600);
        RateLimiter::hit($this->ipRateLimiterKey(), 600);
    }

    /**
     * Reference is normalized to match the lookup path.
     */
    protected function rateLimiterKey(string $reference): string
    {
        return 'resrv-status-lookup:'.sha1((string) request()->ip().'|'.strtoupper(trim($reference)));
    }

    protected function ipRateLimiterKey(): string
    {
        return 'resrv-status-lookup-ip:'.sha1((string) request()->ip());
    }

    public function cancel(): void
    {
        $reservation = $this->reservation;

        if (! $reservation) {
            return;
        }

        // Start each attempt clean so a successful retry never renders a prior failure's error.
        $this->resetErrorBag('cancellation');

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
        $this->linkFailed = false;
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

        if ($reservation->status === ReservationStatusEnum::REFUNDED->value) {
            // Rows refunded before CANCELLED existed include no-charge voids; don't claim
            // a refund when no money moved.
            return $reservation->hasGatewayPayment()
                ? trans('statamic-resrv::frontend.statusCancelled')
                : trans('statamic-resrv::frontend.statusCancelledNoRefund');
        }

        if ($reservation->status === ReservationStatusEnum::CANCELLED->value) {
            // A retained payment must read as such — never as a refund.
            return $reservation->hasGatewayPayment()
                ? trans('statamic-resrv::frontend.statusCancelledNoRefundIssued')
                : trans('statamic-resrv::frontend.statusCancelledNoRefund');
        }

        return '';
    }

    /**
     * What the just-completed cancellation did with the money, keyed off the terminal
     * status the processor chose — the blade must never claim a refund that didn't happen.
     */
    #[Computed]
    public function cancellationSuccessMessage(): string
    {
        $reservation = $this->reservation;

        if (! $reservation || ! $reservation->hasGatewayPayment()) {
            return trans('statamic-resrv::frontend.reservationCancelledNoPaymentSuccess');
        }

        return $reservation->status === ReservationStatusEnum::REFUNDED->value
            ? trans('statamic-resrv::frontend.reservationCancelledSuccess')
            : trans('statamic-resrv::frontend.reservationCancelledNoRefundSuccess');
    }

    /**
     * Lookable statuses: live and terminated-after-confirmation. PENDING/EXPIRED were never
     * confirmed, so no reference was issued.
     */
    protected function visibleStatuses(): array
    {
        return [
            ...ReservationStatusEnum::live(),
            ReservationStatusEnum::REFUNDED->value,
            ReservationStatusEnum::CANCELLED->value,
        ];
    }

    public function render()
    {
        return view('statamic-resrv::livewire.reservation-status');
    }
}
