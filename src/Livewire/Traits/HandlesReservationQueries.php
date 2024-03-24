<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesReservationQueries
{
    public function getReservation()
    {
        try {
            $reservation = Reservation::findOrFail(session('resrv_reservation'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new ReservationException('Reservation not found in the session.');
        }
        
        if ($reservation->status === ReservationStatus::WEBHOOK->value) {
            throw new ReservationException('This reservation is already paid. You cannot modify it.');
        }

        if ($reservation->status === ReservationStatus::EXPIRED->value) {
            throw new ReservationException('This reservation is expired. Please start over.');
        }

        return $reservation;
    }

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
                'price' => data_get($this->availability, 'data.price'),
                'payment' => data_get($this->availability, 'data.payment'),
                'payment_id' => '',
                'customer' => '',
            ]
        );

        ReservationCreated::dispatch($reservation);
    }

    public function getAvailabilityDataFromReservation()
    {
        return [
            'date_start' => $this->reservation->date_start,
            'date_end' => $this->reservation->date_end,
            'quantity' => $this->reservation->quantity,
            'advanced' => $this->reservation->property,
        ];
    }
}
