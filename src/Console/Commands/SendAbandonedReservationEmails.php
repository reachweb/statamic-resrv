<?php

namespace Reach\StatamicResrv\Console\Commands;

use Carbon\Carbon;
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
        if (config('resrv-config.enable_abandoned_emails') !== true) {
            $this->info('Abandoned reservation emails are disabled. Set enable_abandoned_emails to true in resrv config.');

            return self::SUCCESS;
        }

        $delayDays = config('resrv-config.abandoned_email_delay_days', 1);
        $targetDate = Carbon::now()->subDays($delayDays);

        $reservations = Reservation::where('status', 'expired')
            ->whereNotNull('customer_id')
            ->whereDate('updated_at', $targetDate)
            ->with('customer')
            ->get();

        if ($reservations->isEmpty()) {
            $this->info('No abandoned reservations found for '.$targetDate->toDateString().'.');

            return self::SUCCESS;
        }

        $confirmedEmails = Reservation::where('status', 'confirmed')
            ->whereNotNull('customer_id')
            ->join('resrv_customers', 'resrv_reservations.customer_id', '=', 'resrv_customers.id')
            ->pluck('resrv_customers.email')
            ->unique();

        $toNotify = $reservations
            ->reject(fn ($reservation) => $confirmedEmails->contains($reservation->customer->email))
            ->sortByDesc('updated_at')
            ->unique(fn ($reservation) => $reservation->customer->email);

        if ($toNotify->isEmpty()) {
            $this->info('All abandoned customers already have confirmed reservations.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($toNotify as $reservation) {
            Mail::to($reservation->customer->email)->send(new ReservationAbandoned($reservation));
            $count++;
        }

        $this->info("Sent {$count} abandoned reservation email(s).");

        return self::SUCCESS;
    }
}
