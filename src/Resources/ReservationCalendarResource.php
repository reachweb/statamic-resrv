<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Reach\StatamicResrv\Models\Reservation;
use Carbon\Carbon;

class ReservationCalendarResource extends ResourceCollection
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray($request)
    {
        return $this->collection->transform(function ($reservation) use ($request) {   
            $data = [
                'id' => $reservation->id,
                'title' => '#'.$reservation->id.
                            ' - '.$reservation->entry['title'].                            
                            (config('resrv-config.enable_advanced_availability') ? ' - '.$reservation->property : '').
                            (config('resrv-config.enable_locations') ? ' - '.$reservation->location_start_data->name : '').
                            (config('resrv-config.maximum_quantity') > 1 ? ' x '.$reservation->quantity : ''),
                'start' => $this->formatDate($reservation->date_start),
                'end' => $this->formatDate($reservation->date_end),
                'url' => cp_route('resrv.reservation.show', $reservation->id),
                'color' => 'hsl('.rand(0,359).','.rand(0,100).'%,'.rand(0,55).'%)'
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