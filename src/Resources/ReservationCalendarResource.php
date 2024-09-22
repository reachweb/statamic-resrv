<?php

namespace Reach\StatamicResrv\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ReservationCalendarResource extends ResourceCollection
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray($request)
    {
        $childs = collect();
        $reservations = $this->collection->transform(function ($reservation) use ($request, &$childs) {
            if ($reservation->type === 'parent') {
                $childs->push($this->buildChildReservationArray($reservation, $request)->toArray());

                return false;
            }
            $data = [
                'id' => $reservation->id,
                'title' => '#'.$reservation->id.
                            ' - '.$reservation->entry['title'].
                            (config('resrv-config.enable_advanced_availability') ? ' - '.$reservation->getPropertyAttributeLabel() : '').
                            (config('resrv-config.maximum_quantity') > 1 ? ' x '.$reservation->quantity : ''),
                'start' => $this->formatDate($reservation->date_start),
                'end' => $this->formatDate($reservation->date_end),
                'url' => cp_route('resrv.reservation.show', $reservation->id),
            ];
            // Remove end date if we only want the start date
            if ($request->has('onlyStart')) {
                if ($request->query('onlyStart') == 1) {
                    $data['end'] = null;
                }
            }

            return $data;
        })->reject(fn ($item) => $item === false);
        $childs = $childs->flatten(1);

        return $reservations->concat($childs);
    }

    private function formatDate(?Carbon $date)
    {
        if (! $date) {
            return null;
        }

        if (config('resrv-config.enable_time') == false) {
            return $date->toDateString();
        }

        return $date->toIso8601String();
    }

    protected function buildChildReservationArray($reservation, $request)
    {
        return $reservation->childs->map(function ($child) use ($reservation, $request) {
            $data = [
                'id' => $reservation->id,
                'title' => '#'.$reservation->id.
                            ' - '.$reservation->entry['title'].
                            (config('resrv-config.enable_advanced_availability') ? ' - '.$child->getPropertyAttributeLabel() : '').
                            (config('resrv-config.maximum_quantity') > 1 ? ' x '.$child->quantity : ''),
                'start' => $this->formatDate($child->date_start),
                'end' => $this->formatDate($child->date_end),
                'url' => cp_route('resrv.reservation.show', $reservation->id),
                'color' => 'hsl('.rand(0, 359).','.rand(0, 100).'%,'.rand(0, 55).'%)',
            ];
            // Remove end date if we only want the start date
            if ($request->has('onlyStart')) {
                if ($request->query('onlyStart') == 1) {
                    $data['end'] = null;
                }
            }

            return $data;
        });
    }
}
