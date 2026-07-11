<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Reach\StatamicResrv\Exceptions\ReservationNoLongerPayable;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;

/**
 * Mounts a gateway payment for a reservation OUTSIDE the checkout session — used by the
 * manual-reservation pay page (and intended for the status-page balance section from plan
 * 008): resolve the reservation's locked gateway, create or resume a payment intent, and
 * expose the properties the gateway's paymentView() blade reads ($wire.clientSecret,
 * $wire.publicKey, $wire.checkoutCompletedUrl — the return URL — and $amount).
 */
trait HandlesDirectGatewayPayment
{
    #[Locked]
    public string $clientSecret = '';

    #[Locked]
    public string $publicKey = '';

    #[Locked]
    public float $amount = 0;

    #[Locked]
    public string $paymentView = '';

    /** The absolute URL the gateway sends the customer back to after paying. */
    #[Locked]
    public string $checkoutCompletedUrl = '';

    /** Set when the existing intent already succeeded or is processing — nothing to remount. */
    #[Locked]
    public bool $paymentProcessing = false;

    /**
     * Create-or-resume an intent for $amount and mount the gateway's payment view.
     * Returns a redirect response for redirect gateways, null otherwise.
     *
     * @param  ?Closure  $stillPayable  Given the freshly-locked reservation, returns whether it may
     *                                  still be charged. Re-evaluated inside the intent-creation lock
     *                                  so a status change racing the gateway round-trip cannot leave a
     *                                  chargeable intent on a terminal/confirmed booking. Null skips
     *                                  the re-check (the caller vouches the row cannot change).
     *
     * @throws UnknownPaymentGateway
     * @throws ReservationNoLongerPayable
     */
    protected function mountGatewayPayment(Reservation $reservation, PriceClass $amount, string $returnUrl, ?Closure $stillPayable = null)
    {
        // resolvePaymentGateway(), not the manager's forReservation(): a blank recorded gateway
        // (legacy rows, programmatic manual reservations) falls back to the default here exactly
        // as the payment-request email path does — forReservation() throws on gateway('') and
        // would turn every pay link that email offered into a guaranteed error.
        $gateway = $reservation->resolvePaymentGateway();

        $intent = $this->resolveOrCreateIntent($gateway, $reservation, $amount, $returnUrl, $stillPayable);

        if ($intent === null) {
            $this->paymentProcessing = true;

            return null;
        }

        // Redirect gateways bake their return URL from $returnUrl (threaded into paymentIntent by
        // resolveOrCreateIntent), routing this customer back to the pay-by-link page. Here we just
        // forward to the provider's hosted page, tagging resrv_gateway so the return resolves the
        // gateway; ReservationPayment::state() reads the interim status. See Step 12. Resumed
        // intents without a provider URL never reach this point (resolveOrCreateIntent re-mints
        // them), so an empty redirectTo here means the gateway's paymentIntent() broke its
        // contract — fail loudly rather than redirect the customer to a dead URL.
        if ($gateway->redirectsForPayment()) {
            $redirectUrl = (string) ($intent->redirectTo ?? '');

            if ($redirectUrl === '') {
                throw new \RuntimeException(
                    "The redirect gateway [{$reservation->payment_gateway}] returned a payment intent without a redirectTo URL."
                );
            }

            $separator = str_contains($redirectUrl, '?') ? '&' : '?';

            return redirect()->away($redirectUrl.$separator.http_build_query([
                'resrv_gateway' => $reservation->payment_gateway,
            ]));
        }

        $this->publicKey = (string) $gateway->getPublicKey($reservation);
        $this->paymentView = $gateway->paymentView();
        $this->checkoutCompletedUrl = $returnUrl;
        $this->amount = (float) $amount->format();
        $this->clientSecret = (string) $intent->client_secret;

        return null;
    }

