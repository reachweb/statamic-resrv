<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\ManualReservationException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Requests\QuoteManualReservationRequest;
use Reach\StatamicResrv\Http\Requests\StoreManualReservationRequest;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\CheckoutFormResolver;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Statamic\Facades\User;

class ManualReservationCpController extends Controller
{
    public function __construct(
        protected ManualReservationCreator $creator,
        protected PaymentGatewayManager $gatewayManager,
    ) {}

    public function createCp()
    {
        return Inertia::render('resrv::Reservations/Create', [
            'entriesUrl' => cp_route('resrv.manual.entries'),
            'entryUrlTemplate' => cp_route('resrv.manual.entry', 'ITEMID'),
            'quoteUrl' => cp_route('resrv.manual.quote'),
            'storeUrl' => cp_route('resrv.manual.store'),
            'backUrl' => cp_route('resrv.reservations.index'),
            'currencySymbol' => config('resrv-config.currency_symbol'),
            'maximumQuantity' => (int) config('resrv-config.maximum_quantity'),
            'maximumReservationPeriod' => (int) config('resrv-config.maximum_reservation_period_in_days', 30),
            'minimumDaysBefore' => (int) config('resrv-config.minimum_days_before', 0),
            'gateways' => $this->gatewayManager->forCp(),
            'paymentEntryConfigured' => $this->paymentEntryConfigured(),
            'affiliates' => config('resrv-config.enable_affiliates')
                ? Affiliate::where('published', true)
                    ->get()
                    ->map(fn ($affiliate) => [
                        'id' => $affiliate->id,
                        'name' => $affiliate->name,
                        'code' => $affiliate->code,
                    ])
                    ->values()
                : null,
        ]);
    }

    public function entries()
    {
        return response()->json(
            ResrvEntry::where('enabled', true)
                ->orderBy('title')
                ->get()
                ->map(fn ($entry) => [
                    'item_id' => $entry->item_id,
                    'title' => $entry->title,
                    'collection' => $entry->collection,
                ])
                ->values()
        );
    }

    public function entry(string $item_id)
    {
        $rates = Rate::forEntry($item_id)
            ->published()
            ->get()
            ->map(fn ($rate) => ['id' => $rate->id, 'title' => $rate->title])
            ->values();

        $formFields = app(CheckoutFormResolver::class)
            ->resolveForEntryId($item_id)
            ->fields()
            ->values()
            ->map(fn ($field) => $field->toArray());

        return response()->json([
            'rates' => $rates,
            'form_fields' => $formFields,
        ]);
    }

    public function quote(QuoteManualReservationRequest $request)
    {
        $data = $request->validated();

        try {
            $quote = $this->creator->quote($data, requireCustomAmount: false);
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(array_merge($this->serializeQuote($quote), [
            'available_extras' => $this->extrasForEntry($data),
            'available_options' => $this->optionsForEntry($data),
        ]));
    }

    public function store(StoreManualReservationRequest $request)
    {
        try {
            $reservation = $this->creator->create($request->validated(), User::current());
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $reservation->id,
            'redirect' => cp_route('resrv.reservation.show', $reservation->id),
        ], 201);
    }

    /**
     * Whether the manual-reservations payment entry resolves to a usable page — the same
     * check that gates online gateways in the creator and link building on the model.
     */
    protected function paymentEntryConfigured(): bool
    {
        return Reservation::resolveCustomerPageEntry(config('resrv-config.manual_reservations_payment_entry')) !== null;
    }

    protected function serializeQuote(array $quote): array
    {
        return [
            'availability' => $quote['availability'],
            'pricing' => [
                'base_price' => $quote['pricing']['base_price']->format(),
                'original_base_price' => $quote['pricing']['original_base_price'],
                'extras_total' => $quote['pricing']['extras_total']->format(),
                'options_total' => $quote['pricing']['options_total']->format(),
                'total' => $quote['pricing']['total']->format(),
                'total_overridden' => $quote['pricing']['total_overridden'],
            ],
            'payment' => [
                'mode' => $quote['payment']['mode'],
                'amount' => $quote['payment']['amount']->format(),
                'surcharge' => $quote['payment']['surcharge']->format(),
                'amount_with_surcharge' => $quote['payment']['amount_with_surcharge']->format(),
                'gateways' => collect($quote['payment']['gateways'])->map(fn ($gateway) => [
                    'label' => $gateway['label'],
                    'surcharge' => $gateway['surcharge']->format(),
                    'amount_with_surcharge' => $gateway['amount_with_surcharge']->format(),
                ])->all(),
            ],
        ];
    }

    /** The published extras of the entry with prices for the requested stay — what the frontend extras step shows. */
    protected function extrasForEntry(array $data): array
    {
        return Extra::getPriceForDates([
            'item_id' => $data['item_id'],
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $data['quantity'],
            'rate_id' => $data['rate_id'] ?? null,
        ])->map(fn ($extra) => [
            'id' => $extra->id,
            'name' => $extra->name,
            'price' => $extra->price->format(),
            'price_type' => $extra->price_type,
            'allow_multiple' => (bool) $extra->allow_multiple,
            'maximum' => $extra->maximum,
            'description' => $extra->description,
        ])->values()->all();
    }

    /** The published options of the entry with per-value prices for the requested stay. */
    protected function optionsForEntry(array $data): array
    {
        $calcData = [
            'item_id' => $data['item_id'],
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $data['quantity'],
            'rate_id' => $data['rate_id'] ?? null,
        ];

        return Option::entry($data['item_id'])
            ->where('published', true)
            ->with('values')
            ->get()
            ->map(fn ($option) => $option->valuesPriceForDates($calcData))
            ->map(fn ($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'required' => (bool) $option->required,
                'values' => $option->values->map(fn ($value) => [
                    'id' => $value->id,
                    'name' => $value->name,
                    'price' => $value->price->format(),
                    'price_type' => $value->price_type,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
