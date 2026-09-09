<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
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
use Statamic\Facades\Dictionary;
use Statamic\Facades\User;
use Statamic\Fields\Field;

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
            ->map(fn ($field) => $this->serializeFormField($field));

        return response()->json([
            'rates' => $rates,
            'form_fields' => $formFields,
        ]);
    }

    /**
     * A checkout form field as the create page renders it. Dictionary fields carry their
     * resolved items and phone flag, mirroring Livewire\CheckoutForm.
     */
    protected function serializeFormField(Field $field): array
    {
        $data = $field->toArray();

        if ($field->type() !== 'dictionary') {
            return $data;
        }

        $dictionary = $field->config()['dictionary'] ?? null;
        $data['phone_dictionary'] = $dictionary === 'country_phone_codes';
        $data['dictionary_items'] = [];

        // The CP renders phone as a free-typed tel input, so its item list is unused.
        if ($data['phone_dictionary']) {
            return $data;
        }

        $handle = is_array($dictionary) ? ($dictionary['type'] ?? null) : $dictionary;

        if ($handle && ($found = Dictionary::find($handle))) {
            $data['dictionary_items'] = collect($found->optionItems())
                ->map(fn ($item) => Arr::only($item->toArray(), ['value', 'label']))
                ->values()
                ->all();
        }

        return $data;
    }

    public function quote(QuoteManualReservationRequest $request)
    {
        $data = $request->validated();

        try {
            // The extras/options listings read the same session coupon as the quote, so they
            // must be serialized inside the same coupon-free scope or their per-unit prices
            // would not match the totals creation charges.
            $payload = $this->creator->withoutCheckoutSession(fn (): array => array_merge(
                $this->serializeQuote($this->creator->quote($data, requireCustomAmount: false)),
                [
                    'available_extras' => $this->extrasForEntry($data),
                    'available_options' => $this->optionsForEntry($data),
                ]
            ));
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function store(StoreManualReservationRequest $request)
    {
        try {
            $reservation = $this->creator->create($request->validated(), User::current(), requireGatewayForPayment: true);
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $reservation->id,
            'redirect' => cp_route('resrv.reservation.show', $reservation->id),
        ], 201);
    }

    /** Same payment-entry check that gates online gateways in the creator and link building on the model. */
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
                    'available' => $gateway['available'],
                ])->all(),
            ],
        ];
    }

    /** The published extras of the entry with prices for the requested stay — what the frontend extras step shows. */
    protected function extrasForEntry(array $data): array
    {
        $priceData = [
            'item_id' => $data['item_id'],
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $data['quantity'],
            'rate_id' => $data['rate_id'] ?? null,
        ];

        $customer = collect($data['customer'] ?? []);

        return Extra::getPriceForDates($priceData)->map(function ($extra) use ($priceData, $customer) {
            // Re-price custom-priced extras with the customer payload so the listed price matches
            // the quoted total — but only while the driving field holds a usable number:
            // getCustomPrice THROWS on unusable payloads, and an unfilled extra keeps the ×1 fallback.
            if ($extra->price_type === 'custom' && $extra->custom && is_numeric($customer->get($extra->custom))) {
                // Fresh instance: priceForDates mutates price, so re-pricing the transformed one would compound it.
                $extra->price = Extra::find($extra->id)->priceForDates(
                    array_merge($priceData, ['customer' => $customer])
                );
            }

            return [
                'id' => $extra->id,
                'name' => $extra->name,
                'price' => $extra->price->format(),
                'price_type' => $extra->price_type,
                // Driving field handle for custom pricing — the create form re-quotes when it changes.
                'custom' => $extra->custom,
                'allow_multiple' => (bool) $extra->allow_multiple,
                'maximum' => $extra->maximum,
                'description' => $extra->description,
            ];
        })->values()->all();
    }

    /** The published options of the entry (published values only) with per-value prices for the requested stay. */
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
            ->with(['values' => fn ($query) => $query->where('published', true)])
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