    /**
     * Resume the reservation's stored intent when it is still payable, otherwise mint a fresh one; a
     * dead (cancelled) intent gets replaced, and a succeeded/processing one returns null — the money is
     * already moving, so creating another intent would risk a double charge. Both resume and mint run
     * inside the per-reservation intent lock. When $stillPayable is given, the reservation's payability
     * is re-verified under that lock, again on a fresh row right before a resumed intent is handed out
     * (the retrieve is a network round-trip a transition can commit during), and under the row lock at
     * write time for a mint — so a status change racing a gateway round-trip cannot leave a chargeable
     * intent on a terminal/confirmed booking; if it fires, ReservationNoLongerPayable is thrown (and a
     * just-minted intent is voided first).
     *
     * @throws ReservationNoLongerPayable
     */
    protected function resolveOrCreateIntent(PaymentInterface $gateway, Reservation $reservation, PriceClass $amount, string $returnUrl, ?Closure $stillPayable = null): ?object
    {
        // Serialize create-and-store per reservation so two concurrent pay() requests can't each
        // mint a payable intent — last-write-wins would leave the loser untracked yet chargeable.
        // The gateway call is a network round-trip, so this uses an app-level lock that holds no
        // DB connection (unlike a row lock). The waiter re-checks under the lock and resumes the
        // winner's intent instead of creating a second one. Resume rides the same lock — a stored
        // intent must never be handed out without the payability re-check below (the outer pay()
        // guard ran before this lock, so a CP cancel/confirm or the hold-lapse sweep may have
        // transitioned the row since).
        //
        // The TTL must outlive the WHOLE critical section — up to three provider round-trips
        // (retrieve, void, mint) at Stripe's default 80s client timeout — or it expires while a
        // slow gateway call is still in flight and a waiter can acquire the lock, see no stored
        // intent, and mint a duplicate chargeable one the first request's write then untracks.
        // Waiters give up after 10s (a retryable payment error), so the long TTL only costs
        // recovery time when a holder dies without releasing.
        return Cache::lock('resrv-payment-intent-'.$reservation->id, 300)->block(10, function () use ($gateway, $reservation, $amount, $returnUrl, $stillPayable) {
            $reservation->refresh();

            if ($stillPayable !== null && ! $stillPayable($reservation)) {
                throw new ReservationNoLongerPayable($reservation->id);
            }

            $resumed = $this->resumeExistingIntent($gateway, $reservation);
            if ($resumed !== false) {
                if ($this->resumedIntentIsMountable($gateway, $resumed)) {
                    // Null means the money is already moving — nothing chargeable is handed out,
                    // so no re-check is needed.
                    if ($resumed === null) {
                        return null;
                    }

                    // The retrieve above was a network round-trip, so a transition may have
                    // committed since the check under the lock: re-verify on a fresh row before
                    // handing out the chargeable secret — the resume-path analogue of the mint
                    // path's row-locked re-check. No void on failure: the transition that made
                    // the row unpayable owns the stored intent's disposal (cancelOpenIntentQuietly
                    // voids it; settlePaidOutOfBand deliberately KEEPS a capturing intent's
                    // reference so the charge stays refundable — voiding here would strand it).
                    if ($stillPayable !== null) {
                        $fresh = Reservation::query()->find($reservation->id);

                        if ($fresh === null || ! $stillPayable($fresh)) {
                            throw new ReservationNoLongerPayable($reservation->id);
                        }
                    }

                    return $resumed;
                }

                // Resumable but unmountable: the stored intent must be replaced — but its
                // credentials were already handed to the customer (an earlier tab or the
                // provider's hosted page can still complete it), so unlike the uncommitted
                // mint below a failed void cannot be tolerated: minting anyway would leave
                // TWO chargeable intents for one reservation. False means the money started
                // moving during the void round-trip — surface the processing state instead.
                if (! $this->voidSupersededIntent($gateway, $reservation, (string) $reservation->payment_id)) {
                    return null;
                }
            }

            // $returnUrl passed positionally; 3-param gateways ignore it (see Step 12).
            $intent = $gateway->paymentIntent($amount, $reservation, $reservation->customerData ?? collect(), $returnUrl);

            // Bind the payment_id write to a row lock and re-verify payability in the SAME critical
            // section. The app lock above only serializes concurrent pay() calls; a CP cancel/confirm
            // or the hold-lapse sweep transitions the row through transitionTo()'s DB row lock — a
            // different mutex — and can commit a terminal/confirmed status DURING the paymentIntent()
            // network round-trip, after the check above passed. Whoever writes payment_id must observe
            // the final status under the row lock: if the row is no longer payable we void the just-
            // minted intent rather than leave a chargeable orphan the customer could still complete on
            // a cancelled or already-confirmed booking.
            $committed = DB::transaction(function () use ($reservation, $intent, $stillPayable) {
                $fresh = Reservation::query()->lockForUpdate()->find($reservation->id);

                if ($fresh === null || ($stillPayable !== null && ! $stillPayable($fresh))) {
                    return false;
                }

                $attributes = ['payment_id' => $intent->id];

                // A blank recorded gateway resolved to the default at mount time; stamp the key
                // the intent was actually minted through, in the same locked write as its id.
                // Every later reader — the cancel/lapse void (which no-ops on a blank gateway,
                // stranding the live intent), the out-of-band reconcile, a refund — must reach
                // THIS provider even if the configured default changes before it runs.
                if (blank($fresh->payment_gateway)) {
                    $attributes['payment_gateway'] = app(PaymentGatewayManager::class)->defaultName();
                }

                $fresh->update($attributes);
                $reservation->setRawAttributes($fresh->getAttributes(), true);

                return true;
            });

            if (! $committed) {
                $this->voidIntentQuietly($gateway, $reservation, (string) $intent->id);

                throw new ReservationNoLongerPayable($reservation->id);
            }

            return $intent;
        });
    }

