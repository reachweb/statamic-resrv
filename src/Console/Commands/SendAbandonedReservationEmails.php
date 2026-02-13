<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationAbandoned;
use Reach\StatamicResrv\Models\Reservation;

class SendAbandonedReservationEmails extends Command
{
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
            ->whereDate('updated_at', '<=', $targetDate)
            ->with('customer')
            ->get();

        $toNotify = $reservations
            ->sortByDesc('updated_at')
            ->unique(fn ($reservation) => $reservation->customer->email);

        if ($toNotify->isEmpty()) {
            $this->info('No abandoned reservations found.');

            return self::SUCCESS;
        }

        $toNotify->each(function (Reservation $reservation) use ($reservations): void {
            Mail::to($reservation->customer->email)->send(new ReservationAbandoned($reservation));

            $reservations
                ->where('customer_id', $reservation->customer_id)
                ->each(fn (Reservation $r) => $r->update(['abandoned_email_sent_at' => now()]));
        });

        $this->info("Sent {$toNotify->count()} abandoned reservation email(s).");

        return self::SUCCESS;
    }
}
