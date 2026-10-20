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
                $amountReceived = $reservation->amountReceived();

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
                    // Null for non-awaiting rows — the model gates the URL on status.
                    'payment_url' => $reservation->customerPaymentUrl(),
                    'status_label' => ReservationStatus::labelFor($reservation->status),
                    'payment_formatted' => $reservation->payment->format(),
                    'payment_surcharge_formatted' => $reservation->payment_surcharge->format(),
                    'payment_surcharge_is_zero' => $reservation->payment_surcharge->isZero(),
                    'total_to_charge_formatted' => $reservation->totalToCharge(),
                    'total_formatted' => $reservation->total->format(),
                    // Null when receipt is unknown (a frontend bank-transfer booking confirms unpaid).
                    'amount_paid_formatted' => $amountReceived?->format(),
                    'holds_no_charge' => $reservation->gatewayHoldsNoCharge(),
                    'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                    'payment_due_at' => $reservation->isAwaitingPayment() ? $reservation->paymentDueAt()?->toIso8601String() : null,
                    'payment_request_email_sent_at' => $reservation->payment_request_email_sent_at?->toIso8601String(),
                    'payment_unresolved' => (bool) $reservation->payment_unresolved,
                    // Overdue flags only describe a booking still waiting for its payment.
                    'arrival_day_passed' => $reservation->isAwaitingPayment() && $reservation->arrivalDayPassed(),
                    'payment_window_closed' => $reservation->isAwaitingPayment() && $reservation->paymentWindowClosed(),
                    'supports_manual_confirmation' => $reservation->paymentGatewaySupportsManualConfirmation(),
                    // A pending checkout is refunded through its gateway even before an intent is
                    // stored (it may hold an orphaned charge), so the gateway's capability applies.
                    'refund_is_automatic' => $reservation->status === ReservationStatus::PENDING->value
                        ? $reservation->supportsAutomaticRefund()
                        : $reservation->refundIsAutomatic(),
                    'customer_email' => $reservation->customer?->email,
                    'customer_name' => $reservation->customerName(),
                    'created_at' => $this->dateIndexValue('created_at', $reservation->created_at),
                    'updated_at' => $this->dateIndexValue('updated_at', $reservation->updated_at),
                    'created_at_iso' => $reservation->created_at?->toIso8601String(),
                    'date_start_iso' => $reservation->date_start?->toIso8601String(),
                    'date_end_iso' => $reservation->date_end?->toIso8601String(),
                    'stay_dates_have_time' => self::stayDatesHaveTime(),
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

    /** Stays are wall dates (read the leading Y-m-d of their ISO value) unless charged by time — ReservationBlueprint's split. */
    public static function stayDatesHaveTime(): bool
    {
        return (bool) config('resrv-config.calculate_days_using_time');
    }

    // The Listing component renders date columns with DateIndexFieldtype, which expects the
    // Date fieldtype's preProcessIndex() payload — a pre-formatted string would render as "now".
    private function dateIndexValue(string $handle, ?Carbon $date): ?array
    {
        $fieldtype = $this->dateFieldtypes[$handle] ??= $this->blueprint->field($handle)->fieldtype();

        return $fieldtype->preProcessIndex($date);
    }
}
