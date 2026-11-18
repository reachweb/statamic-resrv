<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Exceptions\PaymentAttemptStillOpen;
use Reach\StatamicResrv\Exceptions\ReservationDriftException;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Exceptions\ReservationExpiredException;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
use Reach\StatamicResrv\Exceptions\ReservationTerminatedException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;

class Checkout extends Component
{
    use Traits\HandlesDirectGatewayPayment,
        Traits\HandlesExtrasQueries,
        Traits\HandlesOptionsQueries,
        Traits\HandlesPricing,
        Traits\HandlesReservationConfirmation,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'checkout';

    #[Locked]
    public EnabledExtras $enabledExtras;

    #[Locked]
    public EnabledOptions $enabledOptions;

    #[Locked]
    public string $selectedGateway = '';

    public array $availableGateways = [];

    #[Locked]
    public $coupon;

    #[Locked]
    public bool $enableCoupon = true;

    public int $step = 1;

    public bool $enableExtrasStep = true;

    public $reservationError = false;

    public function mount()
    {
        try {
            $this->reservation();
        } catch (ReservationTerminatedException $e) {
            // CONFIRMED / PARTNER / REFUNDED → the user already completed the flow (or is
            // returning via back button after a successful checkout). Redirect to the
            // checkout-completed entry rather than showing a terminal error page.
            return $this->redirectToCheckoutComplete(session('resrv_reservation'));
        } catch (ReservationException $e) {
            // ReservationExpiredException + any other ReservationException land here —
            // full-page terminal error view.
            $this->reservationError = $e->getMessage();
        }

        // A refresh drops step/selectedGateway back to defaults but leaves any prior step-3
        // payment_surcharge (and active Stripe intent) persisted on the reservation. Roll them
        // back so the sidebar doesn't render a stale gateway fee / inflated payable-now, and
        // so a lingering intent doesn't survive into the next pass through checkout.
        if (! $this->reservationError) {
            $this->resetPaymentState();
        }

        $this->initializeExtrasAndOptions();

        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }

