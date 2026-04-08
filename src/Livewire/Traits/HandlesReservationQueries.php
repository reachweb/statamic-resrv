<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
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
        $customer = $this->createCustomerFromData();

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

    public function createMultiReservation(Collection $selections): void
    {
        $customer = $this->createCustomerFromData();

        // Pre-compute child prices and total in a single pass
        $totalPrice = Price::create(0);
        $childPrices = [];
        $ignoreQuantityForPrices = config('resrv-config.ignore_quantity_for_prices', false);

        foreach ($selections as $index => $selection) {
            $quantity = $selection['quantity'];
            if ($quantity > 1 && ! $ignoreQuantityForPrices) {
                $childPrices[$index] = Price::create($selection['price'])
                    ->multiply((string) $quantity)->format();
            } else {
                $childPrices[$index] = $selection['price'];
            }
            $totalPrice->add(Price::create($childPrices[$index]));
        }

        $totalPayment = match (config('resrv-config.payment')) {
            'fixed' => Price::create(config('resrv-config.fixed_amount')),
            'percent' => Price::create($totalPrice->format())->percent(config('resrv-config.percent_amount')),
            default => Price::create($totalPrice->format()),
        };

        $reservation = DB::transaction(function () use ($selections, $totalPrice, $totalPayment, $customer, $childPrices) {
            $reservation = Reservation::create([
                'status' => ReservationStatus::PENDING,
                'type' => ReservationTypes::PARENT,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => $this->entryId,
                'date_start' => $selections->min('date_start'),
                'date_end' => $selections->max('date_end'),
                'quantity' => $selections->sum('quantity'),
                'price' => $totalPrice->format(),
                'payment' => $totalPayment->format(),
                'payment_id' => '',
                'customer_id' => $customer->id ?? null,
            ]);

            foreach ($selections as $index => $selection) {
                ChildReservation::create([
                    'reservation_id' => $reservation->id,
                    'date_start' => $selection['date_start'],
                    'date_end' => $selection['date_end'],
                    'quantity' => $selection['quantity'],
                    'rate_id' => $selection['rate_id'],
                    'price' => $childPrices[$index],
                ]);
            }

            return $reservation;
        });

        ReservationCreated::dispatch($reservation, new ReservationData(
            affiliate: $this->getAffiliateIfCookieExists(),
            coupon: session('resrv_coupon'),
        ));
    }

    protected function createCustomerFromData(): ?Customer
    {
        if (empty($this->data->customer)) {
            return null;
        }

        return Customer::create([
            'email' => $this->data->customer['email'] ?? '',
            'data' => collect($this->data->customer)->except('email'),
        ]);
    }

    public function getAvailabilityDataFromReservation(): array
    {
        return [
            'date_start' => $this->reservation->date_start,
            'date_end' => $this->reservation->date_end,
            'quantity' => $this->reservation->quantity,
            'rate_id' => $this->reservation->rate_id,
        ];
    }

    public function getUpdatedPrices(): array
    {
        if ($this->reservation->type === ReservationTypes::PARENT->value) {
            return $this->getUpdatedPricesForParent();
        }

        return (new Availability)->getPricing($this->getAvailabilityDataFromReservation(), $this->reservation->item_id);
    }

    protected function getUpdatedPricesForParent(): array
    {
        $totalPrice = Price::create(0);
        $totalOriginalPrice = Price::create(0);
        $hasOriginalPrice = false;

        foreach ($this->reservation->childs as $child) {
            $childPricing = (new Availability)->getPricing([
                'date_start' => $child->date_start,
                'date_end' => $child->date_end,
                'quantity' => $child->quantity,
                'rate_id' => $child->rate_id,
            ], $this->reservation->item_id);

            $totalPrice->add(Price::create($childPricing['price']));
            if ($childPricing['original_price']) {
                $hasOriginalPrice = true;
                $totalOriginalPrice->add(Price::create($childPricing['original_price']));
            }
        }

        $payment = match (config('resrv-config.payment')) {
            'fixed' => Price::create(config('resrv-config.fixed_amount')),
            'percent' => Price::create($totalPrice->format())->percent(config('resrv-config.percent_amount')),
            default => Price::create($totalPrice->format()),
        };

        return [
            'price' => $totalPrice->format(),
            'original_price' => $hasOriginalPrice ? $totalOriginalPrice->format() : null,
            'payment' => $payment->format(),
        ];
    }

    public function reservationPaymentIsZero(): bool
    {
        return $this->reservation->payment->isZero();
    }
}
