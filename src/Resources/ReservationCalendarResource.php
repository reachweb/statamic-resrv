<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Reach\StatamicResrv\Models\Reservation;
use Carbon\Carbon;

class ReservationCalendarResource extends ResourceCollection
{
    public $collects = Reservation::class;

    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray($request)
    {
        return $this->collection->transform(function ($reservation) {            
            return [
                'id' => $reservation->id,
                'title' => '#'.$reservation->id.' - '.$reservation->entry['title'].' - '.$reservation->location_start_data->name,
                'start' => $this->formatDate($reservation->date_start),
                'end' => $this->formatDate($reservation->date_end),
                'url' => cp_route('resrv.reservation.show', $reservation->id),
                'color' => 'hsl('.rand(0,359).','.rand(0,100).'%,'.rand(0,55).'%)'
            ];
        });
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

}