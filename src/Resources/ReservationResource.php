<?php

namespace Reach\StatamicResrv\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Blueprints\ReservationBlueprint;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

class ReservationResource extends ResourceCollection
{
    use HasRequestedColumns;

    protected $blueprint;

    protected $columns;

    public function __construct($resource)
    {
        parent::__construct($resource);
        $reservationBlueprint = new ReservationBlueprint;
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
                    'type' => Str::ucfirst($reservation->type),
                    'status' => $reservation->status,
                    'entry' => $reservation->entry,
                    'quantity' => $reservation->quantity,
                    'payment' => config('resrv-config.currency_symbol').' '.$reservation->payment->format(),
                    'price' => config('resrv-config.currency_symbol').' '.$reservation->price->format(),
                    'date_start' => $this->formatDate($reservation->date_start),
                    'date_end' => $this->formatDate($reservation->date_end),
                    'customer' => $reservation->customer,
                    'extras' => $reservation->extras,
                    'options' => $reservation->options,
                    'rate' => $reservation->getRateLabel(),
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

        if (config('resrv-config.maximum_quantity') == 1) {
            unset($columns['quantity']);
        }

        if (! \Reach\StatamicResrv\Models\Rate::withoutGlobalScopes()->exists()) {
            unset($columns['rate']);
        }

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
