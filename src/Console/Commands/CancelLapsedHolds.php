<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled as ReservationCancelledEvent;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

class CancelLapsedHolds extends Command
{
    protected $signature = 'resrv:cancel-lapsed-holds
        {--dry-run : Report what would be cancelled without changing anything}';

    protected $description = 'Cancel awaiting-payment (manual) reservations whose payment hold has lapsed.';

    public function handle(): int
    {
        $candidates = Reservation::where('status', ReservationStatus::AWAITING_PAYMENT->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now());

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$candidates->count()} lapsed hold(s) would be cancelled.");

            return self::SUCCESS;
        }

        $cancelled = 0;

        // lazyById pages by primary key, not offset, so rows leaving the filtered set mid-run
        // (cancelling flips the status) don't shift the cursor; constant memory for any backlog.
        foreach ($candidates->lazyById(100) as $reservation) {
            if ($this->cancelLapsedHold($reservation)) {
                $cancelled++;
            }
        }

        $this->info("Cancelled {$cancelled} lapsed hold(s).");

        return self::SUCCESS;
    }

    protected function cancelLapsedHold(Reservation $reservation): bool
    {
        try {
            // The CANCELLED chain restores stock (when affects_availability), voids commission,
            // and emails with hold-lapsed wording. The in-transaction re-check is load-bearing:
            // CANCELLED is also reachable from CONFIRMED, so a webhook confirming between the
            // candidate query and the row lock must abort the sweep, not cancel a PAID booking.
            return app(ReservationRefundProcessor::class)->cancelWithoutRefund(
                $reservation,
                ReservationCancelledEvent::CONTEXT_HOLD_LAPSED,
                inTransaction: function (Reservation $fresh) {
                    if (! $fresh->isAwaitingPayment()) {
                        throw new InvalidStateTransition(
                            ReservationStatus::from($fresh->status),
                            ReservationStatus::CANCELLED,
                            $fresh->id,
                        );
                    }
                },
                cancelOpenIntent: true,
            );
        } catch (InvalidStateTransition $e) {
            // The customer paid at the buzzer — correct outcome; leave it CONFIRMED.
            Log::info('Skipped lapsed-hold cancellation: the reservation changed state first.', [
                'reservation_id' => $reservation->id,
                'status' => $e->from->value,
            ]);

            return false;
        }
    }
}
