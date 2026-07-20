<?php

namespace Reach\StatamicResrv\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Enums\ManualPaymentMode;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\ManualReservationException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Jobs\ExpireReservations;
use Reach\StatamicResrv\Mail\ReservationPaymentRequest;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Repositories\AvailabilityRepository;
use Reach\StatamicResrv\Traits\HandlesAvailabilityDates;
use Reach\StatamicResrv\Traits\HandlesPricing;

/**
 * Owns the quote + creation of admin-created (manual) reservations. All money figures are
 * computed server-side through the same code paths the frontend checkout uses
 * (getPricing, calculatePrice, calculatePayment) — never trusted from the client.
 */
class ManualReservationCreator
{
    use HandlesAvailabilityDates, HandlesPricing;

    public function __construct(protected PaymentGatewayManager $gatewayManager) {}

    /**
     * Creates the reservation from validated input (shape validation lives in the HTTP
     * layer; money figures and domain invariants are re-asserted here).
     *
     * @param  array  $input  quote() input plus: customer (form data incl. email),
     *                        affects_availability?, affiliate_id?, hold_days?
     * @param  mixed  $creator  the Statamic user creating the reservation
     *
     * @throws AvailabilityException|ManualReservationException|OptionsException|ExtrasException
     */
    public function create(array $input, $creator = null, bool $requireGatewayForPayment = false): Reservation
    {
        return $this->withoutCheckoutSession(
            fn (): Reservation => $this->createReservation($input, $creator, $requireGatewayForPayment)
        );
    }