    /**
     * Whether a resumed intent can actually be mounted for its gateway. retrievePaymentIntent()'s
     * contract only requires ->status (UPGRADE-PAYMENT-GATEWAYS.md Step 13), but mounting needs
     * more: redirect gateways forward the customer to $intent->redirectTo, and inline gateways
     * render their payment view around $intent->client_secret. A spec-minimal resumed intent
     * missing its mount credential is voided and replaced with a fresh mint (whose
     * paymentIntent() contract does include it) instead of redirecting the customer nowhere or
     * rendering a payment form with an empty secret. Null (money already moving) never mounts,
     * so it always passes.
     */
    protected function resumedIntentIsMountable(PaymentInterface $gateway, ?object $intent): bool
    {
        if ($intent === null) {
            return true;
        }

        if ($gateway->redirectsForPayment()) {
            $redirectTo = $intent->redirectTo ?? null;

            return is_string($redirectTo) && $redirectTo !== '';
        }

        $clientSecret = $intent->client_secret ?? null;

        return is_string($clientSecret) && $clientSecret !== '';
    }

    /**
     * Void a superseded intent whose credentials were already handed out, and PROVE it is dead
     * before the caller mints a replacement. voidIntentQuietly()'s tolerate-and-log posture is
     * safe only for intents the customer never received; here a swallowed cancel failure
     * (StripePaymentGateway logs API errors and returns) or a cancel that skipped an intent no
     * longer in a cancellable state (it succeeded during the round-trip) would leave the old
     * intent completable NEXT TO the replacement — a double charge. So the intent is re-read
     * after the cancel and the provider's answer gates the mint: gone (absent/canceled) lets it
     * proceed; money already moving (succeeded/processing/requires_capture) returns false so the
     * caller shows the processing state and keeps the stored reference; anything still live
     * throws — the customer sees a retryable payment error and no replacement is created. A
     * transient failure of the verification read propagates for the same reason.
     */
    protected function voidSupersededIntent(PaymentInterface $gateway, Reservation $reservation, string $paymentId): bool
    {
        $cancelException = null;

        try {
            $gateway->cancelPaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            $cancelException = $e;
        }

        $existing = $gateway->retrievePaymentIntent($paymentId, $reservation);

        if ($existing === null || ($existing->status ?? '') === 'canceled') {
            return true;
        }

        if (in_array($existing->status ?? '', ['succeeded', 'processing', 'requires_capture'], true)) {
            return false;
        }

        throw $cancelException ?? new \RuntimeException(
            "Could not void the superseded payment intent [{$paymentId}] for reservation [{$reservation->id}]; refusing to mint a replacement next to a live intent."
        );
    }

    /**
     * Best-effort void of an intent that was minted but never committed to the reservation (the row
     * became unpayable under the lock). Tolerates gateway failure the same way the terminal flows do:
     * the reference was never stored, so an unreachable gateway leaves an intent that dies of old age.
     */
    protected function voidIntentQuietly(PaymentInterface $gateway, Reservation $reservation, string $paymentId): void
    {
        if ($paymentId === '') {
            return;
        }

        try {
            $gateway->cancelPaymentIntent($paymentId, $reservation);
        } catch (\Throwable $e) {
            Log::warning('Failed to void an uncommitted payment intent after the reservation became unpayable.', [
                'reservation_id' => $reservation->id,
                'payment_id' => $paymentId,
            ]);
        }
    }

    /**
     * Inspect the reservation's stored intent: the intent to resume when it is still payable,
     * null when the money is already moving (succeeded/processing/requires_capture — captured,
     * settling, or held; minting another would risk a double charge, and remounting a held
     * authorization would ask the customer to pay money that is already secured — the Step 13
     * status contract promises all three keep the reference), or false when there is nothing to
     * resume and a fresh intent is needed. A transient retrieve failure propagates (see
     * StripePaymentGateway::retrievePaymentIntent) so a still-live intent is never replaced on
     * the strength of a failed read.
     */
    protected function resumeExistingIntent(PaymentInterface $gateway, Reservation $reservation): object|false|null
    {
        if (! is_string($reservation->payment_id) || $reservation->payment_id === '') {
            return false;
        }

        $existing = $gateway->retrievePaymentIntent($reservation->payment_id, $reservation);

        if ($existing === null) {
            return false;
        }

        if (in_array($existing->status ?? '', ['succeeded', 'processing', 'requires_capture'], true)) {
            return null;
        }

        if (($existing->status ?? '') !== 'canceled') {
            return $existing;
        }

        return false;
    }

    /**
     * What the gateway's return redirect says about the payment, from the current request
     * query: 'succeeded' | 'processing' | 'failed' | null (no return parameters present).
     * The webhook remains the source of truth — this only drives interim messaging.
     */
    protected function gatewayReturnStatus(): ?string
    {
        $redirectStatus = request()->query('redirect_status');

        if (is_string($redirectStatus) && $redirectStatus !== '') {
            return $redirectStatus;
        }

        if (request()->query('payment_intent') || request()->query('payment_intent_client_secret')) {
            return 'processing';
        }

        return null;
    }
}
