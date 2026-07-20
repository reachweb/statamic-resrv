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
 * Mounts a gateway payment outside the checkout session (manual pay page; plan-008 balance
 * section later): resolve the gateway, create/resume an intent, and expose the properties
 * the gateway's paymentView() blade reads.
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
     * @param  ?Closure  $stillPayable  Whether the (fresh) reservation may still be charged; re-checked
     *                                  under the intent lock. Null skips the re-check.
     *
     * @throws UnknownPaymentGateway
     * @throws ReservationNoLongerPayable
     */
    protected function mountGatewayPayment(Reservation $reservation, PriceClass $amount, string $returnUrl, ?Closure $stillPayable = null)
    {
        // resolvePaymentGateway(), not forReservation(): a blank recorded gateway must fall back
        // to the default (matching the payment-request email path) — forReservation() throws on ''.
        $gateway = $reservation->resolvePaymentGateway();

        // redirectGatewayReturnStatus() only consults the provider when the resrv_gateway marker is
        // on the return URL, and nothing enforces the gateway's Step-12 append — so bake the marker
        // into the base. A compliant gateway's double-append is harmless (presence check, same value).
        if ($gateway->redirectsForPayment() && ! str_contains($returnUrl, 'resrv_gateway=')) {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $separator.http_build_query(['resrv_gateway' => $reservation->payment_gateway]);
        }

        $intent = $this->resolveOrCreateIntent($gateway, $reservation, $amount, $returnUrl, $stillPayable);

        if ($intent === null) {
            $this->paymentProcessing = true;

            return null;
        }

        // Resumed intents without a provider URL were re-minted in resolveOrCreateIntent, so an
        // empty redirectTo means the gateway broke its paymentIntent() contract — fail loudly
        // rather than redirect the customer to a dead URL.
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
     * Resume the stored intent when still payable, otherwise mint a fresh one; succeeded/processing
     * returns null (money already moving — another intent risks a double charge). Everything runs
     * under the per-reservation intent lock, and $stillPayable is re-verified there: on a fresh row
     * before a resume is handed out, and under the row lock at mint-write time (a just-minted
     * intent is voided before throwing).
     *
     * @throws ReservationNoLongerPayable
     */
    protected function resolveOrCreateIntent(PaymentInterface $gateway, Reservation $reservation, PriceClass $amount, string $returnUrl, ?Closure $stillPayable = null): ?object
    {
        // App-level lock (holds no DB connection through the network round-trips) serializes
        // concurrent pay() calls so two requests can't each mint a chargeable intent; a waiter
        // resumes the winner's. Resume rides the same lock so a stored intent is never handed out
        // without the payability re-check. The TTL must outlive the whole critical section — up to
        // three provider round-trips at Stripe's 80s client timeout — or a waiter could mint a
        // duplicate mid-flight; waiters give up after 10s (a retryable payment error).
        return Cache::lock('resrv-payment-intent-'.$reservation->id, 300)->block(10, function () use ($gateway, $reservation, $amount, $returnUrl, $stillPayable) {
            $reservation->refresh();

            if ($stillPayable !== null && ! $stillPayable($reservation)) {
                throw new ReservationNoLongerPayable($reservation->id);
            }

            $resumed = $this->resumeExistingIntent($gateway, $reservation);
            if ($resumed !== false) {
                if ($this->resumedIntentIsMountable($gateway, $resumed)) {
                    // Null = money already moving; nothing chargeable is handed out.
                    if ($resumed === null) {
                        return null;
                    }

                    // The retrieve was a network round-trip a transition can commit during:
                    // re-verify on a fresh row before handing out the chargeable secret. No void
                    // on failure — the transition owns the stored intent's disposal
                    // (settlePaidOutOfBand keeps a capturing intent's reference refundable).
                    if ($stillPayable !== null) {
                        $fresh = Reservation::query()->find($reservation->id);

                        if ($fresh === null || ! $stillPayable($fresh)) {
                            throw new ReservationNoLongerPayable($reservation->id);
                        }
                    }

                    return $resumed;
                }

                // Resumable but unmountable: the stored intent's credentials were already handed
                // out, so a failed void can't be tolerated — minting anyway would leave TWO
                // chargeable intents. False = money started moving; surface the processing state.
                if (! $this->voidSupersededIntent($gateway, $reservation, (string) $reservation->payment_id)) {
                    return null;
                }
            }

            $intent = $gateway->paymentIntent($amount, $reservation, $reservation->customerData ?? collect(), $returnUrl);

            // The app lock only serializes pay() calls; transitions go through transitionTo()'s DB
            // row lock (a different mutex) and can commit during the paymentIntent() round-trip.
            // So re-verify payability under the row lock in the same write as payment_id; if the
            // row is no longer payable the just-minted intent is voided below.
            $committed = DB::transaction(function () use ($reservation, $intent, $stillPayable) {
                $fresh = Reservation::query()->lockForUpdate()->find($reservation->id);

                if ($fresh === null || ($stillPayable !== null && ! $stillPayable($fresh))) {
                    return false;
                }

                $attributes = ['payment_id' => $intent->id];

                // Stamp the gateway the intent was actually minted through, atomically with its id:
                // later readers (cancel/lapse void, out-of-band reconcile, refund) must reach THIS
                // provider even if the configured default changes — a blank gateway strands them.
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
     * retrievePaymentIntent()'s contract only requires ->status (Step 13), but mounting needs
     * redirectTo (redirect gateways) or client_secret (inline). A spec-minimal resumed intent is
     * voided and re-minted instead of mounting without its credential. Null never mounts, so it passes.
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
     * Void a superseded intent whose credentials were already handed out and PROVE it dead before
     * the caller mints a replacement (Stripe's cancel swallows API errors). Re-read after the
     * cancel gates the mint: gone/canceled → true; money already moving → false (caller shows
     * processing, keeps the reference); anything still live throws so no replacement is created.
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
     * Best-effort void of an intent never committed to the reservation: the reference was never
     * stored, so a failed cancel just leaves an intent that dies of old age.
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
     * The stored intent to resume when still payable, null when the money is already moving
     * (succeeded/processing/requires_capture — Step 13 promises all three keep the reference), or
     * false when there is nothing to resume. A transient retrieve failure propagates (see
     * StripePaymentGateway::retrievePaymentIntent) so a live intent is never replaced on a failed read.
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
     * Gateway return-redirect status from the request query: 'succeeded' | 'processing' | 'failed'
     * | null. Interim messaging only — the webhook remains the source of truth.
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