    protected function createReservation(array $input, $creator, bool $requireGatewayForPayment): Reservation
    {
        // Normalize once so the quote, overbook assert, stored rate_id and cancellation
        // snapshot all see the SAME validated rate (see resolveRateId()).
        $input['rate_id'] = $this->resolveRateId($input);

        $quote = $this->quote($input);

        $gateway = (string) ($input['payment_gateway'] ?? '');

        // The CP flow requires a gateway when money is collected now; a zero-amount booking
        // confirms immediately with nothing to pay through, so it may omit one.
        if ($requireGatewayForPayment && $gateway === '' && ! $quote['payment']['amount']->isZero()) {
            throw new ManualReservationException(__('A payment method is required to collect a payment.'));
        }

        // A blank gateway on a paid booking would resolve to the DEFAULT at pay time
        // (resolvePaymentGateway's legacy-row rule) while bypassing its limits, page gate and
        // surcharge — pin the default now so the row is validated and priced against the
        // gateway that will actually collect.
        if ($gateway === '' && ! $quote['payment']['amount']->isZero()) {
            $gateway = (string) $this->gatewayManager->defaultName();

            if ($gateway === '') {
                throw new ManualReservationException(__('No payment gateway is configured to collect a payment.'));
            }

            $quote['payment']['surcharge'] = $this->gatewayManager->calculateSurcharge($gateway, $quote['payment']['amount']);
            $quote['payment']['amount_with_surcharge'] = Price::create($quote['payment']['amount']->format())->add($quote['payment']['surcharge']);
        }

        if ($gateway !== '') {
            $this->assertGatewayIsUsable($gateway, $quote['payment']['amount']);
        }

        $affectsAvailability = (bool) ($input['affects_availability'] ?? true);

        if (! $quote['availability']['status']) {
            if ($affectsAvailability) {
                throw new AvailabilityException(__('There is not enough availability for the selected dates.'));
            }

            $this->assertOverbookOnlyBypassesStock($input);
        }

        $this->assertRequiredExtrasAndOptions($input);

        $affiliate = $this->resolveAffiliate($input['affiliate_id'] ?? null);
        $cancellation = Rate::effectiveCancellationPolicyFor($this->rateIdFrom($input));
        $holdDays = (int) ($input['hold_days'] ?? 0);

        $reservation = DB::transaction(function () use ($input, $quote, $gateway, $cancellation, $affectsAvailability, $affiliate, $holdDays, $creator) {
            $customer = $this->createCustomer($input);

            $reservation = Reservation::create([
                'status' => ReservationStatus::AWAITING_PAYMENT->value,
                'type' => ReservationTypes::NORMAL->value,
                'reference' => (new Reservation)->createRandomReference(),
                'item_id' => $input['item_id'],
                'date_start' => $input['date_start'],
                'date_end' => $input['date_end'],
                'quantity' => (int) ($input['quantity'] ?? 1),
                'rate_id' => $this->rateIdFrom($input),
                'cancellation_policy' => $cancellation['policy']->value,
                'free_cancellation_period' => $cancellation['period'],
                'price' => $quote['pricing']['base_price']->format(),
                'total' => $quote['pricing']['total']->format(),
                'payment' => $quote['payment']['amount']->format(),
                'payment_surcharge' => $quote['payment']['surcharge']->format(),
                'payment_gateway' => $gateway,
                'payment_id' => '',
                'customer_id' => $customer?->id,
                'affects_availability' => $affectsAvailability,
                'created_by' => $creator !== null ? (string) $creator->id() : null,
                'hold_expires_at' => $holdDays >= 1 ? now()->addDays($holdDays) : null,
            ]);

            if ($quote['extras']->isNotEmpty()) {
                $reservation->extras()->sync(
                    $quote['extras']->mapWithKeys(fn ($extra, $id) => [
                        $id => ['quantity' => $extra['quantity'], 'price' => $extra['price']],
                    ])
                );
            }

            if ($quote['options']->isNotEmpty()) {
                $reservation->options()->sync(
                    $quote['options']->mapWithKeys(fn ($option, $id) => [
                        $id => ['value' => $option['value']],
                    ])
                );
            }

            // Inside the transaction, as in checkout: a DecreaseAvailability throw (TOCTOU
            // stock loss) rolls back the reservation row.
            ReservationCreated::dispatch($reservation, new ReservationData(
                affiliate: $affiliate,
                viaCp: true,
                skipDynamicPricings: $quote['pricing']['total_overridden'],
            ));

            return $reservation;
        });

        // Mirrors Checkout::handleReservationWithZeroPayment(): keyed off the payment amount,
        // not the grand total. Nothing collected now → confirm immediately; never a payment
        // request for 0.00 or an awaiting-payment hold the lapse sweep would later cancel.
        if ($quote['payment']['amount']->isZero()) {
            if ($reservation->transitionTo(ReservationStatus::CONFIRMED)) {
                // Everything is committed; a throwing synchronous listener must not turn the
                // creation into a 500 the admin would retry. The confirmation email can be
                // resent from the reservation page.
                try {
                    ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_CP);
                } catch (\Throwable $e) {
                    Log::error('Manual reservation confirmed but a ReservationConfirmed listener failed.', [
                        'reservation_id' => $reservation->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $reservation->fresh();
        }

        // Already committed: a failing mail transport must not cause a retry that would create
        // a second reservation. The payment request can be resent from the reservation page.
        if ((bool) ($input['send_payment_request_email'] ?? true)) {
            try {
                $this->sendPaymentRequestEmail($reservation);
            } catch (\Throwable $e) {
                Log::error('Manual reservation created but the payment-request email failed to send.', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reservation->fresh();
    }

    /**
     * Send the payment request through the standard email system, stamping the send only
     * when the dispatcher actually sent it (a config-disabled event must not stamp).
     *
     * @throws ManualReservationException when the request would be unusable: no longer
     *                                    awaiting payment, hold deadline passed, recorded
     *                                    gateway removed, or an online gateway with no pay
     *                                    link (payment page unconfigured/unpublished).
     */
    public function sendPaymentRequestEmail(Reservation $reservation): bool
    {
        // Callers pre-check isAwaitingPayment() on a plain read, but a webhook/CP/sweep
        // transition can race the send. Re-read under the row lock (serializes with any
        // in-flight transitionTo()); the lock is released before the mail transport runs.
        $fresh = DB::transaction(fn () => Reservation::query()->lockForUpdate()->findOrFail($reservation->id));

        if (! $fresh->isAwaitingPayment()) {
            throw new ManualReservationException(
                __('This reservation is no longer awaiting payment, so a payment request cannot be sent.')
            );
        }

        $reservation->setRawAttributes($fresh->getAttributes(), true);

        // Even before the lapse sweep cancels the hold, the pay page already refuses a
        // past-deadline link — this email could only dead-end the customer.
        if ($reservation->holdDeadlinePassed()) {
            throw new ManualReservationException(
                __('The payment deadline for this reservation has passed, so a payment request cannot be sent.')
            );
        }

        // paymentGatewaySupportsManualConfirmation() maps an UNKNOWN gateway to false, which
        // would satisfy the pay-link branch below — validate resolvability first. A blank
        // recorded gateway falls back to the default and passes.
        try {
            $reservation->resolvePaymentGateway();
        } catch (UnknownPaymentGateway $e) {
            throw new ManualReservationException(
                __('The payment method recorded on this reservation is no longer configured, so a payment request cannot be sent.')
            );
        }

        if (! $reservation->paymentGatewaySupportsManualConfirmation()
            && $reservation->customerPaymentUrl() === null) {
            throw new ManualReservationException(
                __('The payment page entry is not configured or published, so an online payment request cannot be sent.')
            );
        }

        $sent = app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerPaymentRequest,
            new ReservationPaymentRequest($reservation),
        );

        if ($sent) {
            $reservation->update(['payment_request_email_sent_at' => now()]);
        }

        return $sent;
    }

    /**
     * @param array{
     *     item_id: string,
     *     date_start: string,
     *     date_end: string,
     *     quantity?: int,
     *     rate_id?: ?int,
     *     extras?: array<int, array{id: int, quantity: int}>,
     *     options?: array<int, array{id: int, value: int}>,
     *     total_override?: string|int|float|null,
     *     payment_mode?: string,
     *     custom_amount?: string|int|float|null,
     *     payment_gateway?: ?string,
     * } $input
     * @return array{
     *     availability: array{status: bool, available: int, overbook: bool},
     *     rate_id: int,
     *     pricing: array{base_price: PriceClass, original_base_price: ?string, extras_total: PriceClass, options_total: PriceClass, total: PriceClass, total_overridden: bool},
     *     payment: array{mode: string, amount: PriceClass, surcharge: PriceClass, amount_with_surcharge: PriceClass, gateways: array},
     *     extras: Collection,
     *     options: Collection,
     * }
     *
     * @throws AvailabilityException|ManualReservationException|OptionsException
     */
    public function quote(array $input, bool $requireCustomAmount = true): array
    {
        return $this->withoutCheckoutSession(
            fn (): array => $this->buildQuote($input, $requireCustomAmount)
        );
    }

    /**
     * The CP shares the session with the frontend: a session coupon (resrv_coupon) gates
     * coupon-only dynamic pricing, and resrv-search is the fallback customer payload for
     * custom-priced extras. Stash both keys and restore them so the admin's own checkout
     * in another tab is unaffected. Public for the CP quote endpoint's supplemental
     * listings; nesting is a no-op.
     */
    public function withoutCheckoutSession(callable $callback): mixed
    {
        $stashed = [];

        foreach (['resrv_coupon', 'resrv-search'] as $key) {
            if (session()->has($key)) {
                $stashed[$key] = session($key);
                session()->forget($key);
            }
        }

        try {
            return $callback();
        } finally {
            session($stashed);
        }
    }

    protected function buildQuote(array $input, bool $requireCustomAmount): array
    {
        $input['rate_id'] = $this->resolveRateId($input);

        $itemId = $input['item_id'];
        $data = $this->availabilityDataFrom($input);

        // Prune stale pending holds but never this session's own: quoting must not expire the
        // admin's in-progress checkout (same CP-safe pattern as AvailabilityCpController).
        ExpireReservations::dispatchSync(expireSessionHold: false);

        $result = (new Availability)->getAvailabilityForEntry($data, $itemId, expireReservations: false);
        $status = (bool) ($result['message']['status'] ?? false);

        $this->initiateAvailabilityUnsafe($data);
        $stockRow = app(AvailabilityRepository::class)->itemAvailableBetween(
            date_start: $this->date_start,
            date_end: $this->date_end,
            duration: $this->duration,
            quantity: 1,
            statamic_id: $itemId,
            rateId: $data['rate_id'],
        )->first();
        $available = $stockRow ? (int) $stockRow->available : 0;

        $pricing = (new Availability)->getPricing($data, $itemId);

        if ($pricing === false) {
            throw new AvailabilityException(__('No pricing was found for the selected dates.'));
        }

        $basePrice = Price::create($pricing['price']);

        $extras = $this->priceExtras($input, $data);
        $options = $this->priceOptions($input, $data);

        $extrasTotal = Price::create(0)->add(...$extras->pluck('total')->all());
        $optionsTotal = Price::create(0)->add(...$options->pluck('total')->all());

        $totalOverridden = array_key_exists('total_override', $input) && $input['total_override'] !== null && $input['total_override'] !== '';

        if ($totalOverridden) {
            $total = $this->parseAmount($input['total_override'], __('The overridden total is not a valid amount.'));
            $basePrice = Price::create($total->format())->subtract($extrasTotal, $optionsTotal);

            if (Price::create(0)->greaterThan($basePrice)) {
                throw new ManualReservationException(__('The overridden total cannot be lower than the extras and options total.'));
            }
        } else {
            $total = Price::create($basePrice->format())->add($extrasTotal, $optionsTotal);
        }

        $amount = $this->requestedAmount($input, $basePrice, $total, $requireCustomAmount);

        $gateway = $input['payment_gateway'] ?? null;
        $surcharge = ($gateway && ! $amount->isZero())
            ? $this->gatewayManager->calculateSurcharge($gateway, $amount)
            : Price::create(0);

        return [
            'availability' => [
                'status' => $status,
                'available' => $available,
                'overbook' => ! $status,
            ],
            'rate_id' => $data['rate_id'],
            'pricing' => [
                'base_price' => $basePrice,
                'original_base_price' => $pricing['original_price'],
                'extras_total' => $extrasTotal,
                'options_total' => $optionsTotal,
                'total' => $total,
                'total_overridden' => $totalOverridden,
            ],
            'payment' => [
                'mode' => $this->paymentMode($input)->value,
                'amount' => $amount,
                'surcharge' => $surcharge,
                'amount_with_surcharge' => Price::create($amount->format())->add($surcharge),
                'gateways' => $this->perGatewayAmounts($amount),
            ],
            'extras' => $extras,
            'options' => $options,
        ];
    }

    /**
     * The amount the customer will be asked to pay. `standard` mirrors the frontend checkout
     * (calculatePayment() on the base price, forced to the full total for `everything` or no
     * free cancellation); `full` charges the total; `custom` a validated amount.
     */
    protected function requestedAmount(array $input, PriceClass $basePrice, PriceClass $total, bool $requireCustomAmount = true): PriceClass
    {
        // A supplied custom amount always exceeds a zero total and must be rejected, while an
        // omitted amount stays a legitimate comped booking — so only the required check is relaxed.
        if ($total->isZero()) {
            if ($this->paymentMode($input) === ManualPaymentMode::Custom) {
                $this->customAmount($input, $total, requireAmount: false);
            }

            return Price::create(0);
        }

        return match ($this->paymentMode($input)) {
            ManualPaymentMode::Standard => $this->standardAmount($input, $basePrice, $total),
            ManualPaymentMode::Full => Price::create($total->format()),
            ManualPaymentMode::Custom => $this->customAmount($input, $total, $requireCustomAmount),
        };
    }

    protected function standardAmount(array $input, PriceClass $basePrice, PriceClass $total): PriceClass
    {
        if (config('resrv-config.payment') === 'everything'
            || ! $this->freeCancellationPossible($this->rateIdFrom($input), Carbon::parse($input['date_start']))) {
            return Price::create($total->format());
        }

        return $this->calculatePayment(Price::create($basePrice->format()));
    }

    /**
     * A validated custom amount. `$requireAmount` is relaxed for the live quote so an
     * as-yet-unentered amount yields zero instead of blanking the quote; creation keeps it required.
     */
    protected function customAmount(array $input, PriceClass $total, bool $requireAmount = true): PriceClass
    {
        $raw = $input['custom_amount'] ?? null;

        if ($raw === null || $raw === '') {
            if ($requireAmount) {
                throw new ManualReservationException(__('A custom amount is required for the custom payment mode.'));
            }

            return Price::create(0);
        }

        $amount = $this->parseAmount($raw, __('The custom amount is not a valid amount.'));

        if ($amount->isZero() || Price::create(0)->greaterThan($amount)) {
            throw new ManualReservationException(__('The custom amount must be greater than zero.'));
        }

        if ($amount->greaterThan($total)) {
            throw new ManualReservationException(__('The custom amount cannot exceed the reservation total.'));
        }

        return $amount;
    }

    /**
     * The HTTP layer's `numeric` rule accepts values moneyphp's parser does not (scientific
     * notation, excess decimals) — surface parse failures as a 422 domain error, not a 500.
     */
    protected function parseAmount(string|int|float $raw, string $message): PriceClass
    {
        try {
            return Price::create($raw);
        } catch (\Throwable) {
            throw new ManualReservationException($message);
        }
    }

    /**
     * Replicates Livewire\Traits\HandlesPricing::freeCancellationPossible() for a
     * pre-creation context (no reservation snapshot yet).
     */
    protected function freeCancellationPossible(?int $rateId, Carbon $dateStart): bool
    {
        $cancellation = Rate::effectiveCancellationPolicyFor($rateId);

        if ($cancellation['policy'] === CancellationPolicy::NonRefundable) {
            return false;
        }

        if (config('resrv-config.full_payment_after_free_cancellation') === false) {
            return true;
        }

        $freeCancellation = $cancellation['period'] ?? 0;
        $freeCancellationDays = (int) Carbon::create($dateStart->year, $dateStart->month, $dateStart->day, 0, 0, 0)
            ->diffInDays(now()->startOfDay(), true);

        return $freeCancellationDays > $freeCancellation;
    }

    /**
     * The concrete rate every quote and creation is priced, validated and stored against.
     * An explicit rate_id must be published and applicable (the pricing lookup underneath is
     * validity-blind); an omitted one adopts the entry's first published applicable rate,
     * matching the create form's default. Never null: itemPricesBetween() with a null rateId
     * sums price rows across EVERY rate, and the reservation's rate-scoped stock movements
     * would match no availability rows.
     *
     * @throws ManualReservationException
     */
    protected function resolveRateId(array $input): int
    {
        $itemId = (string) $input['item_id'];

        if (($rateId = $this->rateIdFrom($input)) !== null) {
            $rate = Rate::published()->find($rateId);

            if (! $rate || ! $rate->appliesToEntry($itemId)) {
                throw new ManualReservationException(__('The selected rate is not available for this entry.'));
            }

            return $rateId;
        }

        $rate = Rate::forEntry($itemId)->published()->orderBy('order')->first();

        if (! $rate) {
            throw new ManualReservationException(__('There is no published rate available for this entry.'));
        }

        return $rate->id;
    }

    /**
     * The overbook toggle (affects_availability=false) may bypass ONLY stock. Availability
     * status also reads false for structural reasons (disabled entry, unpublished/inapplicable
     * rate, date-window/stay/lead-time limits) and the pricing lookup is validity-blind, so
     * re-check those here. Shared-rate capacity is deliberately NOT re-checked — like stock,
     * it is exactly what the toggle exists to bypass.
     *
     * @throws AvailabilityException|ManualReservationException
     */
    protected function assertOverbookOnlyBypassesStock(array $input): void
    {
        $entry = Entry::query()->itemId((string) $input['item_id'])->first();

        if ($entry === null || $entry->isDisabled()) {
            throw new AvailabilityException(__('This entry is not enabled for reservations.'));
        }

        $rateId = $this->rateIdFrom($input);
        $rate = $rateId !== null ? Rate::published()->find($rateId) : null;

        if (! $rate || ! $rate->appliesToEntry((string) $input['item_id'])) {
            throw new ManualReservationException(__('The selected rate is not available for this entry.'));
        }

        $this->initiateAvailabilityUnsafe($this->availabilityDataFrom($input));

        if (! $rate->isAvailableForDates($this->date_start, $this->date_end)
            || ! $rate->meetsStayRestrictions($this->duration)
            || ! $rate->meetsBookingLeadTime($this->date_start)) {
            throw new ManualReservationException(__('The selected rate does not allow these dates.'));
        }
    }

    /**
     * @throws ManualReservationException when the gateway cannot be used for this booking
     */
    protected function assertGatewayIsUsable(string $gateway, PriceClass $amount): void
    {
        $instance = $this->gatewayManager->gateway($gateway);

        // A non-manually-confirmable gateway confirms only via webhook (return legs are
        // display-only by contract — UPGRADE-PAYMENT-GATEWAYS.md Steps 3-4). With neither
        // capability it could collect money nothing can ever confirm, and the hold-lapse
        // sweep would cancel the booking out from under the payment.
        if (! $amount->isZero()
            && ! $instance->supportsManualConfirmation()
            && ! $instance->supportsWebhooks()) {
            throw new ManualReservationException(
                __('This payment method cannot confirm online payments, so it cannot be used to collect a payment.')
            );
        }

        // Without a configured payment page an online gateway has no link to pay through —
        // only manually-confirmable gateways work. A zero amount collects through no gateway
        // at all and must not be rejected for lacking one.
        if (! $amount->isZero()
            && Reservation::resolveCustomerPageEntry(config('resrv-config.manual_reservations_payment_entry')) === null
            && ! $instance->supportsManualConfirmation()) {
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

    /** @return Collection<int, array{quantity: int, price: string, total: PriceClass}> keyed by extra id */
    protected function priceExtras(array $input, array $data): Collection
    {
        $calcData = array_merge($data, ['item_id' => $input['item_id']]);

        // Carry the customer payload so a custom-priced extra (Extra::getCustomPrice) reads
        // its multiplier from the same checkout-form field the frontend does; the resrv-search
        // session fallback is stashed by withoutCheckoutSession(), so this is the only source.
        if (! empty($input['customer'])) {
            $calcData['customer'] = collect($input['customer']);
        }

        return collect($input['extras'] ?? [])->mapWithKeys(function ($item) use ($calcData) {
            $id = (int) ($item['id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            // Only extras published and attached to this entry — mirrors the frontend scoping;
            // Extra::find() alone would let a stale/crafted payload attach a foreign extra.
            $extra = Extra::whereHas('entries', fn ($query) => $query->where('resrv_entries.item_id', $calcData['item_id']))
                ->where('published', true)
                ->find($id);

            if (! $extra) {
                throw new ManualReservationException(__('The selected extra does not belong to this entry.'));
            }

            if ($quantity < 1) {
                throw new ManualReservationException(__('The extra quantity must be at least 1.'));
            }

            // Non-multiple extras carry no maximum, so the cap below alone would accept any
            // quantity — cap them at one unit like the create form does.
            if (! $extra->allow_multiple && $quantity > 1) {
                throw new ManualReservationException(__('The extra ":name" cannot be added more than once.', ['name' => $extra->name]));
            }

            if ($extra->maximum > 0 && $quantity > $extra->maximum) {
                throw new ManualReservationException(__('The selected quantity exceeds the maximum allowed for this extra.'));
            }

            try {
                // Fresh clones: calculatePrice/priceForDates write back into the model's
                // price attribute, so a shared instance would compound the price.
                $price = (clone $extra)->priceForDates($calcData);
                $total = (clone $extra)->calculatePrice($calcData, $quantity);
            } catch (ManualReservationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // A custom-priced extra throws when its driving customer field is absent or
                // non-numeric — surface as a 422 domain error, not a 500.
                throw new ManualReservationException(__('The extra ":name" could not be priced — check its custom-price field on the customer form.', ['name' => $extra->name]));
            }

            return [$id => [
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
            ]];
        });
    }

    /** @return Collection<int, array{value: int, total: PriceClass}> keyed by option id */
    protected function priceOptions(array $input, array $data): Collection
    {
        $calcData = array_merge($data, ['item_id' => $input['item_id']]);

        return collect($input['options'] ?? [])->mapWithKeys(function ($item) use ($calcData, $input) {
            $id = (int) ($item['id'] ?? 0);
            $value = (int) ($item['value'] ?? 0);

            // Only published options belonging to this entry — a plain Option::find() would
            // accept an option unpublished after the form loaded.
            $option = Option::entry($input['item_id'])->where('published', true)->find($id);

            if (! $option) {
                throw new ManualReservationException(__('The selected option does not belong to this entry.'));
            }

            // calculatePrice() resolves values withTrashed() for historical reservations —
            // a NEW reservation must only accept published, non-trashed values still on offer.
            if (! $option->values()->where('published', true)->whereKey($value)->exists()) {
                throw new ManualReservationException(__('The selected option value is no longer available.'));
            }

            return [$id => [
                'value' => $value,
                'total' => $option->calculatePrice($calcData, $value),
            ]];
        });
    }

    /**
     * Same required-extras/options semantics the checkout applies, via the underlying checks
     * (validateReservation() would also re-validate the total, which an overridden total
     * legitimately fails).
     */
    protected function assertRequiredExtrasAndOptions(array $input): void
    {
        $data = array_merge($this->availabilityDataFrom($input), [
            'extras' => collect($input['extras'] ?? []),
            'options' => collect($input['options'] ?? []),
        ]);

        $required = (new ExtraCondition)->hasRequiredExtrasSelected($input['item_id'], $data);

        if ($required !== true) {
            throw new ExtrasException($required->map(
                fn ($messages, $extraId) => 'ID '.$extraId.' '.$messages->implode(' ')
            )->implode(', '));
        }

        $requiredOptionIds = Option::entry($input['item_id'])
            ->where('published', true)
            ->where('required', true)
            ->pluck('id');

        $selected = collect($input['options'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);

        if (! $requiredOptionIds->every(fn ($id) => $selected->contains((int) $id))) {
            throw new OptionsException(__('There are required options you did not select.'));
        }
    }

    protected function createCustomer(array $input): ?Customer
    {
        $customerData = $input['customer'] ?? [];

        if (empty($customerData)) {
            return null;
        }

        // Persist only known form handles — mirrors CheckoutForm::saveCustomer()'s allow-list.
        $allowed = app(CheckoutFormResolver::class)
            ->resolveForEntryId($input['item_id'])
            ->fields()
            ->values()
            ->map(fn ($field) => $field->handle());

        return Customer::create([
            'email' => $customerData['email'] ?? '',
            'data' => collect($customerData)->only($allowed->all())->except('email'),
        ]);
    }

    protected function resolveAffiliate($affiliateId): ?Affiliate
    {
        if (! $affiliateId) {
            return null;
        }

        // Mirror the create page's visibility rules (feature flag + published): a stale or
        // crafted payload must not attach a commission for what the form never offers.
        if (! config('resrv-config.enable_affiliates')) {
            throw new ManualReservationException(__('Affiliates are disabled.'));
        }

        $affiliate = Affiliate::published()->find($affiliateId);

        if (! $affiliate) {
            throw new ManualReservationException(__('The selected affiliate was not found.'));
        }

        return $affiliate;
    }

    /** @return array<string, array{label: ?string, surcharge: PriceClass, amount_with_surcharge: PriceClass}> */
    protected function perGatewayAmounts(PriceClass $amount): array
    {
        return collect(array_keys($this->gatewayManager->all()))->mapWithKeys(function ($name) use ($amount) {
            $surcharge = $amount->isZero()
                ? Price::create(0)
                : $this->gatewayManager->calculateSurcharge($name, Price::create($amount->format()));

            return [$name => [
                'label' => $this->gatewayManager->label($name),
                'surcharge' => $surcharge,
                'amount_with_surcharge' => Price::create($amount->format())->add($surcharge),
            ]];
        })->all();
    }

    protected function paymentMode(array $input): ManualPaymentMode
    {
        return ManualPaymentMode::tryFrom($input['payment_mode'] ?? ManualPaymentMode::Standard->value)
            ?? throw new ManualReservationException(__('Unknown payment mode.'));
    }

    protected function rateIdFrom(array $input): ?int
    {
        $rateId = $input['rate_id'] ?? null;

        return is_numeric($rateId) ? (int) $rateId : null;
    }

    /** @return array{date_start: string, date_end: string, quantity: int, rate_id: ?int} */
    protected function availabilityDataFrom(array $input): array
    {
        return [
            'date_start' => $input['date_start'],
            'date_end' => $input['date_end'],
            'quantity' => (int) ($input['quantity'] ?? 1),
            'rate_id' => $this->rateIdFrom($input),
        ];
    }
}
