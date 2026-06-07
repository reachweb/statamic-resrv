<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Money\Price as PriceClass;

trait HandlesPricing
{
    public function calculateReservationTotals(): Collection
    {
        // Init totals
        $total = Price::create(0);

        $reservationTotal = $this->reservation->price;

        $original = $this->getUpdatedPrices()['original_price'];
        $originalPrice = $original !== null ? Price::create($original) : null;

        // Calculate totals
        $extrasTotal = $this->calculateExtraTotals();
        $optionsTotal = $this->calculateOptionTotals();

        $total = $total->add($reservationTotal, $extrasTotal, $optionsTotal);

        $payment = $this->reservation->payment;
        $paymentSurcharge = $this->reservation->payment_surcharge;

        return collect(compact('total', 'reservationTotal', 'originalPrice', 'extrasTotal', 'optionsTotal', 'payment', 'paymentSurcharge'));
    }

    public function calculateAvailabilityTotals($availabilityTotal): PriceClass
    {
        $total = Price::create($availabilityTotal);

        $extrasTotal = $this->calculateExtraTotals();
        $optionsTotal = $this->calculateOptionTotals();

        return $total->add($extrasTotal, $optionsTotal);
    }

    public function calculateExtraTotals()
    {
        $extrasTotal = Price::create(0);

        if ($this->enabledExtras->extras->count() > 0) {
            $extrasTotal = $extrasTotal->add(...$this->enabledExtras->extras->map(fn ($extra) => Price::create($extra['price'])->multiply($extra['quantity']))->toArray());
        }

        return $extrasTotal;
    }

    public function calculateOptionTotals()
    {
        $optionsTotal = Price::create(0);

        if ($this->enabledOptions->options->count() > 0) {
            $optionsTotal = $optionsTotal->add(...$this->enabledOptions->options
                ->map(fn ($option) => Price::create($option['price']))
                ->toArray()
            );
        }

        return $optionsTotal;
    }

    public function freeCancellationPossible(?int $rateId = null): bool
    {
        $cancellation = $this->resolveCancellationPolicy($rateId);

        // A non-refundable booking has no free-cancellation window at all, so it is prepaid
        // in full — regardless of the full_payment_after_free_cancellation toggle, which only
        // governs what happens once a free-cancellation period has passed.
        if ($cancellation['policy'] === CancellationPolicy::NonRefundable) {
            return false;
        }

        if (config('resrv-config.full_payment_after_free_cancellation') === false) {
            return true;
        }
        $freeCancellation = $cancellation['period'];
        $freeCancellationDays = false;

        if ($this instanceof AvailabilityResults) {
            $dateStart = Carbon::parse($this->data->dates['date_start']);
            $freeCancellationDays = (int) Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)->diffInDays(now()->startOfDay(), true);
        }
        if ($this instanceof Checkout) {
            $dateStart = Carbon::parse($this->reservation->date_start);
            $freeCancellationDays = (int) Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)->diffInDays(now()->startOfDay(), true);
        }
        if ($freeCancellationDays !== false && $freeCancellationDays <= $freeCancellation) {
            return false;
        }

        return true;
    }

    private array $resolvedCancellationPolicies = [];

    /**
     * Resolve the cancellation terms in play: the reservation snapshot during checkout, the
     * rate's (or selected rate's) policy on availability results, the global default otherwise.
     *
     * @return array{policy: CancellationPolicy, period: ?int}
     */
    protected function resolveCancellationPolicy(?int $rateId = null): array
    {
        if ($this instanceof Checkout) {
            return $this->reservation->effectiveCancellationPolicy();
        }

        if (! $rateId && $this instanceof AvailabilityResults && is_numeric($this->data->rate ?? null)) {
            $rateId = (int) $this->data->rate;
        }

        if (! $rateId) {
            return CancellationPolicy::globalDefault();
        }

        return $this->resolvedCancellationPolicies[$rateId] ??= Rate::effectiveCancellationPolicyFor($rateId);
    }
}
