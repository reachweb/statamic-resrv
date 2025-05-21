<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Customer;
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
        $customer = null;

        if (! empty($this->data->customer)) {
            $customer = Customer::create([
                'email' => $this->data->customer['email'] ?? '',
                'data' => collect($this->data->customer)->except('email'),
            ]);
        }

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
                'customer_id' => $customer->id ?? null,
            ]
        );

        ReservationCreated::dispatch($reservation, new ReservationData(
            affiliate: $this->getAffiliateIfCookieExists(),
            coupon: session('resrv_coupon') ?? null,
        ));
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
