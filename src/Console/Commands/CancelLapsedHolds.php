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
            ->where('hold_expires_at', '<', now())
            ->get();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$candidates->count()} lapsed hold(s) would be cancelled.");

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($candidates as $reservation) {
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
            // The CANCELLED chain restores stock only when the reservation decremented it
            // (affects_availability), voids commission, logs, and emails both parties —
            // with the hold-lapsed wording carried by the context. Any open payment intent
            // is voided after the transition commits. The in-transaction origin re-check is
            // load-bearing: CANCELLED is also reachable from CONFIRMED, so without it a
            // webhook confirming between the candidate query and the row lock would let
            // the sweep cancel a PAID booking.
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
            // A webhook confirmed it between the candidate query and the row lock — the
            // customer paid at the buzzer. Correct outcome; leave it CONFIRMED.
            Log::info('Skipped lapsed-hold cancellation: the reservation changed state first.', [
                'reservation_id' => $reservation->id,
                'status' => $e->from->value,
            ]);

            return false;
        }
    }
}
