<?php

namespace Reach\StatamicResrv\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus as ReservationStatusEnum;
use Reach\StatamicResrv\Exceptions\CancellationNotAllowed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Livewire\Traits\HandlesCustomerLookup;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

class ReservationStatus extends Component
{
    use HandlesCustomerLookup;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string|max:255')]
    public string $reference = '';

    public bool $cancelled = false;

    public function mount(): void
    {
        if (! $this->featureEnabled()) {
            return;
        }

        $this->loadReservationFromUri();
    }

    /**
     * The whole page is opt-in. When disabled the blade renders nothing, and every action
     * no-ops server-side — Livewire actions can be invoked without the UI.
     */
    protected function featureEnabled(): bool
    {
        return (bool) config('resrv-config.enable_reservation_status_page');
    }

    protected function lookupRateLimiterPrefix(): string
    {
        return 'resrv-status-lookup';
    }

    public function lookup(): void
    {
        if (! $this->featureEnabled()) {
            return;
        }

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

    public function cancel(): void
    {
        if (! $this->featureEnabled()) {
            return;
        }

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
            // A retained payment must read as such — never as a refund. An unresolved
            // reference is not verified collected money, so it must not read as retained.
            return $reservation->hasGatewayPayment() && ! $reservation->payment_unresolved
                ? trans('statamic-resrv::frontend.statusCancelledNoRefundIssued')
                : trans('statamic-resrv::frontend.statusCancelledNoRefund');
        }

        if ($reservation->status === ReservationStatusEnum::COMPLETED->value) {
            return trans('statamic-resrv::frontend.statusCompleted');
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
     * Lookable statuses: live, terminated-after-confirmation, and completed stays — a
     * post-stay link visit must resolve, not claim the reservation doesn't exist.
     * PENDING/EXPIRED were never confirmed, so no reference was issued.
     */
    protected function visibleStatuses(): array
    {
        return [
            ...ReservationStatusEnum::live(),
            ReservationStatusEnum::REFUNDED->value,
            ReservationStatusEnum::CANCELLED->value,
            ReservationStatusEnum::COMPLETED->value,
        ];
    }

    public function render()
    {
        return view('statamic-resrv::livewire.reservation-status');
    }
}
