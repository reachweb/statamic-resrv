<?php

namespace Reach\StatamicResrv\Support;

use Carbon\Carbon;
use Closure;
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
use Reach\StatamicResrv\Models\ExtraCategory;
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
     *                        affects_availability?, affiliate_id?, hold_days?,
     *                        send_payment_request_email?, expected_total?, expected_amount?.
     *                        payment_gateway and hold_days are ignored when nothing is
     *                        collected now (the booking confirms immediately).
     * @param  mixed  $creator  the Statamic user creating the reservation
     * @param  ?Closure(Reservation): void  $onCommitted  runs right after the creation transaction
     *                                                    commits, before any confirmation or email; a
     *                                                    throw is reported, never aborts the creation
     *
     * @throws AvailabilityException|ManualReservationException|OptionsException|ExtrasException
     */
    public function create(array $input, $creator = null, bool $requireGatewayForPayment = false, ?Closure $onCommitted = null): Reservation
    {
        return $this->withoutCheckoutSession(
            fn (): Reservation => $this->createReservation($input, $creator, $requireGatewayForPayment, $onCommitted)
        );
    }

    protected function createReservation(array $input, $creator, bool $requireGatewayForPayment, ?Closure $onCommitted = null): Reservation
    {
        // Normalize once so the quote, overbook assert, stored rate_id and cancellation
        // snapshot all see the SAME validated rate (see resolveRateId()).
        $input['rate_id'] = $this->resolveRateId($input);

        $quote = $this->quote($input);

        $this->assertQuoteMatchesWhatWasShown($input, [
            'expected_total' => $quote['pricing']['total'],
            'expected_amount' => $quote['payment']['amount'],
        ]);

        // A zero-amount booking collects through no gateway and confirms immediately, so — like
        // Checkout::handleReservationWithZeroPayment — it records neither a gateway nor a hold.
        $collectsPayment = ! $quote['payment']['amount']->isZero();
        $gateway = $collectsPayment ? (string) ($input['payment_gateway'] ?? '') : '';

        if ($requireGatewayForPayment && $gateway === '' && $collectsPayment) {
            throw new ManualReservationException(__('A payment method is required to collect a payment.'), field: 'payment_gateway');
        }

        // A blank gateway on a paid booking would resolve to the DEFAULT at pay time
        // (resolvePaymentGateway's legacy-row rule) while bypassing its limits, page gate and
        // surcharge — pin the default now so the row is validated and priced against the
        // gateway that will actually collect.
        if ($gateway === '' && $collectsPayment) {
            $gateway = (string) $this->gatewayManager->defaultName();

            if ($gateway === '') {
                throw new ManualReservationException(__('No payment gateway is configured to collect a payment.'), field: 'payment_gateway');
            }

            $quote['payment']['surcharge'] = $this->gatewayManager->calculateSurcharge($gateway, $quote['payment']['amount']);
            $quote['payment']['amount_with_surcharge'] = Price::create($quote['payment']['amount']->format())->add($quote['payment']['surcharge']);
        }

        // Checked once the collecting gateway is final: the surcharge is part of what the
        // customer is asked to pay, so a gateway fee that moved since the quote re-quotes too.
        $this->assertQuoteMatchesWhatWasShown($input, [
            'expected_surcharge' => $quote['payment']['surcharge'],
        ]);

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
        $holdDays = $collectsPayment ? (int) ($input['hold_days'] ?? 0) : 0;

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
                        $id => [
                            'quantity' => $extra['quantity'],
                            'price' => $extra['price'],
                            'total' => $extra['total']->format(),
                        ],
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

        if ($onCommitted !== null) {
            try {
                $onCommitted($reservation);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Mirrors Checkout::handleReservationWithZeroPayment(): keyed off the payment amount,
        // not the grand total. Nothing collected now → confirm immediately; never a payment
        // request for 0.00 or an awaiting-payment hold the lapse sweep would later cancel.
        if (! $collectsPayment) {
            if ($reservation->transitionTo(ReservationStatus::CONFIRMED)) {
                // Everything is committed; a throwing synchronous listener must not turn the
                // creation into a 500 the admin would retry. The confirmation email can be
                // resent from the reservation page. The CP's email switch governs the customer
                // confirmation here (there is no payment request); the admin email always goes.
                try {
                    ReservationConfirmed::dispatch(
                        $reservation,
                        ReservationConfirmed::VIA_CP,
                        [],
                        (bool) ($input['send_payment_request_email'] ?? true),
                    );
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
     *                                    awaiting payment, hold deadline or arrival day
     *                                    passed, recorded gateway removed, or an online
     *                                    gateway with no pay link (payment page
     *                                    unconfigured/unpublished).
     */
    public function sendPaymentRequestEmail(Reservation $reservation, bool $deadlineChanged = false): bool
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

        // paymentWindowClosed()'s other half: the pay page refuses once the arrival day is over.
        if ($reservation->arrivalDayPassed()) {
            throw new ManualReservationException(
                __('The arrival date for this reservation has passed, so a payment request cannot be sent.')
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
            new ReservationPaymentRequest($reservation, deadlineChanged: $deadlineChanged),
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
     *     pricing: array{base_price: PriceClass, original_base_price: ?string, computed_base_price: PriceClass, computed_total: PriceClass, adjustment: PriceClass, discount: ?PriceClass, extras_total: PriceClass, options_total: PriceClass, total: PriceClass, balance: PriceClass, total_overridden: bool},
     *     payment: array{mode: string, amount: PriceClass, standard_amount: PriceClass, full_amount: PriceClass, surcharge: PriceClass, amount_with_surcharge: PriceClass, gateways: array, custom_amount_error: ?string},
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

        $this->assertStayLengthAllowed($data);

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

        // An overbook bypasses only stock. The availability check it skips is also what guarantees
        // a priced row for every night, and the pricing lookup sums whatever rows exist — so a
        // partly unpriced stay would silently be undercharged.
        if (! $status && ! $this->everyNightIsPriced($itemId, $data['rate_id'])) {
            throw new ManualReservationException(__('These dates have no price configured for every night of the stay.'), field: 'dates');
        }

        $computedBasePrice = Price::create($pricing['price']);
        $basePrice = Price::create($computedBasePrice->format());

        $extras = $this->priceExtras($input, $data);
        $options = $this->priceOptions($input, $data);

        $extrasTotal = Price::create(0)->add(...$extras->pluck('total')->all());
        $optionsTotal = Price::create(0)->add(...$options->pluck('total')->all());
        $computedTotal = Price::create($computedBasePrice->format())->add($extrasTotal, $optionsTotal);

        $totalOverridden = array_key_exists('total_override', $input) && $input['total_override'] !== null && $input['total_override'] !== '';

        if ($totalOverridden) {
            $total = $this->parseAmount($input['total_override'], __('The overridden total is not a valid amount.'), 'total_override');
            $basePrice = Price::create($total->format())->subtract($extrasTotal, $optionsTotal);

            if (Price::create(0)->greaterThan($basePrice)) {
                throw new ManualReservationException(__('The overridden total cannot be lower than the extras and options total.'), field: 'total_override');
            }
        } else {
            $total = Price::create($computedTotal->format());
        }

        // The live quote keeps pricing through a rejected custom amount (e.g. a total lowered
        // under it) and reports the reason, so the form refreshes the total the amount is judged
        // against and shows the message under the field. Creation still throws.
        $customAmountError = null;

        try {
            $amount = $this->requestedAmount($input, $basePrice, $total, $requireCustomAmount);
        } catch (ManualReservationException $e) {
            if ($requireCustomAmount || $this->paymentMode($input) !== ManualPaymentMode::Custom) {
                throw $e;
            }

            $amount = Price::create(0);
            $customAmountError = $e->getMessage();
        }

        $gateway = $input['payment_gateway'] ?? null;
        $surcharge = ($gateway && ! $amount->isZero())
            ? $this->gatewayManager->calculateSurcharge($gateway, $amount)
            : Price::create(0);

        // Both choices are always priced so the form can show what each mode would request.
        $standardAmount = $total->isZero()
            ? Price::create(0)
            : $this->standardAmount($input, $basePrice, $total);

        $discount = $pricing['original_price'] !== null
            ? Price::create($pricing['original_price'])->subtract($computedBasePrice)
            : null;

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
                // What base/total would be without an override; adjustment = total − computed_total.
                'computed_base_price' => $computedBasePrice,
                'computed_total' => $computedTotal,
                'adjustment' => Price::create($total->format())->subtract($computedTotal),
                'discount' => $discount,
                'extras_total' => $extrasTotal,
                'options_total' => $optionsTotal,
                'total' => $total,
                // What Resrv will not collect now.
                'balance' => Price::create($total->format())->subtract($amount),
                'total_overridden' => $totalOverridden,
            ],
            'payment' => [
                'mode' => $this->paymentMode($input)->value,
                'amount' => $amount,
                'standard_amount' => $standardAmount,
                'full_amount' => Price::create($total->format()),
                'surcharge' => $surcharge,
                'amount_with_surcharge' => Price::create($amount->format())->add($surcharge),
                'gateways' => $this->perGatewayAmounts($amount),
                'custom_amount_error' => $customAmountError,
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

    /**
     * Capped at the total: a fixed deposit can exceed a total lowered by an override, and the
     * request must never ask for more than the booking costs (custom amounts are bounded the
     * same way). The frontend checkout keeps its unclamped calculatePayment().
     */
    protected function standardAmount(array $input, PriceClass $basePrice, PriceClass $total): PriceClass
    {
        if (config('resrv-config.payment') === 'everything'
            || ! $this->freeCancellationPossible($this->rateIdFrom($input), Carbon::parse($input['date_start']))) {
            return Price::create($total->format());
        }

        $amount = $this->calculatePayment(Price::create($basePrice->format()));

        return $amount->greaterThan($total) ? Price::create($total->format()) : $amount;
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
                throw new ManualReservationException(__('A custom amount is required for the custom payment mode.'), field: 'custom_amount');
            }

            return Price::create(0);
        }

        $amount = $this->parseAmount($raw, __('The custom amount is not a valid amount.'), 'custom_amount');

        if ($amount->isZero() || Price::create(0)->greaterThan($amount)) {
            throw new ManualReservationException(__('The custom amount must be greater than zero.'), field: 'custom_amount');
        }

        if ($amount->greaterThan($total)) {
            throw new ManualReservationException(__('The custom amount cannot exceed the reservation total.'), field: 'custom_amount');
        }

        return $amount;
    }

    /**
     * The HTTP layer's `numeric` rule accepts values moneyphp's parser does not (scientific
     * notation, excess decimals) — surface parse failures as a 422 domain error, not a 500.
     */
    protected function parseAmount(string|int|float $raw, string $message, ?string $field = null): PriceClass
    {
        try {
            return Price::create($raw);
        } catch (\Throwable) {
            throw new ManualReservationException($message, field: $field);
        }
    }

    /**
     * The store request may carry the total, amount and surcharge the admin was shown. Anything
     * that moved since (another admin's price edit, a dynamic pricing expiring, the
     * free-cancellation window closing at midnight, a changed gateway fee) must re-quote instead
     * of persisting and emailing different figures.
     *
     * @param  array<string, PriceClass>  $checks  input key => the freshly computed figure
     *
     * @throws ManualReservationException
     */
    protected function assertQuoteMatchesWhatWasShown(array $input, array $checks): void
    {
        foreach ($checks as $key => $actual) {
            $expected = $input[$key] ?? null;

            if ($expected === null || $expected === '') {
                continue;
            }

            try {
                $matches = Price::create($expected)->equals($actual);
            } catch (\Throwable) {
                $matches = false;
            }

            if (! $matches) {
                throw new ManualReservationException(
                    __('The price changed since it was last shown — review the updated figures and create again.'),
                    reason: 'price_changed',
                );
            }
        }
    }

    /**
     * The availability engine enforces the same limits (checkDurationValidity()), but with
     * customer-facing wording; measured the same way so both agree on the night count.
     *
     * @throws ManualReservationException
     */
    protected function assertStayLengthAllowed(array $data): void
    {
        $this->initiateAvailabilityUnsafe($data);

        $maximum = config('resrv-config.maximum_reservation_period_in_days');
        $minimum = config('resrv-config.minimum_reservation_period_in_days');

        if (is_numeric($maximum) && $this->duration > (int) $maximum) {
            throw new ManualReservationException(__('The stay is longer than the maximum of :days nights.', ['days' => (int) $maximum]), field: 'dates');
        }

        if (is_numeric($minimum) && $this->duration < (int) $minimum) {
            throw new ManualReservationException(__('The stay is shorter than the minimum of :days nights.', ['days' => (int) $minimum]), field: 'dates');
        }
    }

    /** Whether every night of the initiated stay has a priced row for the rate (its base pool for shared rates). */
    protected function everyNightIsPriced(string $itemId, int $rateId): bool
    {
        return app(AvailabilityRepository::class)
            ->itemPricedNightCount($this->date_start, $this->date_end, $itemId, $rateId) >= $this->duration;
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
                throw new ManualReservationException(__('The selected rate is not available for this entry.'), field: 'rate_id');
            }

            return $rateId;
        }

        $rate = Rate::forEntry($itemId)->published()->orderBy('order')->first();

        if (! $rate) {
            throw new ManualReservationException(__('There is no published rate available for this entry.'), field: 'rate_id');
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
            throw new ManualReservationException(__('This entry is not enabled for reservations.'));
        }

        $rateId = $this->rateIdFrom($input);
        $rate = $rateId !== null ? Rate::published()->find($rateId) : null;

        if (! $rate || ! $rate->appliesToEntry((string) $input['item_id'])) {
            throw new ManualReservationException(__('The selected rate is not available for this entry.'), field: 'rate_id');
        }

        $this->initiateAvailabilityUnsafe($this->availabilityDataFrom($input));

        if (! $rate->isAvailableForDates($this->date_start, $this->date_end)
            || ! $rate->meetsStayRestrictions($this->duration)
            || ! $rate->meetsBookingLeadTime($this->date_start)) {
            throw new ManualReservationException(__('The selected rate does not allow these dates.'), field: 'dates');
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
                __('This payment method cannot confirm online payments, so it cannot be used to collect a payment.'),
                field: 'payment_gateway',
            );
        }

        // Without a configured payment page an online gateway has no link to pay through —
        // only manually-confirmable gateways work. A zero amount collects through no gateway
        // at all and must not be rejected for lacking one.
        if (! $amount->isZero()
            && Reservation::resolveCustomerPageEntry(config('resrv-config.manual_reservations_payment_entry')) === null
            && ! $instance->supportsManualConfirmation()) {
            throw new ManualReservationException(
                __('The payment page entry is not configured, so only payment methods that support manual confirmation can be used.'),
                field: 'payment_gateway',
            );
        }

        if (! $amount->isZero() && ! $this->gatewayManager->isAvailableFor($gateway, $amount)) {
            throw new ManualReservationException(
                __('The requested amount is outside the allowed limits for this payment method.'),
                field: 'payment_gateway',
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

            // Only selectable extras attached to this entry — the same predicate as the quote's
            // extras list and the frontend checkout (Extra::scopeSelectable(): published and not
            // in an unpublished category). Extra::find() alone would let a stale/crafted payload
            // attach a foreign or hidden extra.
            $extra = Extra::whereHas('entries', fn ($query) => $query->where('resrv_entries.item_id', $calcData['item_id']))
                ->selectable()
                ->find($id);

            if (! $extra) {
                throw new ManualReservationException(__('The selected extra does not belong to this entry.'), field: 'extras');
            }

            if ($quantity < 1) {
                throw new ManualReservationException(__('The extra quantity must be at least 1.'), field: 'extras');
            }

            // Non-multiple extras carry no maximum, so the cap below alone would accept any
            // quantity — cap them at one unit like the create form does.
            if (! $extra->allow_multiple && $quantity > 1) {
                throw new ManualReservationException(__('The extra ":name" cannot be added more than once.', ['name' => $extra->name]), field: 'extras');
            }

            if ($extra->maximum > 0 && $quantity > $extra->maximum) {
                throw new ManualReservationException(__('The selected quantity exceeds the maximum allowed for this extra.'), field: 'extras');
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
                throw new ManualReservationException(__('The extra ":name" could not be priced — check its custom-price field on the customer form.', ['name' => $extra->name]), field: 'extras');
            }

            // Both are snapshotted on the pivot: the unit price the form listed, and the line
            // actually charged. They are not linear in each other once a dynamic-pricing
            // minimum/maximum binds — priceForDates() clamps the unit, calculatePrice() the line
            // — so the CP renders the line from `total` (Extra::priceFromPivot()), never from
            // price × quantity, which would show 2 × 100 for a 100 charge.
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
                throw new ManualReservationException(__('The selected option does not belong to this entry.'), field: 'options');
            }

            // calculatePrice() resolves values withTrashed() for historical reservations —
            // a NEW reservation must only accept published, non-trashed values still on offer.
            if (! $option->values()->where('published', true)->whereKey($value)->exists()) {
                throw new ManualReservationException(__('The selected option value is no longer available.'), field: 'options');
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
        $missing = (new ExtraCondition)->hasRequiredExtrasSelected($input['item_id'], $this->conditionDataFrom($input));

        if ($missing !== true) {
            throw new ExtrasException(__('Add the required extras: :extras.', [
                'extras' => $this->extraNames($missing->keys())->implode(', '),
            ]));
        }

        $selected = collect($input['options'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);

        $missingOptions = Option::entry($input['item_id'])
            ->where('published', true)
            ->where('required', true)
            ->get(['id', 'name'])
            ->reject(fn ($option) => $selected->contains((int) $option->id));

        if ($missingOptions->isNotEmpty()) {
            throw new OptionsException(__('Select a value for: :options.', [
                'options' => $missingOptions->pluck('name')->implode(', '),
            ]));
        }
    }

    /**
     * Extras the entry's conditions require for this stay and selection (the same rules the
     * frontend extras step and creation enforce), each with a CP explanation of why.
     *
     * @return Collection<int, ?string> reason keyed by extra id
     */
    public function requiredExtras(array $input): Collection
    {
        return (new ExtraCondition)
            ->requiredConditionsThatApply($input['item_id'], $this->conditionDataFrom($input))
            ->map(fn (Collection $conditions) => $conditions
                ->map(fn ($condition) => $this->requiredReason($condition))
                ->filter()
                ->unique()
                ->implode(' ') ?: null);
    }

    protected function requiredReason(object $condition): ?string
    {
        $value = $condition->value ?? null;

        return match ($condition->type ?? null) {
            'always' => __('Always required for this entry.'),
            'pickup_time' => __('Required for this start time.'),
            'dropoff_time' => __('Required for this end time.'),
            'reservation_dates' => __('Required for these dates.'),
            'reservation_duration' => __('Required for this length of stay.'),
            'extra_selected' => __('Required because :extra is selected.', ['extra' => $this->extraNames(collect([$value]))->first()]),
            'extra_not_selected' => __('Required unless :extra is selected.', ['extra' => $this->extraNames(collect([$value]))->first()]),
            'extra_in_category_selected' => __('Required because an extra from :category is selected.', ['category' => $this->categoryName($value)]),
            'no_extra_in_category_selected' => __('Required unless an extra from :category is selected.', ['category' => $this->categoryName($value)]),
            default => null,
        };
    }

    /** @return Collection<int, string> names in the given id order, falling back to "#id" */
    protected function extraNames(Collection $ids): Collection
    {
        $names = Extra::withTrashed()->whereIn('id', $ids->filter()->all())->pluck('name', 'id');

        return $ids->map(fn ($id) => $names->get($id) ?? '#'.$id)->values();
    }

    protected function categoryName($id): string
    {
        return ExtraCategory::find($id)?->name ?? '#'.$id;
    }

    /** The data shape ExtraCondition evaluates: the stay plus the current extras/options selection. */
    protected function conditionDataFrom(array $input): array
    {
        return array_merge($this->availabilityDataFrom($input), [
            'extras' => collect($input['extras'] ?? []),
            'options' => collect($input['options'] ?? []),
        ]);
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
                // Mirrors assertGatewayIsUsable()'s amount-limit check so the form can disable
                // gateways the store request would reject.
                'available' => $amount->isZero() || $this->gatewayManager->isAvailableFor($name, $amount),
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
