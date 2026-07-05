<?php

namespace Reach\StatamicResrv\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Blueprints\ReservationBlueprint;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Resources\Concerns\ResolvesReservationEntries;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

class ReservationResource extends ResourceCollection
{
    use HasRequestedColumns, ResolvesReservationEntries;

    protected $blueprint;

    protected $columns;

    protected $dateFieldtypes = [];

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

        $entries = $this->resolveReservationEntries($this->collection);

        return [
            'data' => $this->collection->transform(function ($reservation) use ($entries) {
                return [
                    'id' => $reservation->id,
                    'reference' => $reservation->reference,
                    'type' => Str::ucfirst($reservation->type),
                    'status' => $reservation->status,
                    'entry' => $reservation->entryToArray($entries->get($reservation->item_id)),
                    'quantity' => $reservation->quantity,
                    'payment' => config('resrv-config.currency_symbol').' '.$reservation->payment->format(),
                    'price' => config('resrv-config.currency_symbol').' '.$reservation->price->format(),
                    'date_start' => $this->dateIndexValue('date_start', $reservation->date_start),
                    'date_end' => $this->dateIndexValue('date_end', $reservation->date_end),
                    'customer' => ['email' => $reservation->customer?->email],
                    'extras' => $reservation->extras,
                    'options' => $reservation->options,
                    'rate' => $reservation->getRateLabel(),
                    'payment_gateway' => $reservation->payment_gateway
                        ? app(PaymentGatewayManager::class)->label($reservation->payment_gateway)
                        : null,
                    'affects_availability' => (bool) $reservation->affects_availability,
                    // Only resolved for awaiting rows: the URL requires an entry lookup per
                    // row and is only actionable while the payment is outstanding.
                    'payment_url' => $reservation->status === ReservationStatus::AWAITING_PAYMENT->value
                        ? $reservation->customerPaymentUrl()
                        : null,
                    'created_at' => $this->dateIndexValue('created_at', $reservation->created_at),
                    'updated_at' => $this->dateIndexValue('updated_at', $reservation->updated_at),
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

        if (! Cache::rememberForever('resrv_rates_exist', fn () => Rate::withoutGlobalScopes()->exists())) {
            unset($columns['rate']);
        }

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();
    }

    // The Listing component renders date columns with DateIndexFieldtype, which expects the
    // Date fieldtype's preProcessIndex() payload — a pre-formatted string would render as "now".
    private function dateIndexValue(string $handle, ?Carbon $date): ?array
    {
        $fieldtype = $this->dateFieldtypes[$handle] ??= $this->blueprint->field($handle)->fieldtype();

        return $fieldtype->preProcessIndex($date);
    }
}
