<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Exceptions\ReservationExpiredException;
use Reach\StatamicResrv\Exceptions\ReservationTerminatedException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;

trait HandlesReservationQueries
{
    use HandlesAffiliates;

    public function getReservation(): Reservation
    {
        try {
            $reservation = Reservation::findOrFail(session('resrv_reservation'));
        } catch (ModelNotFoundException $e) {
            throw new ReservationException('Reservation not found in the session.');
        }

        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            throw new ReservationTerminatedException(ReservationStatus::CONFIRMED, 'This reservation is already confirmed.');
        }

        if ($reservation->status === ReservationStatus::PARTNER->value) {
            throw new ReservationTerminatedException(ReservationStatus::PARTNER, 'This reservation is already confirmed.');
        }

        if ($reservation->status === ReservationStatus::REFUNDED->value) {
            throw new ReservationTerminatedException(ReservationStatus::REFUNDED, 'This reservation has been cancelled.');
        }

        if ($reservation->status === ReservationStatus::WEBHOOK->value) {
            throw new ReservationException('This reservation is already paid. You cannot modify it.');
        }

        if ($reservation->status === ReservationStatus::EXPIRED->value) {
            throw new ReservationExpiredException('This reservation has expired. Please start over.');
        }

        // Time-based expiration at read — don't wait for the background job to run. If the
        // hold window has elapsed, trigger expire() synchronously (which also cancels the
        // Stripe intent) and surface the same terminal error.
        $holdMinutes = config('resrv-config.minutes_to_hold');
        if ($holdMinutes && $reservation->status === ReservationStatus::PENDING->value) {
            $expireAt = Carbon::parse($reservation->created_at)->addMinutes($holdMinutes);
            if ($expireAt <= Carbon::now()) {
                $reservation->expire();
                throw new ReservationExpiredException('This reservation has expired. Please start over.');
            }
        }

        return $reservation;
    }

    public function createReservation(): void
    {
        $customer = $this->createCustomerFromData();

        $rateId = ($this->data->rate && $this->data->rate !== 'any' && is_numeric($this->data->rate))
            ? (int) $this->data->rate
            : null;

        // Snapshot the resolved cancellation terms onto the reservation — later edits to the
        // rate or the global config must not change what this customer agreed to at booking.
        $cancellation = Rate::effectiveCancellationPolicyFor($rateId);

        // Dispatch inside the transaction so a failing side effect (e.g. the locked
        // DecreaseAvailability throwing when stock ran out in the TOCTOU window) rolls
        // back the reservation row instead of leaving an orphan PENDING reservation.
        DB::transaction(function () use ($rateId, $cancellation, $customer) {
            $reservation = Reservation::create(
                [
                    'status' => ReservationStatus::PENDING,
                    'type' => ReservationTypes::NORMAL->value,
                    'reference' => (new Reservation)->createRandomReference(),
                    'item_id' => $this->entryId,
                    'date_start' => $this->data->dates['date_start'],
                    'date_end' => $this->data->dates['date_end'],
                    'quantity' => $this->data->quantity,
                    'rate_id' => $rateId,
                    'cancellation_policy' => $cancellation['policy']->value,
                    'free_cancellation_period' => $cancellation['period'],
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
        });
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

        // Snapshot cancellation terms: each child freezes its own rate's policy, the parent
        // freezes the strictest one — the parent's snapshot is what gates the (single) payment.
        // Each selection carries its own check-in date because deadlines, not raw periods,
        // decide strictness when the cart mixes start dates.
        $rates = Rate::withTrashed()
            ->findMany($selections->pluck('rate_id')->filter()->unique())
            ->keyBy('id');
        $childCancellations = $selections->map(
            fn ($selection) => [
                ...($rates->get($selection['rate_id'])?->effectiveCancellationPolicy()
                    ?? CancellationPolicy::globalDefault()),
                'date_start' => $selection['date_start'],
            ]
        );
        $parentCancellation = Reservation::strictestCancellationPolicy($childCancellations);

        // Dispatch inside the transaction so a failing child decrement rolls back the
        // parent + every child row (and the sibling decrements already applied), instead
        // of committing the rows and leaving stock partially decremented.
        DB::transaction(function () use ($selections, $totalPrice, $totalPayment, $customer, $childPrices, $childCancellations, $parentCancellation) {
            $reservation = Reservation::create([
                'status' => ReservationStatus::PENDING,
                'type' => ReservationTypes::PARENT->value,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => $this->entryId,
                'date_start' => $selections->min('date_start'),
                'date_end' => $selections->max('date_end'),
                'quantity' => $selections->sum('quantity'),
                'cancellation_policy' => $parentCancellation['policy']->value,
                'free_cancellation_period' => $parentCancellation['period'],
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
                    'cancellation_policy' => $childCancellations[$index]['policy']->value,
                    'free_cancellation_period' => $childCancellations[$index]['period'],
                    'price' => $childPrices[$index],
                ]);
            }

            ReservationCreated::dispatch($reservation, new ReservationData(
                affiliate: $this->getAffiliateIfCookieExists(),
                coupon: session('resrv_coupon'),
            ));
        });
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

    private ?array $updatedPricesCache = null;

    private ?Reservation $updatedPricesCacheReservation = null;

    public function getUpdatedPrices(): array
    {
        // Memoise per request: multiple callers hit this in one Livewire render, and a parent cart
        // issues one getPricing() query per child. Cache invalidates when the reservation is reloaded.
        if ($this->updatedPricesCache !== null && $this->updatedPricesCacheReservation === $this->reservation) {
            return $this->updatedPricesCache;
        }

        $this->updatedPricesCacheReservation = $this->reservation;

        if ($this->reservation->type === ReservationTypes::PARENT->value) {
            return $this->updatedPricesCache = $this->getUpdatedPricesForParent();
        }

        return $this->updatedPricesCache = (new Availability)->getPricing($this->getAvailabilityDataFromReservation(), $this->reservation->item_id);
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
            } else {
                $totalOriginalPrice->add(Price::create($childPricing['price']));
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
        // Use the amount actually due now: a zero deposit with an always-now surcharge is not zero.
        return $this->reservation->payableNow()->isZero();
    }
}
