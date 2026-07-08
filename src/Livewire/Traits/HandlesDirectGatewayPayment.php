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
        $gateway = app(PaymentGatewayManager::class)->forReservation($reservation);

        $intent = $this->resolveOrCreateIntent($gateway, $reservation, $amount, $returnUrl, $stillPayable);

        if ($intent === null) {
            $this->paymentProcessing = true;

            return null;
        }

        // Redirect gateways bake their return URL from $returnUrl (threaded into paymentIntent by
        // resolveOrCreateIntent), routing this customer back to the pay-by-link page. Here we just
        // forward to the provider's hosted page, tagging resrv_gateway so the return resolves the
        // gateway; ReservationPayment::state() reads the interim status. See Step 12.
        if ($gateway->redirectsForPayment()) {
            $redirectUrl = $intent->redirectTo;
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
     * already moving, so creating another intent would risk a double charge. When $stillPayable is
     * given, the reservation's payability is re-verified inside the intent-creation lock (and under the
     * row lock at write time) so a status change racing the gateway round-trip cannot leave a
     * chargeable intent on a terminal/confirmed booking; if it fires, the minted intent is voided and
     * ReservationNoLongerPayable is thrown.
     *
     * @throws ReservationNoLongerPayable
     */
    protected function resolveOrCreateIntent(PaymentInterface $gateway, Reservation $reservation, PriceClass $amount, string $returnUrl, ?Closure $stillPayable = null): ?object
    {
        $resumed = $this->resumeExistingIntent($gateway, $reservation);

        // A payable intent to resume, or null meaning "money already moving — do not mint another".
        if ($resumed !== false) {
            return $resumed;
        }

        // Serialize create-and-store per reservation so two concurrent pay() requests can't each
        // mint a payable intent — last-write-wins would leave the loser untracked yet chargeable.
        // The gateway call is a network round-trip, so this uses an app-level lock that holds no
        // DB connection (unlike a row lock). The waiter re-checks under the lock and resumes the
        // winner's intent instead of creating a second one.
        return Cache::lock('resrv-payment-intent-'.$reservation->id, 15)->block(10, function () use ($gateway, $reservation, $amount, $returnUrl, $stillPayable) {
            $reservation->refresh();

            // Re-check payability before resuming a stored intent: the outer guard ran before this
            // lock, so the hold-lapse sweep or a CP cancel/confirm may have transitioned the row since.
            if ($stillPayable !== null && ! $stillPayable($reservation)) {
                throw new ReservationNoLongerPayable($reservation->id);
            }

            $resumed = $this->resumeExistingIntent($gateway, $reservation);
            if ($resumed !== false) {
                return $resumed;
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

                $fresh->update(['payment_id' => $intent->id]);
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
     * null when the money is already moving (succeeded/processing — minting another would risk a
     * double charge), or false when there is nothing to resume and a fresh intent is needed. A
     * transient retrieve failure propagates (see StripePaymentGateway::retrievePaymentIntent) so
     * a still-live intent is never replaced on the strength of a failed read.
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

        if (in_array($existing->status ?? '', ['succeeded', 'processing'], true)) {
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
