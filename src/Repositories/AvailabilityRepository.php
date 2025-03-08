<?php

namespace Reach\StatamicResrv\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;

class AvailabilityRepository
{
    public function availableBetween(string $date_start, string $date_end, int $duration, int $quantity, array $advanced)
    {
        return Availability::selectRaw('count(statamic_id) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, property')
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id', 'property')
            ->having('days', '=', $duration);
    }

    public function itemAvailableBetween(string $date_start, string $date_end, int $duration, int $quantity, string $statamic_id, array $advanced)
    {
        return Availability::selectRaw('count(date) as days, group_concat(price) as prices, group_concat(date) as dates, statamic_id, max(available) as available, max(property) as property')
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->where('available', '>=', $quantity)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id')
            ->having('days', '=', $duration);
    }

    public function itemPricesBetween(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        return Availability::selectRaw('group_concat(price) as prices, statamic_id')
            ->where('statamic_id', $statamic_id)
            ->where('date', '>=', $date_start)
            ->where('date', '<', $date_end)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->groupBy('statamic_id');
    }

    public function itemsExistAndHavePrices(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        $query = Availability::where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            });

        $totalDays = $query->count();
        $daysWithPrices = $query->whereNotNull('price')->count();

        $expectedDays = (int) Carbon::parse($date_start)->diffInDays(Carbon::parse($date_end)->addDay(), true);

        return $totalDays > 0 && $totalDays === $daysWithPrices && $totalDays === $expectedDays;
    }

    public function itemGetProperties(string $statamic_id)
    {
        return Availability::select('property')
            ->where('statamic_id', $statamic_id)
            ->groupBy('property')
            ->get();
    }

    public function decrement(Reservation|ChildReservation $reservation)
    {
        if ($reservation instanceof ChildReservation) {
            $reservation->item_id = $reservation->entry->item_id;
        }

        DB::transaction(function () use ($reservation) {
            $availabilities = Availability::where('date', '>=', $reservation->date_start->isoFormat('YYYY-MM-DD'))
                ->where('date', '<', $reservation->date_end->isoFormat('YYYY-MM-DD'))
                ->where('statamic_id', $reservation->item_id)
                ->when($this->createAdvanced($reservation->property), function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->sharedLock()
                ->get();

            foreach ($availabilities as $availability) {
                $pending = $availability->pending ?? [];
                $reservationId = $reservation instanceof ChildReservation ? 'child-'.$reservation->id : $reservation->id;

                if (in_array($reservationId, $pending)) {
                    Log::error("Reservation ID {$reservationId} was already found in pending list for availability ID {$availability->id}");

                    continue;
                }

                $available = $availability->available - $reservation->quantity;
                $availability->update([
                    'available' => $available,
                    'pending' => array_merge($pending, [$reservationId]),
                ]);
            }
        });
    }

    public function increment(Reservation|ChildReservation $reservation): void
    {
        if ($reservation instanceof ChildReservation) {
            $reservation->item_id = $reservation->entry->item_id;
        }

        DB::transaction(function () use ($reservation) {
            $availabilities = Availability::where('date', '>=', $reservation->date_start->isoFormat('YYYY-MM-DD'))
                ->where('date', '<', $reservation->date_end->isoFormat('YYYY-MM-DD'))
                ->where('statamic_id', $reservation->item_id)
                ->when($this->createAdvanced($reservation->property), function (Builder $query, array $advanced) {
                    if (! in_array('any', $advanced)) {
                        $query->whereIn('property', $advanced);
                    }
                })
                ->sharedLock()
                ->get();

            foreach ($availabilities as $availability) {
                $pending = $availability->pending ?? [];
                $reservationId = $reservation instanceof ChildReservation ? 'child-'.$reservation->id : $reservation->id;

                if (! in_array($reservationId, $pending) && ! $reservation->isParent()) {
                    Log::error("Reservation ID {$reservationId} not found in pending list for availability ID {$availability->id}");

                    continue;
                }

                $available = $availability->available + $reservation->quantity;
                $availability->update([
                    'available' => $available,
                    'pending' => array_values(array_diff($pending, [$reservationId])),
                ]);
            }
        });
    }

    public function delete(string $date_start, string $date_end, string $statamic_id, array $advanced)
    {
        return Availability::where('date', '>=', $date_start)
            ->where('date', '<=', $date_end)
            ->where('statamic_id', $statamic_id)
            ->when($advanced, function (Builder $query, array $advanced) {
                if (! in_array('any', $advanced)) {
                    $query->whereIn('property', $advanced);
                }
            })
            ->delete();
    }

    private function createAdvanced($advanced): array
    {
        if ($advanced == null) {
            return ['none'];
        }

        return explode('|', $advanced);
    }
}
