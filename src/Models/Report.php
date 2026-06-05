<?php

namespace Reach\StatamicResrv\Models;

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
        $this->reservations = Reservation::whereDate($this->dateField, '>=', $this->date_start)
            ->whereDate($this->dateField, '<=', $this->date_end)
            ->whereIn('status', ['confirmed', 'partner'])
            ->get();
    }

    public function countConfirmedReservations()
    {
        return $this->reservations->count();
    }

    public function sumConfirmedReservations(): PriceClass
    {
        // Accumulate in integer minor units (Money::add) rather than summing formatted decimal
        // strings as floats, which CLAUDE.md forbids and which can drift by a cent over many rows.
        return $this->reservations->reduce(
            fn (PriceClass $carry, $reservation) => $carry->add($reservation->price),
            Price::create(0)
        );
    }

    public function avgConfirmedReservations(): PriceClass
    {
        $count = $this->countConfirmedReservations();

        if ($count === 0) {
            return Price::create(0);
        }

        return $this->sumConfirmedReservations()->divide((string) $count);
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

            return [
                'id' => $itemId,
                'title' => $entry['title'],
                'api_url' => $entry['url'],
                'reservations' => $reservations->count(),
                'total_revenue' => round($reservations->sum(fn ($reservation) => $reservation->price->format()), 2),
                'avg_revenue' => round($reservations->avg(fn ($reservation) => $reservation->price->format()), 2),
                'percentage' => round($reservations->count() / $this->countConfirmedReservations(), 2),
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
                'percentage' => round($item->occurrences / $this->countConfirmedReservations(), 2),
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
}
