<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Resources\Concerns\ResolvesReservationEntries;

class Report
{
    use ResolvesReservationEntries;

    protected $date_start;

    protected $date_end;

    protected $dateField;

    protected $reservations;

    public function __construct($date_start, $date_end, string $dateField = 'date_start')
    {
        $this->date_start = $date_start;
        $this->date_end = $date_end;
        $this->dateField = $dateField;
        // Follow the money: the report covers reservations whose revenue the business holds —
        // confirmed/partner bookings plus no-refund cancellations that kept their gateway payment.
        // A returned charge always ends REFUNDED, and no-charge voids (partner / zero-payment)
        // carry an empty payment_id, so "cancelled with a payment_id" is the retained set — minus
        // payment_unresolved rows, whose payment_id is only a reconciliation handle on an
        // unverifiable intent that never collected money (cancelled unpaid holds).
        $this->reservations = Reservation::whereDate($this->dateField, '>=', $this->date_start)
            ->whereDate($this->dateField, '<=', $this->date_end)
            ->where(function ($query) {
                $query->whereIn('status', ['confirmed', 'partner'])
                    ->orWhere(function ($query) {
                        $query->where('status', 'cancelled')
                            ->whereNotNull('payment_id')
                            ->where('payment_id', '!=', '')
                            ->where('payment_unresolved', false);
                    });
            })
            ->get();
    }

    public function countReservations()
    {
        return $this->reservations->count();
    }

    public function sumRevenue(): PriceClass
    {
        // Accumulate in integer minor units (Money::add) rather than summing formatted decimal
        // strings as floats, which CLAUDE.md forbids and which can drift by a cent over many rows.
        return $this->reservations->reduce(
            fn (PriceClass $carry, $reservation) => $carry->add($reservation->price),
            Price::create(0)
        );
    }

    public function avgRevenue(): PriceClass
    {
        $count = $this->countReservations();

        if ($count === 0) {
            return Price::create(0);
        }

        return $this->sumRevenue()->divide((string) $count);
    }

    public function topSellerItems()
    {
        // Group the already-loaded reservations once instead of re-scanning the full collection
        // (and re-querying) per item; this also keeps the status set aligned with the rest of the report.
        $topItems = $this->reservations
            ->groupBy('item_id')
            ->sortByDesc(fn ($reservations) => $reservations->count())
            ->take(10);

        // Batch-resolve the top items' entries in one query instead of one Entry::find() per item.
        $entries = $this->resolveReservationEntries(
            $topItems->map(fn ($reservations) => $reservations->first())->values()
        );

        return $topItems->map(function ($reservations, $itemId) use ($entries) {
            $entry = $reservations->first()->entryToArray($entries->get($itemId));

            // Accumulate in integer minor units like sumRevenue() above — not
            // Collection::sum() over format() strings, which adds money as floats. The single
            // terminal (float) cast keeps the JSON numeric for the table sort and cannot drift.
            $totalRevenue = $reservations->reduce(
                fn (PriceClass $carry, $reservation) => $carry->add($reservation->price),
                Price::create(0)
            );

            return [
                'id' => $itemId,
                'title' => $entry['title'],
                'api_url' => $entry['url'],
                'reservations' => $reservations->count(),
                'quantity_sold' => (int) $reservations->sum('quantity'),
                'total_revenue' => (float) $totalRevenue->format(),
                'avg_revenue' => (float) (clone $totalRevenue)->divide((string) $reservations->count())->format(),
                'percentage' => round($reservations->count() / $this->countReservations(), 2),
            ];
        })->values();
    }

    public function topSellerExtras()
    {
        $extras = $this->getTopExtras();
        $extras->transform(function ($item) {
            $extra = Extra::withTrashed()->find($item->extra_id);

            return [
                'id' => $item->extra_id,
                'title' => $extra->name,
                'reservations' => (int) $item->occurrences,
                'percentage' => round($item->occurrences / $this->countReservations(), 2),
            ];
        });

        return $extras;
    }

    protected function getTopExtras()
    {
        return DB::table('resrv_reservation_extra')
            ->select('extra_id')
            ->addSelect(DB::raw('COUNT(reservation_id) AS occurrences'))
            ->whereIn('reservation_id', $this->reservations->pluck('id'))
            ->groupBy('extra_id')
            ->orderBy('occurrences', 'DESC')
            ->limit(10)
            ->get('occurrences');
    }

