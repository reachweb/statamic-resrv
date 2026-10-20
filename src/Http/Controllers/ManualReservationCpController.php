<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Entry as EntryFacade;
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
            'gateways' => collect($this->gatewayManager->forCp())->map(fn (array $gateway) => $gateway + [
                'description' => $gateway['supports_manual_confirmation']
                    ? __('Customer pays outside Resrv (e.g. bank transfer); you mark the reservation as paid.')
                    : __('Customer pays online through the payment link; the reservation confirms automatically.'),
            ])->all(),
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
        $collectionTitles = CollectionFacade::all()->mapWithKeys(fn ($collection) => [$collection->handle() => $collection->title()]);

        // Sorted by collection, then title, so the picker's collection badge reads as a group label.
        return response()->json(
            ResrvEntry::where('enabled', true)
                ->orderBy('title')
                ->get()
                ->map(fn ($entry) => [
                    'item_id' => $entry->item_id,
                    'title' => $entry->title,
                    'collection' => $entry->collection,
                    'collection_title' => $collectionTitles->get($entry->collection) ?? $entry->collection,
                ])
                ->sortBy([['collection_title', 'asc'], ['title', 'asc']])
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
            // So the create page can point at the entry's availability when dates have no price.
            'edit_url' => EntryFacade::find($item_id)?->editUrl(),
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
            return $this->domainErrorResponse($e);
        }

        return response()->json($payload);
    }

    public function store(StoreManualReservationRequest $request)
    {
        $data = $request->validated();
        $user = User::current();
        $key = $data['idempotency_key'] ?? null;

        if ($key === null || $key === '') {
            return $this->createReservation($data, $user);
        }

        // Scoped to the admin: a retry of a timed-out or failed-looking submit returns the first
        // reservation instead of booking (and decrementing stock) twice.
        $cacheKey = 'resrv_manual_create:'.($user?->id() ?? 'guest').':'.hash('sha256', $key);
        $fingerprint = $this->payloadFingerprint(Arr::except($data, ['idempotency_key']));

        $attempt = function () use ($cacheKey, $fingerprint, $data, $user): JsonResponse {
            $marker = Cache::get($cacheKey);
            $existing = is_array($marker) && isset($marker['id']) ? Reservation::find($marker['id']) : null;

            if ($existing) {
                return ($marker['fingerprint'] ?? null) === $fingerprint
                    ? $this->createdResponse($existing, 200, replayed: true)
                    : $this->alreadyCreatedResponse($existing);
            }

            // Marked as soon as the row commits, so a death or throw during the emails/confirmation
            // that follow still turns a same-key retry into a replay instead of a second booking.
            return $this->createReservation($data, $user, function (Reservation $reservation) use ($cacheKey, $fingerprint) {
                Cache::put($cacheKey, ['id' => $reservation->id, 'fingerprint' => $fingerprint], now()->addMinutes(30));
            });
        };

        // Without lock support (e.g. APC) the marker alone still makes sequential retries safe.
        if (! Cache::getStore() instanceof LockProvider) {
            return $attempt();
        }

        // The lock serializes a concurrent double submit; the creation (including its inline email) runs inside it.
        try {
            return Cache::lock($cacheKey.':lock', 120)->block(10, $attempt);
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => __('This reservation is still being created. Check the reservations list before trying again.'),
            ], 409);
        }
    }

    protected function createReservation(array $data, $user, ?Closure $onCommitted = null): JsonResponse
    {
        try {
            $reservation = $this->creator->create($data, $user, requireGatewayForPayment: true, onCommitted: $onCommitted);
        } catch (AvailabilityException|ManualReservationException|OptionsException|ExtrasException $e) {
            return $this->domainErrorResponse($e);
        }

        return $this->createdResponse($reservation, 201);
    }

    protected function createdResponse(Reservation $reservation, int $status, bool $replayed = false): JsonResponse
    {
        return response()->json([
            'id' => $reservation->id,
            'redirect' => cp_route('resrv.reservation.show', $reservation->id),
            'status' => $reservation->status,
            'payment_request_sent' => $reservation->payment_request_email_sent_at !== null,
            'replayed' => $replayed,
        ], $status);
    }

    /** The same form session already created a reservation, but this submit carries different input. */
    protected function alreadyCreatedResponse(Reservation $reservation): JsonResponse
    {
        return response()->json([
            'error' => __('A reservation was already created from this form (#:id). Open it to review before creating another.', ['id' => $reservation->id]),
            'redirect' => cp_route('resrv.reservation.show', $reservation->id),
            'code' => 'already_created',
        ], 409);
    }

    /** Order-insensitive hash of the validated input, so a replay can tell an identical retry from an edited one. */
    protected function payloadFingerprint(array $data): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode($normalize($data)));
    }

    /**
     * Domain errors as {error, field?, code?}: `field` names the create-form input the message
     * belongs to (so the page shows it under that control), `code` a cause the page branches on.
     */
    protected function domainErrorResponse(\Throwable $e): JsonResponse
    {
        $field = match (true) {
            $e instanceof ManualReservationException => $e->field,
            $e instanceof OptionsException => 'options',
            $e instanceof ExtrasException => 'extras',
            $e instanceof AvailabilityException => 'dates',
            default => null,
        };

        return response()->json(array_filter([
            'error' => $e->getMessage(),
            'field' => $field,
            'code' => $e instanceof ManualReservationException ? $e->reason : null,
        ], fn ($value) => $value !== null), 422);
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
                'computed_base_price' => $quote['pricing']['computed_base_price']->format(),
                'computed_total' => $quote['pricing']['computed_total']->format(),
                'adjustment' => $quote['pricing']['adjustment']->format(),
                'discount' => $quote['pricing']['discount']?->format(),
                'extras_total' => $quote['pricing']['extras_total']->format(),
                'options_total' => $quote['pricing']['options_total']->format(),
                'total' => $quote['pricing']['total']->format(),
                'balance' => $quote['pricing']['balance']->format(),
                'total_overridden' => $quote['pricing']['total_overridden'],
            ],
            'payment' => [
                'mode' => $quote['payment']['mode'],
                'amount' => $quote['payment']['amount']->format(),
                'surcharge' => $quote['payment']['surcharge']->format(),
                'amount_with_surcharge' => $quote['payment']['amount_with_surcharge']->format(),
                'standard_amount' => $quote['payment']['standard_amount']->format(),
                'full_amount' => $quote['payment']['full_amount']->format(),
                'gateways' => collect($quote['payment']['gateways'])->map(fn ($gateway) => [
                    'label' => $gateway['label'],
                    'surcharge' => $gateway['surcharge']->format(),
                    'surcharge_is_zero' => $gateway['surcharge']->isZero(),
                    'amount_with_surcharge' => $gateway['amount_with_surcharge']->format(),
                    'available' => $gateway['available'],
                ])->all(),
                'custom_amount_error' => $quote['payment']['custom_amount_error'],
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

        // Required by the entry's extra conditions for this stay and selection — creation refuses
        // the booking without them, so the form must show (and gate on) them up front.
        $required = $this->creator->requiredExtras($data);

        return Extra::getPriceForDates($priceData)->map(function ($extra) use ($priceData, $customer, $required) {
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
                'required' => $required->has($extra->id),
                'required_reason' => $required->get($extra->id),
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
