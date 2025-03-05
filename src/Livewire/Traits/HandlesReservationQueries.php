<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesReservationQueries
{
    use HandlesAffiliates;

    public function getReservation()
    {
        try {
            $reservation = Reservation::findOrFail(session('resrv_reservation'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw new ReservationException('Reservation not found in the session.');
        }

        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            throw new ReservationException('This reservation is already confirmed.');
        }

        if ($reservation->status === ReservationStatus::WEBHOOK->value) {
            throw new ReservationException('This reservation is already paid. You cannot modify it.');
        }

        if ($reservation->status === ReservationStatus::EXPIRED->value) {
            throw new ReservationException('This reservation has expired. Please start over.');
        }

        return $reservation;
    }

    public function createReservation(): void
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
                'customer' => $this->data->customer ?? '',
            ]
        );

        ReservationCreated::dispatch($reservation, new ReservationData(
            affiliate: $this->getAffiliateIfCookieExists(),
            coupon: session('resrv_coupon') ?? null,
        ));
    }

    public function createMultipleReservations(): void
    {
        $justDates = $this->cart->items->map(fn ($item) => $item->availabilityData['dates'])->flatten();

        $mainReservation = Reservation::create(
            [
                'status' => ReservationStatus::PENDING,
                'type' => ReservationTypes::PARENT,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => 'parent',
                'date_start' => $justDates->min(),
                'date_end' => $justDates->max(),
                'quantity' => $this->cart->items->reduce(fn ($carry, $item) => $carry + $item->availabilityData['quantity'], 0),
                'property' => null,
                'price' => $this->calculateCartTotalPrice(),
                'payment' => $this->calculateCartPaymentAmount(),
                'payment_id' => '',
                'customer' => [],
            ]
        );

        $childs = $this->cart->items->map(function ($item) {
            return [
                'item_id' => Entry::whereItemId($item->entryId)->id,
                'date_start' => $item->availabilityData['dates']['date_start'],
                'date_end' => $item->availabilityData['dates']['date_end'],
                'quantity' => $item->availabilityData['quantity'],
                'property' => $item->availabilityData['advanced'],
                'price' => data_get($item->results, 'data.price'),
                'payment' => data_get($item->results, 'data.payment'),
            ];
        });

        $mainReservation->childs()->createMany($childs->toArray());
    }

    public function getAvailabilityDataFromReservation(): array
    {
        return [
            'date_start' => $this->reservation->date_start,
            'date_end' => $this->reservation->date_end,
            'quantity' => $this->reservation->quantity,
            'advanced' => $this->reservation->property,
        ];
    }

    public function getUpdatedPrices(): array
    {
        return (new Availability)->getPricing($this->getAvailabilityDataFromReservation(), $this->reservation->item_id);
    }

    public function reservationPaymentIsZero(): bool
    {
        return $this->reservation->payment->isZero();
    }
}
