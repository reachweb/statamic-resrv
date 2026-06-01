<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationAbandoned;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;
use Statamic\Console\RunsInPlease;

class SendAbandonedReservationEmails extends Command
{
    use RunsInPlease;

    protected $signature = 'resrv:send-abandoned-emails';

    protected $description = 'Send recovery emails for expired reservations that have customer data';

    public function handle(): int
    {
        if (! config('resrv-config.enable_abandoned_emails')) {
            $this->info('Abandoned reservation emails are disabled. Set enable_abandoned_emails to true in resrv config.');

            return self::SUCCESS;
        }

        $delayDays = config('resrv-config.abandoned_email_delay_days', 1);
        $targetDate = now()->subDays($delayDays);

        $reservations = Reservation::where('status', 'expired')
            ->whereHas('customer')
            ->whereNull('abandoned_email_sent_at')
            ->where('updated_at', '<=', $targetDate)
            ->with('customer')
            ->get();

        $toNotify = $reservations
            ->sortByDesc('updated_at')
            ->unique(fn ($reservation) => $reservation->customer->email);

        if ($toNotify->isEmpty()) {
            $this->info('No abandoned reservations found.');

            return self::SUCCESS;
        }

        $sentCount = 0;
        /** @var ReservationEmailDispatcher $dispatcher */
        $dispatcher = app(ReservationEmailDispatcher::class);

        $toNotify->each(function (Reservation $reservation) use ($reservations, &$sentCount, $dispatcher): void {
            if ($dispatcher->send(
                $reservation,
                ReservationEmailEvent::CustomerAbandoned,
                new ReservationAbandoned($reservation),
            )) {
                $sentCount++;
                // Mark by recipient email (the dedupe key), not customer_id: a single
                // person abandoning multiple checkouts gets a fresh customer row each
                // time, so customer_id alone would leave the duplicates to resend.
                $reservations
                    ->filter(fn (Reservation $r) => $r->customer->email === $reservation->customer->email)
                    ->each(fn (Reservation $r) => $r->update(['abandoned_email_sent_at' => now()]));
            }
        });

        $this->info("Sent {$sentCount} abandoned reservation email(s).");

        return self::SUCCESS;
    }
}
