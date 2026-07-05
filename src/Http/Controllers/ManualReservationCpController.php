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
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Support\CheckoutFormResolver;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Statamic\Facades\Entry;
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
            // The meta payload inline — saves the page a round trip.
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
            'paymentConfig' => [
                'type' => config('resrv-config.payment'),
                'fixed_amount' => config('resrv-config.fixed_amount'),
                'percent_amount' => config('resrv-config.percent_amount'),
            ],
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
            ->map(fn ($field) => $field->toArray())
            ->values();

        return response()->json([
            'rates' => $rates,
            'form_fields' => $formFields,
        ]);
    }

    public function meta()
    {
        return response()->json([
            'gateways' => $this->gatewayManager->forCp(),
            'payment_entry_configured' => $this->paymentEntryConfigured(),
            'affiliates' => config('resrv-config.enable_affiliates')
                ? Affiliate::where('published', true)
                    ->get()
                    ->map(fn ($affiliate) => [
                        'id' => $affiliate->id,
                        'name' => $affiliate->name,
                        'code' => $affiliate->code,
                        'fee' => (float) $affiliate->fee,
                    ])
                    ->values()
                : null,
            'currency_symbol' => config('resrv-config.currency_symbol'),
            'payment_config' => [
                'type' => config('resrv-config.payment'),
                'fixed_amount' => config('resrv-config.fixed_amount'),
                'percent_amount' => config('resrv-config.percent_amount'),
            ],
        ]);
    }

    public function quote(QuoteManualReservationRequest $request)
    {
        $data = $request->validated();

        try {
            $quote = $this->creator->quote($data);
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
        $data = $request->validated();

        try {
            $quote = $this->creator->quote($data);
            $this->assertGatewayIsUsable($data['payment_gateway'], $quote['payment']['amount']);

            $reservation = $this->creator->create($data, User::current());
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $reservation->id,
            'redirect' => cp_route('resrv.reservation.show', $reservation->id),
        ], 201);
    }

    /**
     * @throws ManualReservationException when the gateway cannot be used for this booking
     */
    protected function assertGatewayIsUsable(string $gateway, PriceClass $amount): void
    {
        // Without a configured (published, routable) payment page there is no link to pay
        // an online gateway through — only manually-confirmable (offline) gateways work.
        if (! $this->paymentEntryConfigured()
            && ! $this->gatewayManager->gateway($gateway)->supportsManualConfirmation()) {
            throw new ManualReservationException(
                __('The payment page entry is not configured, so only payment methods that support manual confirmation can be used.')
            );
        }

        if (! $amount->isZero() && ! $this->gatewayManager->isAvailableFor($gateway, $amount)) {
            throw new ManualReservationException(
                __('The requested amount is outside the allowed limits for this payment method.')
            );
        }
    }

    /**
     * Mirrors the entry checks of Reservation::customerStatusUrl() for the
     * manual-reservations payment entry: configured, published, public and routable.
     */
    protected function paymentEntryConfigured(): bool
    {
        $entryId = config('resrv-config.manual_reservations_payment_entry');

        if (is_array($entryId)) {
            $entryId = $entryId[0] ?? null;
        }

        if (! is_string($entryId) && ! is_int($entryId)) {
            return false;
        }

        $entryId = (string) $entryId;

        if ($entryId === '') {
            return false;
        }

        $entry = Entry::find($entryId);

        return $entry && $entry->published() && ! $entry->private() && $entry->url();
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
            ->map(fn ($option) => Option::find($option->id)->valuesPriceForDates($calcData))
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