    public function affiliateSales(): Collection
    {
        $pivots = DB::table('resrv_reservation_affiliate')
            ->whereIn('reservation_id', $this->reservations->pluck('id'))
            // Voided commissions (refunds, no-charge voids) must never report. The status set
            // loaded above happens to exclude reservations with stamped pivots today, but this
            // filter — not that coincidence — is the contract.
            ->whereNull('cancelled_at')
            ->get(['reservation_id', 'affiliate_id', 'fee', 'data']);

        if ($pivots->isEmpty()) {
            return collect();
        }

        $reservationsById = $this->reservations->keyBy('id');

        // withTrashed so a soft-deleted affiliate still resolves its current name.
        $affiliates = Affiliate::withTrashed()
            ->whereIn('id', $pivots->pluck('affiliate_id')->unique())
            ->get()
            ->keyBy('id');

        return $pivots->groupBy('affiliate_id')->map(function ($rows, $affiliateId) use ($reservationsById, $affiliates) {
            // Accumulate in integer minor units via Price (CLAUDE.md forbids float-summing money).
            $sales = Price::create(0);
            $commission = Price::create(0);

            foreach ($rows as $row) {
                $reservation = $reservationsById->get($row->reservation_id);

                // Skip a missing reservation or a null total (the total accessor would throw on null).
                if (! $reservation || $reservation->getRawOriginal('total') === null) {
                    continue;
                }

                // The total accessor returns a fresh Price per access, so each is safe to mutate.
                $sales->add($reservation->total);
                $commission->add($reservation->total->multiply($row->fee / 100));
            }

            $affiliate = $affiliates->get($affiliateId);

            // Prefer a populated snapshot: pre-snapshot bookings have null data, so first() alone
            // could pick an empty row and mislabel a force-deleted affiliate that has a usable one.
            $snapshot = $rows
                ->map(fn ($row) => $this->decodeSnapshot($row->data))
                ->first(fn ($decoded) => ! empty($decoded)) ?? [];

            return [
                'id' => (int) $affiliateId,
                'title' => $affiliate?->name ?? ($snapshot['name'] ?? __('Deleted affiliate')),
                'deleted' => $affiliate ? $affiliate->trashed() : true,
                'reservations' => $rows->count(),
                'total_revenue' => (float) $sales->format(),
                'commission' => (float) $commission->format(),
            ];
        })->sortByDesc('reservations')->values();
    }

    public function dynamicPricingApplications(): Collection
    {
        $rows = DB::table('resrv_reservation_dynamic_pricing')
            ->select('dynamic_pricing_id')
            ->addSelect(DB::raw('COUNT(reservation_id) AS occurrences'))
            ->whereIn('reservation_id', $this->reservations->pluck('id'))
            ->groupBy('dynamic_pricing_id')
            ->orderBy('occurrences', 'DESC')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $rules = DynamicPricing::whereIn('id', $rows->pluck('dynamic_pricing_id'))->get()->keyBy('id');

        // Recover titles for hard-deleted rules from their pivot snapshot (one query, only if needed).
        // Scope to the selected reservations so a renamed rule keeps the title it had in this range,
        // not one leaked from an application outside it.
        $missing = $rows->pluck('dynamic_pricing_id')->reject(fn ($id) => $rules->has($id));
        $snapshots = $missing->isEmpty() ? collect() : DB::table('resrv_reservation_dynamic_pricing')
            ->whereIn('dynamic_pricing_id', $missing)
            ->whereIn('reservation_id', $this->reservations->pluck('id'))
            ->get(['dynamic_pricing_id', 'data'])
            ->groupBy('dynamic_pricing_id')
            ->map(fn ($group) => $this->decodeSnapshot($group->first()->data));

        $total = $this->countReservations();

        return $rows->map(function ($row) use ($rules, $snapshots, $total) {
            $rule = $rules->get($row->dynamic_pricing_id);
            $snapshot = $snapshots->get($row->dynamic_pricing_id, []);

            return [
                'id' => (int) $row->dynamic_pricing_id,
                'title' => $rule?->title ?? ($snapshot['title'] ?? __('Deleted rule')),
                'deleted' => $rule === null,
                'reservations' => (int) $row->occurrences,
                'percentage' => $total > 0 ? round($row->occurrences / $total, 2) : 0,
            ];
        })->values();
    }

    protected function decodeSnapshot(?string $data): array
    {
        $decoded = $data ? json_decode($data, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