        $this->coupon = session('resrv_coupon') ?? null;
    }

    /**
     * Redirect to the configured checkout-completed entry. Used when the user lands on the
     * checkout with a reservation that is already in a terminal-confirmed state.
     */
    protected function redirectToCheckoutComplete(?int $reservationId = null)
    {
        $id = $reservationId ?? (isset($this->reservation) ? $this->reservation->id : null);
        $url = $this->getCheckoutCompleteEntry()->absoluteUrl();

        if ($id) {
            $url .= '?payment_pending='.$id;
        }

        return redirect()->to($url);
    }

    #[Computed]
    public function reservation()
    {
        return $this->getReservation();
    }

    #[Computed]
    public function entry()
    {
        return $this->getEntry($this->reservation->item_id);
    }

    public function goToStep(int $step): void
    {
        if ($step < 3 && $this->selectedGateway !== '' && ! $this->resetPaymentState()) {
            return;
        }
        $this->step = $step;
    }

    public function initializeExtrasAndOptions(): void
    {
        // When extras step is disabled, load from session if available
        if ($this->enableExtrasStep === false) {
            if (session()->has('resrv-extras')) {
                $this->enabledExtras->fill(session('resrv-extras'));
            } else {
                $this->enabledExtras->extras = collect();
            }
            if (session()->has('resrv-options')) {
                $this->enabledOptions->fill(session('resrv-options'));
            } else {
                $this->enabledOptions->options = collect();
            }

            if (! $this->reservationError) {
                $this->repriceEnabledAddons();
            }
        } else {
            $this->enabledExtras->extras = collect();
            $this->enabledOptions->options = collect();
        }
    }

    /**
     * Re-price the session-carried extras/options against the actual reservation before
     * anything consumes them. Several components persist the shared 'resrv-extras' /
     * 'resrv-options' session keys on every Livewire dehydrate, so the stored prices can
     * be a stale snapshot (e.g. extras priced at a previous search quantity after a
     * multi-cart selection changed it) — trusting them makes validateTotal() throw
     * ReservationDriftException on mount with no way for the customer to recover.
     * Prices are recomputed the same way Reservation::validateExtraCharges() does
     * (per child for parent reservations) so the two totals always agree.
     */
    protected function repriceEnabledAddons(): void
    {
        if ($this->enabledExtras->extras->isEmpty() && $this->enabledOptions->options->isEmpty()) {
            return;
        }

        $reservation = $this->reservation;

        $datasets = $reservation->addonPricingData();

        // Resolve extras through the query Reservation::extraForValidation() uses — the entry's
        // selectable extras — so a selection carried over from another entry is never priced here.
        $entry = ResrvEntry::query()->itemId($reservation->item_id)->first();

        $this->enabledExtras->extras = $this->enabledExtras->extras->map(function ($extra) use ($entry, $reservation) {
            // Conditions are not needed to price.
            $extraModel = $entry?->extras()->selectable()->without('conditions')->find($extra['id']);

            // Leave unresolvable extras untouched — extraForValidation() raises the designed
            // ExtrasException for them during validation, so the stale selection must reach
            // it rather than be dropped here and silently un-book itself.
            if (! $extraModel) {
                return $extra;
            }

            $extra['price'] = $reservation->extraUnitPrice($extraModel)->format();

            // One unit's line is its price (EnabledExtras::lineTotal()), as in withExtraLineTotal(),
            // so only a larger quantity is priced again as a line.
            unset($extra['total']);
            if ((int) $extra['quantity'] > 1) {
                $extra['total'] = $reservation->extraLineTotal($extraModel, (int) $extra['quantity'])->format();
            }

            return $extra;
        });

        $this->enabledOptions->options = $this->enabledOptions->options->map(function ($option) use ($datasets) {
            $value = OptionValue::find($option['value']);

            // Leave unresolvable values untouched — optionForValidation() raises the designed
            // OptionsException for them during validation.
            if (! $value) {
                return $option;
            }

            $totalPrice = Price::create(0);

            foreach ($datasets as $data) {
                $totalPrice->add(Price::create($value->priceForDates($data)));
            }

            $option['price'] = $totalPrice->format();

            return $option;
        });
    }

    /**
     * Run $write while no other request writes this reservation's add-on lines and totals. Two tabs
     * booking add-ons or changing the coupon at once could otherwise interleave their writes and
     * leave lines that don't add up to the total handleSecondStep() charges.
     */
    protected function withReservationLock(Closure $write)
    {
        // The TTL outlives voiding a payment intent (two gateway round-trips), as updateTotals() does.
        try {
            return Cache::lock('resrv-checkout-'.session('resrv_reservation'), 300)->block(10, function () use ($write) {
                // Read the row afresh: the request we waited for may have just written it.
                unset($this->reservation);

                return $write();
            });
        } catch (LockTimeoutException) {
            $this->addError('reservation', 'The reservation is being updated in another window. Please try again.');
        }
    }

    public function handleFirstStep()
    {
        // Validate data
        $this->validate();

        return $this->withReservationLock(function () {
            // Confirm that the reservation data is valid by cross-checking with the database.
            // Terminal checks run BEFORE drift checks — otherwise an expired reservation whose
            // stale data also drifted would get demoted to a recoverable banner because the
            // drift exception would be thrown first.
            try {
                $this->confirmReservationHasNotExpired();
                $this->confirmReservationIsValid();
            } catch (ReservationExpiredException $e) {
                $this->reservationError = $e->getMessage();

                return;
            } catch (ReservationTerminatedException $e) {
                return $this->redirectToCheckoutComplete();
            } catch (OptionsException $e) {
                $this->addError('options', $e->getMessage());

                return;
            } catch (ExtrasException $e) {
                $this->addError('extras', $e->getMessage());

                return;
            } catch (ReservationDriftException $e) {
                $this->addError('reservation', $e->getMessage());

                return;
            } catch (ReservationException $e) {
                $this->addError('reservation', $e->getMessage());

                return;
            }

            // Validation only checks that the lines add up to the server's total, so write the
            // reservation and its pivot rows from the server's per-line prices, not the payload's.
            $this->repriceEnabledAddons();

            $totals = $this->calculateReservationTotals();

            $toUpdate = [
                'total' => $totals->get('total')->format(),
            ];

            if (config('resrv-config.payment') == 'everything' || ! $this->freeCancellationPossible()) {
                $toUpdate['payment'] = $totals->get('total')->format();
                // Writing a fresh base payment — clear any stale surcharge from a previous step-3 pass
                $toUpdate['payment_surcharge'] = 0;
            }

            // Update the reservation with the total
            $this->reservation->update($toUpdate);

            // Sync extras & options to the database. Don't advance if either fails —
            // the total was already written above, so proceeding would let the user pay
            // an amount that doesn't match the extras/options actually attached.
            if (! $this->assignExtras() || ! $this->assignOptions()) {
                return;
            }

            $this->step = 2;
        });
    }

    #[On('checkout-form-submitted')]
    public function handleSecondStep()
    {
        $this->resetErrorBag('reservation');

        // Price/availability drift is intentionally NOT re-validated here. The quote and stock are
        // held at reservation creation and re-confirmed once in handleFirstStep(); for the rest of
        // the minutes_to_hold window the price is locked. The charge below uses the server-stored
        // reservation->payment (never client input), so there is no tampering vector — re-pricing
        // now would only reject legitimate customers whose held price drifted mid-checkout,
        // defeating the hold. Only expiry is re-checked.

        // Make sure the reservation is not expired — wrap every subsequent $this->reservation
        // access too, because the getReservation() time-check inside the computed can itself
        // throw ReservationExpiredException (the reservation may have aged past
        // minutes_to_hold between mount and this submit).
        try {
            $this->confirmReservationHasNotExpired();

            // If the payment amount is zero, just show the confirmation page
            if ($this->reservationPaymentIsZero()) {
                return $this->handleReservationWithZeroPayment();
            }
        } catch (ReservationExpiredException $e) {
            $this->reservationError = $e->getMessage();

            return;
        } catch (ReservationTerminatedException $e) {
            return $this->redirectToCheckoutComplete();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        // Reset any stale payment state from a previous pass through checkout
        if (! $this->resetPaymentState()) {
            return;
        }

        // Populate available gateways: those the current payment amount allows and that load
        $manager = app(PaymentGatewayManager::class);
        $reservation = $this->reservation->fresh();
        $this->availableGateways = $manager->availableForFrontend($reservation->payment);

        // No gateway left — surface an error and stay on step 2
        if (empty($this->availableGateways)) {
            $this->addNoGatewayError($reservation);

            return;
        }

        // If only one surviving gateway, auto-select and initialize payment
        if (count($this->availableGateways) === 1) {
            return $this->initializePayment($this->availableGateways[0]['name']);
        }

        // Multiple gateways — show picker
        $this->step = 3;
    }

    #[On('gateway-selected')]
    public function selectGateway(string $gateway)
    {
        $this->resetErrorBag('reservation');

        $manager = app(PaymentGatewayManager::class);

        if (! $manager->has($gateway)) {
            $this->addError('reservation', __('Invalid payment gateway selected.'));

            return;
        }

        $reservation = $this->reservation->fresh();
        if (! $manager->isAvailableFor($gateway, $reservation->payment)) {
            // The clicked gateway is being rejected — fully reset payment state (intent + surcharge)
            // carried over from a prior tab or browser-back copy of step 3, so a later webhook can't
            // act on the stale intent and the sidebar doesn't show an inflated payable-now from the
            // previous gateway's surcharge.
            return $this->relistGateways($reservation, __('statamic-resrv::frontend.gatewayNotAvailableForAmount'));
        }

        // A gateway that cannot be built is left out of availableForFrontend(), but a crafted or
        // stale gateway-selected can still name it. Refuse it before initializePayment() voids the
        // intent of the gateway the customer is already paying with. It comes after the amount
        // check, which never builds the gateway and must still reset stale payment state.
        try {
            $manager->gateway($gateway);
        } catch (\Throwable $e) {
            report(new \RuntimeException("Payment gateway [{$gateway}] could not be loaded and its selection was refused.", 0, $e));

            // A customer already paying through another gateway keeps that payment as it is. The
            // reservation is checked too, read again after the build attempt: a stale picker holds ''
            // while another tab's payment is live, and that payment may have started while this
            // request was still building the gateway.
            if ($this->selectedGateway !== '' || $reservation->fresh()->hasGatewayPayment()) {
                $this->addError('reservation', __('Invalid payment gateway selected.'));

                return;
            }

            // At the picker, drawn while the gateway still loaded, offer only the gateways that load now.
            return $this->relistGateways($reservation, __('Invalid payment gateway selected.'));
        }

        return $this->initializePayment($gateway);
    }

    /**
     * After a refused selection, reset the payment state and list the gateways again: back to
     * step 2 when none is left, the only one left selected, or the picker kept with $error.
     */
    protected function relistGateways(Reservation $reservation, string $error)
    {
        if (! $this->resetPaymentState()) {
            return;
        }

        $this->availableGateways = app(PaymentGatewayManager::class)->availableForFrontend($reservation->payment);

        // No gateway left — bounce back to step 2 with the no-gateway error
        if (empty($this->availableGateways)) {
            $this->addNoGatewayError($reservation);
            $this->step = 2;

            return;
        }

        // Single surviving gateway — auto-select and initialize payment
        if (count($this->availableGateways) === 1) {
            return $this->initializePayment($this->availableGateways[0]['name']);
        }

        // Multiple still available — keep the picker visible with a contextual error
        $this->addError('reservation', $error);
    }

    /**
     * Blames the amount only when it rules out every gateway. Otherwise every gateway the amount
     * allows failed to load, which the customer can't fix by changing the booking.
     */
    protected function addNoGatewayError(Reservation $reservation): void
    {
        $this->addError('reservation', app(PaymentGatewayManager::class)->allowsAmount($reservation->payment)
            ? __('statamic-resrv::frontend.noGatewayAvailable')
            : __('statamic-resrv::frontend.noGatewayAvailableForAmount'));
    }

    /**
     * Void the stored intent and drop its surcharge. False when the intent could not be voided: the
     * reservation keeps both, and underIntentLock() has told the customer.
     */
    public function resetPaymentState(): bool
    {
        $reset = $this->underIntentLock($this->reservation->fresh(), function (Reservation $reservation) {
            // MUST NOT wipe payment_id/gateway or cancel the intent for non-PENDING reservations —
            // StripePaymentGateway::refund() needs payment_id to call Stripe, and cancelling a
            // live intent for a confirmed reservation would unwind a completed booking.
            if ($reservation->status !== ReservationStatus::PENDING->value) {
                return true;
            }

            if (! $this->cancelActiveIntent($reservation)) {
                return null;
            }

            // selectedGateway resets on page refresh but payment_surcharge persists in DB
            if (! $reservation->payment_surcharge->isZero()) {
                $reservation->update(['payment_surcharge' => 0]);
            }

            return true;
        });

        if ($reset) {
            $this->reset('selectedGateway', 'clientSecret', 'publicKey', 'paymentView');
        }

        return (bool) $reset;
    }

    /**
     * Void the stored intent; false when it already moves money. Call under the intent lock with a
     * fresh PENDING reservation. Unlike the pay page's AWAITING_PAYMENT row, a PENDING one is expired
     * by the old intent's cancellation webhook, so the reference (payment_id and payment_gateway, a
     * pair) is cleared before the void and restored when voidSupersededIntent() can't prove it dead.
     *
     * @throws PaymentAttemptStillOpen
     */
    protected function cancelActiveIntent(Reservation $reservation): bool
    {
        $manager = app(PaymentGatewayManager::class);
        $reference = $reservation->only('payment_id', 'payment_gateway');

        if (blank($reference['payment_id']) || ! $manager->has((string) $reference['payment_gateway'])) {
            return true;
        }

        $reservation->update(['payment_id' => '', 'payment_gateway' => '']);
        $voided = false;

        try {
            return $voided = $this->voidSupersededIntent($manager->gateway($reference['payment_gateway']), $reservation, $reference['payment_id']);
        } finally {
            if (! $voided) {
                $reservation->update($reference);
            }
        }
    }

    /**
     * Void the stored intent, then create and mount one for $gatewayName's total. Unlike the pay page,
     * the checkout never resumes a stored intent: it may be priced for another amount or surcharge.
     */
    protected function initializePayment(string $gatewayName)
    {
        $reservation = $this->reservation->fresh();
        $manager = app(PaymentGatewayManager::class);
        $gateway = $manager->gateway($gatewayName);
        $payment = $reservation->payment;
        $surcharge = $manager->calculateSurcharge($gatewayName, $payment);
        $amount = Price::create($payment->format())->add($surcharge);
        $returnUrl = $this->getCheckoutCompleteEntry()->absoluteUrl();

        // The intent is stored only while the reservation still owes the amount it was priced from:
        // no intent outlives an amount change, such as a coupon applied in another tab.
        $stillPayable = fn (Reservation $fresh) => $fresh->status === ReservationStatus::PENDING->value
            && $fresh->payment->equals($payment);

        $intent = $this->underIntentLock($reservation, function (Reservation $reservation) use ($gateway, $gatewayName, $amount, $surcharge, $returnUrl, $stillPayable) {
            if (! $stillPayable($reservation)) {
                throw new ReservationNoLongerPayable($reservation->id);
            }

            // `payment` stays the reservation amount (read by CP, API, emails); the surcharge is stored apart.
            return $this->cancelActiveIntent($reservation)
                ? $this->createIntent($gateway, $reservation, $amount, $returnUrl, $stillPayable, [
                    'payment_gateway' => $gatewayName,
                    'payment_surcharge' => $surcharge->format(),
                ])
                : null;
        });

        // Back to choosing: the picker when there is a choice, otherwise the details step.
        if ($intent === null) {
            $this->reset('selectedGateway', 'clientSecret', 'publicKey', 'paymentView');
            $this->step = count($this->availableGateways) > 1 ? 3 : 2;

            return;
        }

        $this->selectedGateway = $gatewayName;
        $this->step = 3;

        return $this->mountIntent($gateway, $reservation, $intent, $amount, $returnUrl);
    }

    /**
     * Run $step under the intent lock on $reservation, read again inside it. Null when $step could
     * not finish: a failure is shown, and a null from $step (the stored intent already moves money,
     * so its webhook confirms the booking) sends the customer to checkout complete.
     */
    protected function underIntentLock(Reservation $reservation, Closure $step): mixed
    {
        try {
            $result = $this->withIntentLock($reservation, fn () => $step($reservation->refresh()));
        } catch (PaymentAttemptStillOpen $e) {
            $this->addError('reservation', __('statamic-resrv::frontend.paymentAttemptStillOpen', [
                'date' => $e->expiresAt->copy()->setTimezone(config('app.timezone'))->format('d-m-Y H:i T'),
            ]));

            return null;
        } catch (\Throwable $e) {
            // A transition or another tab changing the reservation meanwhile is not a fault.
            if (! $e instanceof ReservationNoLongerPayable) {
                report($e);
            }

            $this->addError('reservation', __('statamic-resrv::frontend.paymentMountFailed'));

            return null;
        } finally {
            unset($this->reservation);
        }

        if ($result === null) {
            $this->redirectToCheckoutComplete($reservation->id);
        }

        return $result;
    }

    #[On('checkout-form-submitted-without-payment')]
    public function handleReservationWithoutPayment()
    {
        // Make sure the reservation is not expired
        try {
            $this->confirmReservationHasNotExpired();
        } catch (ReservationExpiredException $e) {
            $this->reservationError = $e->getMessage();

            return;
        } catch (ReservationTerminatedException $e) {
            return $this->redirectToCheckoutComplete();
        } catch (ReservationException $e) {
            $this->addError('reservation', $e->getMessage());

            return;
        }

        // Get a fresh record from the database
        $reservation = $this->reservation->fresh();

        // Ensure once again that the affiliate can confirm the reservation without payment
        if (! $this->affiliateCanSkipPayment()) {
            $this->addError('reservation', 'You cannot confirm this reservation without payment. Please clear your cookies and try again.');

            return;
        }

        // PARTNER is the terminal-equivalent state for affiliate (skip-payment) bookings.
        if ($this->confirmOrAlreadyConfirmed($reservation, ReservationStatus::PARTNER)) {
            return $this->redirectToCheckoutComplete($reservation->id);
        }

        $this->reservationError = 'This reservation has expired. Please start over.';
    }

    protected function handleReservationWithZeroPayment()
    {
        // Ensure once again that the total is zero
        if (! $this->reservationPaymentIsZero()) {
            $this->addError('reservation', 'We cannot confirm this reservation. Please try again.');

            return;
        }

        if ($this->confirmOrAlreadyConfirmed($this->reservation, ReservationStatus::CONFIRMED)) {
            return $this->redirectToCheckoutComplete($this->reservation->id);
        }

        $this->reservationError = 'This reservation has expired. Please start over.';
    }

    protected function confirmReservationIsValid(): void
    {
        $totals = $this->calculateReservationTotals();

        // Confirm everything is OK before we move on
        $this->reservation->validateReservation(array_merge(
            $this->getAvailabilityDataFromReservation(),
            [
                'payment' => $this->reservation->payment,
                'price' => $this->reservation->price,
                'total' => $totals->get('total'),
                'extras' => $this->enabledExtras->extras,
                'options' => $this->enabledOptions->options,
                'customer' => $this->reservation->customerData ?? collect(),
            ],
        ), $this->entry->id());
    }

    protected function confirmReservationHasNotExpired(): void
    {
        $expireAt = Carbon::parse($this->reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');
        if ($expireAt < Carbon::now() || $this->reservation->fresh()->status === ReservationStatus::EXPIRED->value) {
            throw new ReservationExpiredException('This reservation has expired. Please start over.');
        }
    }

    public function addCoupon(string $coupon)
    {
        $data = validator(['coupon' => $coupon], ['coupon' => 'required|alpha_dash'], ['coupon' => 'The coupon code is invalid.'])->validate();

        try {
            $couponModel = DynamicPricing::searchForCoupon($data['coupon'], $this->reservation->id);
        } catch (CouponNotFoundException $exception) {
            $this->addError('coupon', $exception->getMessage());

            return;
        }
        session(['resrv_coupon' => $data['coupon']]);
        $this->coupon = $data['coupon'];
        $this->resetValidation('coupon');
        $this->dispatch('coupon-applied', $this->coupon);
    }

    public function removeCoupon()
    {
        $this->dispatch('coupon-removed', $this->coupon, true);
        session()->forget('resrv_coupon');
        $this->coupon = null;
    }

    #[On('extras-updated')]
    public function updateExtras($extras): void
    {
        $this->enabledExtras->extras = collect($extras);
    }

    #[On('options-updated')]
    public function updateOptions($options): void
    {
        $this->enabledOptions->options = collect($options);
    }

    protected function assignExtras(): bool
    {
        // An empty selection syncs too: it unbooks what an earlier pass through this step booked.
        try {
            $this->reservation->extras()->sync($this->enabledExtras->extrasToSync());
        } catch (\Exception $e) {
            $this->addError('extras', 'There was an error assigning the extras. Please try again.');

            return false;
        }

        return true;
    }

    protected function assignOptions(): bool
    {
        // An empty selection syncs too: it unbooks what an earlier pass through this step booked.
        try {
            $this->reservation->options()->sync($this->enabledOptions->optionsToSync());
        } catch (\Exception $e) {
            $this->addError('options', 'There was an error assigning the options. Please try again.');

            return false;
        }

        return true;
    }

    #[On('coupon-applied'), On('coupon-removed')]
    public function updateTotals($coupon, $removeCoupon = false): void
    {
        $this->withReservationLock(function () use ($coupon, $removeCoupon) {
            // This try-catch block is here to prevent an error if a user tries to remove a coupon that has been deleted
            try {
                $couponModel = DynamicPricing::searchForCoupon($coupon, $this->reservation->id);

                if ($couponModel->appliesToExtras()) {
                    $this->dispatch('extras-coupon-changed');
                }
            } catch (CouponNotFoundException $exception) {
                // Dispatch the event anyway
                $this->dispatch('extras-coupon-changed');
            }

            // A coupon change invalidates any intent created for the old amount. Cancel it off the
            // persisted reservation — resetPaymentState() reads payment_id — not the component-local
            // selectedGateway, which a stale tab can hold as '' while the row still has a live intent.
            $wasOnPaymentStep = $this->selectedGateway !== '' || $this->step >= 3;
            if (! $this->resetPaymentState()) {
                return;
            }
            if ($wasOnPaymentStep) {
                $this->step = 2;
            }

            // A coupon can change the extras' prices too: re-price the booked ones, which the total below
            // charges. At step 1 the Extras component re-prices the selection.
            $this->reservation->repriceBookedExtras();

            // Past step 1 nothing re-prices the selection, and it can differ from what is booked (another
            // tab booked other add-ons, or the browser sent another selection). The sidebar lists the
            // booked add-ons instead, priced as bookedAddonsTotal() charges them below.
            if ($this->step > 1) {
                $this->enabledExtras->extras = $this->reservation->extras()->get()->mapWithKeys(fn (Extra $extra) => [$extra->id => [
                    'id' => $extra->id,
                    'name' => $extra->override_label ?? $extra->name,
                    'quantity' => (int) $extra->pivot->quantity,
                    'price' => Price::create($extra->pivot->price)->format(),
                    'total' => $extra->priceFromPivot(),
                ]]);

                $this->enabledOptions->options = $this->reservation->optionsForEmail()->mapWithKeys(fn (Option $option) => [$option->id => [
                    'id' => $option->id,
                    'value' => (int) $option->pivot->value,
                    'price' => $this->reservation->bookedOptionPrice($option)->format(),
                    'optionName' => $option->name,
                    'valueName' => $option->values->firstWhere('id', $option->pivot->value)?->name,
                ]]);
            }

            // Recompute price, total and payment, then persist in one update so a concurrent
            // handleSecondStep() never reads the deposit before the full-payment override lands.
            $prices = $this->getUpdatedPrices();

            // The browser dispatches this event, and handleSecondStep() charges what it writes, so the
            // add-ons are the ones handleFirstStep() validated and booked, not the current selection.
            $total = Price::create($prices['price'])->add($this->reservation->bookedAddonsTotal());

            // Reapply handleFirstStep()'s full-payment decision: non-refundable, the full-payment
            // window, or everything mode must pay the whole total, not the deposit.
            $payment = (config('resrv-config.payment') == 'everything' || ! $this->freeCancellationPossible())
                ? $total->format()
                : $prices['payment'];

            $this->reservation->update([
                'price' => $prices['price'],
                'payment' => $payment,
                'payment_surcharge' => 0,
                'total' => $total->format(),
            ]);
            unset($this->reservation);

            CouponUpdated::dispatch($this->reservation, $coupon, $removeCoupon);
        });
    }

    public function render()
    {
        if ($this->reservationError) {
            return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
        }

        return view('statamic-resrv::livewire.'.$this->view);
    }
}
