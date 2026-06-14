<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Surcharge;
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
        $surchargeTotal = $this->calculateSurchargeTotals();

        $total = $total->add($reservationTotal, $extrasTotal, $optionsTotal, $surchargeTotal);

        $payment = $this->reservation->payment;
        $paymentSurcharge = $this->reservation->payment_surcharge;

        return collect(compact('total', 'reservationTotal', 'originalPrice', 'extrasTotal', 'optionsTotal', 'surchargeTotal', 'payment', 'paymentSurcharge'));
    }

    public function calculateAvailabilityTotals($availabilityTotal): PriceClass
    {
        $total = Price::create($availabilityTotal);

        $extrasTotal = $this->calculateExtraTotals();
        $optionsTotal = $this->calculateOptionTotals();

        return $total->add($extrasTotal, $optionsTotal, $this->calculateSurchargeTotals());
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

    public function calculateSurchargeTotals()
    {
        return Surcharge::totalForSelections($this->enabledOptions->selections());
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
        // The check-in date the free-cancellation window is measured against. AvailabilityMultiResults
        // resolves to null — it has no single date here and decides full payment at checkout from the
        // parent snapshot — so the window check is skipped (and any future caller fails safe, not silent).
        $dateStart = match (true) {
            $this instanceof AvailabilityResults => Carbon::parse($this->data->dates['date_start']),
            $this instanceof Checkout => Carbon::parse($this->reservation->date_start),
            default => null,
        };

        if ($dateStart === null) {
            return true;
        }

        // An unconfigured (NULL) period behaves like 0: the window only closes on check-in day.
        $freeCancellation = $cancellation['period'] ?? 0;
        $freeCancellationDays = (int) Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)->diffInDays(now()->startOfDay(), true);

        return $freeCancellationDays > $freeCancellation;
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
