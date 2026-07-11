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
 * computed server-side through the same code paths the frontend checkout uses — never
 * trusted from the client: base price via Availability::getPricing() (dynamic pricing
 * baked in), extras/options via the same calculatePrice() calls validateExtraCharges()
 * relies on, the requested amount via calculatePayment() (checkout parity).
 */
class ManualReservationCreator
{
    use HandlesAvailabilityDates, HandlesPricing;

    public function __construct(protected PaymentGatewayManager $gatewayManager) {}

    /**
     * Creates the reservation from validated input (shape validation lives in the HTTP
     * layer; this method re-computes every money figure and asserts the domain
     * invariants). Returns the fresh reservation — email side effects live elsewhere.
     *
     * @param  array  $input  quote() input plus: customer (form data incl. email),
     *                        affects_availability?, affiliate_id?, hold_days?
     * @param  mixed  $creator  the Statamic user creating the reservation
     *
     * @throws AvailabilityException|ManualReservationException|OptionsException|ExtrasException
     */
    public function create(array $input, $creator = null, bool $requireGatewayForPayment = false): Reservation
    {
        $quote = $this->quote($input);

        $gateway = (string) ($input['payment_gateway'] ?? '');

        // The CP flow ($requireGatewayForPayment) requires a gateway whenever money is collected
        // now — but a zero-amount booking (fully comped / zero deposit) confirms immediately with
        // nothing to pay through, so it may omit one. Direct/programmatic callers may always omit
        // a gateway (there may be nothing to pay through), which leaves nothing to assert.
        if ($requireGatewayForPayment && $gateway === '' && ! $quote['payment']['amount']->isZero()) {
            throw new ManualReservationException(__('A payment method is required to collect a payment.'));
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

        $reservation = DB::transaction(function () use ($input, $quote, $cancellation, $affectsAvailability, $affiliate, $holdDays, $creator) {
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
                'payment_gateway' => (string) ($input['payment_gateway'] ?? ''),
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

            // Inside the transaction, same as checkout: a DecreaseAvailability throw (stock
            // ran out in the TOCTOU window) rolls back the reservation row.
            ReservationCreated::dispatch($reservation, new ReservationData(
                affiliate: $affiliate,
                viaCp: true,
                skipDynamicPricings: $quote['pricing']['total_overridden'],
            ));

            return $reservation;
        });

        // Mirrors Checkout::handleReservationWithZeroPayment(), which keys off the payment amount
        // (reservationPaymentIsZero) — not the grand total. When nothing is collected now (a
        // deposit can round/compute to zero while paid extras keep the total positive) the
        // booking is firm immediately and the confirmation email chain fires — never a payment
        // request for 0.00, and never an awaiting-payment hold the lapse sweep would later cancel.
        if ($quote['payment']['amount']->isZero()) {
            if ($reservation->transitionTo(ReservationStatus::CONFIRMED)) {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_CP);
            }

            return $reservation->fresh();
        }

        // The reservation, customer and stock decrement have already committed. A failing mail
        // transport must not turn a successful creation into a 500 the admin would retry —
        // retrying would create a second reservation and decrement stock again. Log and carry
        // on; the payment request can be resent from the reservation page.
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
     * Send the payment request through the standard email system and stamp the send —
     * only when the dispatcher actually sent it (a site that disabled the event via
     * config must not get a false stamp).
     *
     * @throws ManualReservationException when the request would be unusable: the reservation
     *                                    is no longer awaiting payment (a webhook or another
     *                                    admin confirmed/cancelled it after the caller's
     *                                    pre-check), the hold's payment deadline has already
     *                                    passed (the pay page refuses expired links), the
     *                                    recorded gateway was removed from the configuration
     *                                    (the pay link could never mount a payment), or an
     *                                    online (non-manually-confirmable) gateway has no pay
     *                                    link to offer because the payment page entry was
     *                                    unconfigured/unpublished after creation. The email
     *                                    would otherwise fall back to its offline "send us your
     *                                    payment" wording with no way to actually pay.
     */
    public function sendPaymentRequestEmail(Reservation $reservation): bool
    {
        // Callers pre-check isAwaitingPayment() on a plain read, but a webhook confirm, a CP
        // confirm/cancel or the lapse sweep can transition the row before the send — and a
        // "Pay now" email for a booking that is already paid or cancelled can only mislead the
        // customer. Re-read under the row lock (serializing with any in-flight transitionTo())
        // and judge the settled row; the lock is released before the mail transport runs, so
        // only a transition starting entirely after this read can still race the send.
        $fresh = DB::transaction(fn () => Reservation::query()->lockForUpdate()->findOrFail($reservation->id));

        if (! $fresh->isAwaitingPayment()) {
            throw new ManualReservationException(
                __('This reservation is no longer awaiting payment, so a payment request cannot be sent.')
            );
        }

        $reservation->setRawAttributes($fresh->getAttributes(), true);

        // The lapse sweep may not have cancelled an expired hold yet, but the pay page already
        // refuses a past-deadline link (ReservationPayment's deadline_passed state) — a "Pay now"
        // email carrying a deadline in the past could only ever dead-end the customer.
        if ($reservation->holdDeadlinePassed()) {
            throw new ManualReservationException(
                __('The payment deadline for this reservation has passed, so a payment request cannot be sent.')
            );
        }

        // paymentGatewaySupportsManualConfirmation() maps an UNKNOWN gateway to false, which
        // would satisfy the pay-link branch below and email a "Pay now" link that can only
        // error when the page fails to resolve the gateway — so validate resolvability first.
        // A blank recorded gateway falls back to the default (resolvePaymentGateway's legacy
        // rule) and passes.
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
        $itemId = $input['item_id'];
        $data = $this->availabilityDataFrom($input);

        // Prune stale pending holds so they don't skew the stock read — but never this
        // session's own hold, which getAvailabilityForEntry's frontend default would abandon:
        // quoting a manual reservation must not expire the admin's unrelated in-progress
        // checkout in another tab (same CP-safe pattern as AvailabilityCpController).
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
     * The amount the customer will be asked to pay. `standard` mirrors what the frontend
     * checkout charges: calculatePayment() on the base price, forced to the full total
     * when the payment type is `everything` or no free cancellation is possible (the
     * Checkout step-1 override). `full` charges the total; `custom` a validated amount.
     */
    protected function requestedAmount(array $input, PriceClass $basePrice, PriceClass $total, bool $requireCustomAmount = true): PriceClass
    {
        // The zero-total shortcut must not skip custom-mode validation: a supplied custom
        // amount alongside a zero total is contradictory (it always exceeds the total) and
        // has to be rejected — silently storing a zero payment would confirm a free booking
        // the request explicitly asked to collect money for. An omitted amount stays a
        // legitimate fully-comped booking, so the required check is relaxed exactly here.
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
     * as-yet-unentered amount yields zero instead of throwing (which would blank the
     * quote and hide the very input the user needs); creation keeps it required.
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
     * Parse an admin-supplied money figure into a Price. The HTTP layer's `numeric` rule
     * accepts values moneyphp's decimal parser does not (scientific notation like "1e3",
     * or more decimals than the currency's subunit), so a parse failure must surface as a
     * domain error (422), not a 500 — same posture as priceExtras().
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
     * pre-creation context (no reservation snapshot yet): rate policy (or global
     * default) + the free-cancellation window measured against the check-in date.
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
     * The overbook toggle (affects_availability=false) may bypass ONLY stock. The quote's
     * availability status also reads false for structural reasons — a disabled entry, an
     * unpublished or inapplicable rate, or the rate's date-window/stay/lead-time restrictions —
     * and the pricing lookup underneath is validity-blind (it reads price rows regardless), so
     * without this re-check a stale or crafted payload could book an entry or rate the create
     * form never offers. Shared-rate capacity is deliberately NOT re-checked: like the stock
     * count, it is exactly what the toggle exists to bypass.
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

        if ($rateId === null) {
            return;
        }

        $rate = Rate::published()->find($rateId);

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
        // Without a configured (published, routable) payment page there is no link to pay
        // an online gateway through — only manually-confirmable (offline) gateways work. A
        // zero requested amount is collected through no gateway at all (the booking confirms
        // immediately), so it needs no payment page and must not be rejected for lacking one.
        if (! $amount->isZero()
            && Reservation::resolveCustomerPageEntry(config('resrv-config.manual_reservations_payment_entry')) === null
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

    /** @return Collection<int, array{quantity: int, price: string, total: PriceClass}> keyed by extra id */
    protected function priceExtras(array $input, array $data): Collection
    {
        $calcData = array_merge($data, ['item_id' => $input['item_id']]);

        // Carry the customer payload so a custom-priced extra (Extra::getCustomPrice) reads its
        // multiplier from the same checkout-form field the frontend does. The CP has no
        // resrv-search session to fall back on, so without this a custom extra silently prices at
        // ×1 and the reservation charges less than the equivalent frontend checkout. Both the live
        // quote and creation carry it — the admin must review the same total that will be stored,
        // so a selected custom extra whose driving field is still empty fails the quote (below)
        // rather than previewing an amount creation would not charge.
        if (! empty($input['customer'])) {
            $calcData['customer'] = collect($input['customer']);
        }

        return collect($input['extras'] ?? [])->mapWithKeys(function ($item) use ($calcData) {
            $id = (int) ($item['id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            // Only extras published and attached to this entry may be priced/attached — mirrors
            // the frontend's $entry->extras()->where('published', true) scoping and the options
            // ownership check in priceOptions(). Extra::find() alone would accept an extra from
            // another entry, letting a stale/crafted payload price and attach a foreign extra.
            $extra = Extra::whereHas('entries', fn ($query) => $query->where('resrv_entries.item_id', $calcData['item_id']))
                ->where('published', true)
                ->find($id);

            if (! $extra) {
                throw new ManualReservationException(__('The selected extra does not belong to this entry.'));
            }

            if ($quantity < 1) {
                throw new ManualReservationException(__('The extra quantity must be at least 1.'));
            }

            if ($extra->maximum > 0 && $quantity > $extra->maximum) {
                throw new ManualReservationException(__('The selected quantity exceeds the maximum allowed for this extra.'));
            }

            try {
                // Fresh instances per call: calculatePrice/priceForDates write back into the
                // model's price attribute, so sharing one instance would compound the price.
                $price = (clone $extra)->priceForDates($calcData);
                $total = (clone $extra)->calculatePrice($calcData, $quantity);
            } catch (ManualReservationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // A custom-priced extra throws when its driving customer field is absent or
                // non-numeric — surface it as a domain error (422) rather than a 500.
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

            // Only published options belonging to this entry may be priced/attached — mirrors
            // the create form's optionsForEntry() scoping and the extras check above. A plain
            // Option::find() would accept an option unpublished after the form loaded.
            $option = Option::entry($input['item_id'])->where('published', true)->find($id);

            if (! $option) {
                throw new ManualReservationException(__('The selected option does not belong to this entry.'));
            }

            // calculatePrice() resolves values withTrashed() so existing reservations keep
            // pricing historical values — a NEW reservation must only accept values still on
            // offer (the create form lists an option's published, non-trashed values).
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
     * Required extras/options enforcement — the same semantics the checkout applies via
     * Reservation::validateReservation(), through the directly-callable underlying checks
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

        // Only persist known form handles — mirrors CheckoutForm::saveCustomer()'s
        // allow-list so manual reservations are indistinguishable from frontend ones.
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

        // Mirror the create page's visibility rules (feature flag + published scope): the form
        // never offers a disabled feature or an unpublished affiliate, so a stale or crafted
        // payload must not attach a commission row for one either.
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
