<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Carbon\Carbon;
use Reach\StatamicResrv\Blueprints\ReservationBlueprint;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

class ReservationResource extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = Reservation::class;
    protected $blueprint;
    protected $columns;

    public function __construct($resource)
    {
        parent::__construct($resource);

        $reservationBlueprint = new ReservationBlueprint();
        $this->blueprint = $reservationBlueprint();
    }

    public function columnPreferenceKey($key)
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    public function toArray($request)
    {
        $this->setColumns();

        return [
            'data' => $this->collection->transform(function ($reservation) {                

                return [
                    'id' => $reservation->id,
                    'reference' => $reservation->reference,
                    'status' => $reservation->status,
                    'entry' => $reservation->entry,
                    'payment' => config('resrv-config.currency_symbol').' '.$reservation->payment,
                    'price' => config('resrv-config.currency_symbol').' '.$reservation->price,
                    'location_start' => $reservation->location_start_data,
                    'location_end' => $reservation->location_end_data,
                    'date_start' => $this->formatDate($reservation->date_start),
                    'date_end' => $this->formatDate($reservation->date_end),
                    'customer' => $reservation->customer,
                    'extras' => $reservation->extras,
                    'created_at' => $this->formatDate($reservation->created_at),
                    'updated_at' => $this->formatDate($reservation->updated_at),
                ];
            }),

            'meta' => [
                'columns' => $this->visibleColumns(),
            ],
        ];
    }

    private function setColumns()
    {
        $columns = $this->blueprint->columns();

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();
    }

    private function formatDate(?Carbon $date)
    {
        $format = config('statamic.cp.date_format').' H:i';

        if (! $date) {
            return null;
        }

        return $date->format($format);
    }
 
}