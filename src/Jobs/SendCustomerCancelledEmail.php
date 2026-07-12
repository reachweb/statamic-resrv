<?php

namespace Reach\StatamicResrv\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;

class SendCustomerCancelledEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reservation;

    /**
     * Initialized outside the constructor because payloads queued before this property existed
     * carry no `context` key — SerializesModels::__unserialize skips missing keys and constructors
     * never run on unserialization, so a promoted (uninitialized) typed property would make
     * handle() throw and drop the cancellation email.
     */
    protected ?string $context = null;

    public function __construct(Reservation $reservation, ?string $context = null)
    {
        $this->reservation = $reservation;
        $this->context = $context;
    }

    public function handle(): void
    {
        /** @var ReservationEmailDispatcher $dispatcher */
        $dispatcher = app(ReservationEmailDispatcher::class);

        $dispatcher->send(
            $this->reservation,
            ReservationEmailEvent::CustomerCancelled,
            new ReservationCancelledCustomer($this->reservation, $this->context),
        );
    }
}
