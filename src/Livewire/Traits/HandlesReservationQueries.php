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

    public function getReservation(): Reservation
    {
        $reservation = Reservation::find(session('resrv_reservation'));

        if (! $reservation) {
            throw new ReservationException('Reservation not found in the session.');
        }

        $error = match ($reservation->status) {
            ReservationStatus::CONFIRMED->value => 'This reservation is already confirmed.',
            ReservationStatus::WEBHOOK->value => 'This reservation is already paid. You cannot modify it.',
            ReservationStatus::EXPIRED->value => 'This reservation has expired. Please start over.',
            default => null,
        };

        if ($error) {
            throw new ReservationException($error);
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

        $rateId = ($this->data->rate && $this->data->rate !== 'any' && is_numeric($this->data->rate))
            ? (int) $this->data->rate
            : null;

        $reservation = Reservation::create(
            [
                'status' => ReservationStatus::PENDING,
                'type' => ReservationTypes::NORMAL,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => $this->entryId,
                'date_start' => $this->data->dates['date_start'],
                'date_end' => $this->data->dates['date_end'],
                'quantity' => $this->data->quantity,
                'property' => $this->data->rate,
                'rate_id' => $rateId,
                'price' => data_get($this->availability, 'data.price'),
                'payment' => data_get($this->availability, 'data.payment'),
                'payment_id' => '',
                'customer_id' => $customer->id ?? null,
            ]
        );

        ReservationCreated::dispatch($reservation, new ReservationData(
            affiliate: $this->getAffiliateIfCookieExists(),
            coupon: session('resrv_coupon'),
        ));
    }

    public function getAvailabilityDataFromReservation(): array
    {
        return [
            'date_start' => $this->reservation->date_start,
            'date_end' => $this->reservation->date_end,
            'quantity' => $this->reservation->quantity,
            'advanced' => $this->reservation->getRawOriginal('property'),
            'rate_id' => $this->reservation->rate_id,
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
