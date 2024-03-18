<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesReservationQueries
{
    public function createReservation()
    {
        $reservation = Reservation::create(
            [
                'status' => ReservationStatus::PENDING,
                'type' => ReservationTypes::NORMAL,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => $this->entryId,
                'date_start' => $this->data->dates['date_start'],
                'date_end' => $this->data->dates['date_end'],
                'quantity' => $this->data->quantity,
                'property' => $this->data->advanced,
                'price' => data_get($this->availability, 'data.total'),
                'payment' => data_get($this->availability, 'data.payment'),
                'payment_id' => '',
                'customer' => '',
            ]
        );

        ReservationCreated::dispatch($reservation);
    }
}
