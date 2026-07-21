# Upgrading Custom Payment Gateways for Resrv Multiple Payment Methods

This document provides step-by-step instructions for updating a custom payment gateway class (e.g. PayPal, Mollie, Square) to work with Resrv's multiple payment methods system.

## Background

Resrv now supports multiple concurrent payment gateways. The `PaymentInterface` has seven new required methods (`name()`, `label()`, `paymentView()`, `supportsManualConfirmation()`, `cancelPaymentIntent()`, `supportsAutomaticRefunds()`, and `retrievePaymentIntent()` — the first four are covered in Step 1, `cancelPaymentIntent()` in Step 9, `supportsAutomaticRefunds()` in Step 11, and `retrievePaymentIntent()` in Step 13), and `paymentIntent()` gained a required 4th parameter, `?string $returnUrl = null` (Step 12). Gateways are registered in `config/resrv-config.php` under the `payment_gateways` key. During checkout, customers pick a gateway from a list, and the selected config key is stored on the reservation's `payment_gateway` column. Webhooks, refunds, and redirect callbacks all resolve the correct gateway using that stored key.

There are two gateway types:
- **Inline gateways** (e.g. Stripe) — render a payment form directly on the checkout page via a Blade view. `redirectsForPayment()` returns `false`.
- **Redirect gateways** (e.g. PayPal, Mollie) — redirect the customer to the provider's hosted page. `redirectsForPayment()` returns `true`.

Both types follow the same interface. The sections below are marked with which type they apply to.

---

## Step 1: Add the Four Identity Methods

**Applies to: all gateways**

> ℹ️ This step covers four of the seven new interface methods. The rest are covered later: `cancelPaymentIntent()` in **Step 9**, `supportsAutomaticRefunds()` in **Step 11**, and `retrievePaymentIntent()` in **Step 13**. Don't ship without implementing them all; the interface requires them and your gateway will fatal at boot otherwise.

Open your gateway class that implements `Reach\StatamicResrv\Http\Payment\PaymentInterface` and add these four methods:

```php
/**
 * Machine-readable slug for this gateway.
 * Used as a fallback identifier. Keep it short, lowercase, no spaces.
 * Examples: 'paypal', 'mollie', 'square'
 */
public function name(): string
{
    return 'paypal';
}

/**
 * Human-readable label shown in the checkout gateway picker.
 * This is the default label. It can be overridden per-installation
 * via the 'label' key in config/resrv-config.php.
 * Examples: 'PayPal', 'iDEAL (via Mollie)', 'Credit Card'
 */
public function label(): string
{
    return 'PayPal';
}

/**
 * The Blade view used to render this gateway's payment UI.
 *
 * For INLINE gateways: return the view that renders the payment form
 * (JS SDK embed, card fields, etc.). The view receives these Livewire
 * properties from the CheckoutPayment component:
 *   - $clientSecret (string)
 *   - $publicKey (string)
 *   - $amount (float)
 *   - $checkoutCompletedUrl (string)
 *
 * For REDIRECT gateways: this value is not used at runtime because
 * the customer is redirected away before the view renders. Return
 * the default Resrv view as a safe fallback:
 *   return 'statamic-resrv::livewire.checkout-payment';
 */
public function paymentView(): string
{
    // Inline gateway — custom view:
    return 'your-package::livewire.paypal-payment';

    // Redirect gateway — default fallback (never actually rendered):
    // return 'statamic-resrv::livewire.checkout-payment';
}
```

```php
/**
 * Whether the gateway supports manual (non-provider-verified) confirmation.
 *
 * Return true ONLY for gateways where the customer confirms without an
 * external payment provider verifying the transaction in real time
 * (e.g. offline / bank-transfer / pay-on-premises gateways).
 *
 * When true, the checkout UI shows a "Confirm Reservation" button that
 * immediately confirms the reservation without a provider callback.
 *
 * For gateways backed by a payment provider (Stripe, PayPal, etc.)
 * this MUST return false — confirmation comes from the provider's
 * webhook or redirect callback instead.
 */
public function supportsManualConfirmation(): bool
{
    return false; // Most gateways should return false
}
```

### Reference: StripePaymentGateway (inline)

```php
public function name(): string { return 'stripe'; }
public function label(): string { return 'Credit Card'; }
public function paymentView(): string { return 'statamic-resrv::livewire.checkout-payment'; }
public function supportsManualConfirmation(): bool { return false; }
```

Stripe's `paymentView()` returns the default Resrv checkout-payment Blade view, which embeds Stripe Elements via Alpine.js. If your inline gateway needs a different JS SDK or form, create a custom Blade view.

---

## Step 2: Review paymentIntent() Return Value

**Applies to: all gateways**

The `paymentIntent()` method must return an object (or stdClass) with specific properties depending on your gateway type.

### For inline gateways (redirectsForPayment() returns false)

The returned object must have:
- `->id` — a unique payment/session identifier, stored in the reservation's `payment_id` column
- `->client_secret` — passed to the frontend JS SDK to initialize the payment form

```php
public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
{
    // Create payment session with your provider's SDK...
    $session = YourProvider::createSession([
        'amount' => $amount->raw(), // numeric string in minor units (cents)
        'currency' => Str::lower(config('resrv-config.currency_isoCode')),
        'metadata' => ['reservation_id' => $reservation->id],
    ]);

    $result = new \stdClass;
    $result->id = $session->id;
    $result->client_secret = $session->client_secret;
    return $result;
}
```

### For redirect gateways (redirectsForPayment() returns true)

The returned object must have:
- `->id` — a unique payment/session identifier, stored in the reservation's `payment_id` column
- `->redirectTo` — the full URL where the customer should be sent to complete payment

```php
public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
{
    $session = YourProvider::createCheckout([
        'amount' => $amount->raw(),
        'currency' => Str::lower(config('resrv-config.currency_isoCode')),
        'success_url' => $returnUrl ?? $this->getReturnUrl($reservation),
        'cancel_url' => $this->getCancelUrl($reservation),
        'metadata' => ['reservation_id' => $reservation->id],
    ]);

    $result = new \stdClass;
    $result->id = $session->id;
    $result->redirectTo = $session->checkout_url;
    return $result;
}
```

**Important for redirect gateways**: Resrv automatically appends `resrv_gateway=<config_key>` to the `redirectTo` URL before redirecting. You do NOT need to add this yourself. However, your return/success URL should point to the Statamic page that uses the `{{ resrv_checkout_redirect }}` tag. That tag reads `resrv_gateway` from the query string to resolve the correct gateway for `handleRedirectBack()`.

Build that return/success URL from the **4th `$returnUrl` parameter** rather than hard-coding the checkout-complete entry: `$base = $returnUrl ?? $this->getReturnUrl($reservation)`. Resrv passes the checkout-complete entry there during normal checkout (so behavior is unchanged) but a different base for other surfaces such as the manual pay-by-link page. The parameter is part of the interface — see **Step 12**.

---

## Step 3: Implement handleRedirectBack() for Redirect Gateways

**Applies to: redirect gateways only**

When the customer returns from the provider, the `{{ resrv_checkout_redirect }}` Statamic tag calls `handleRedirectBack()` on the correct gateway (resolved via the `resrv_gateway` query parameter).

This method must return an array with a `status` key:

```php
public function handleRedirectBack(): array
{
    // Always check for pending payments first
    if ($pending = $this->handlePaymentPending()) {
        return $pending;
    }

    // Retrieve payment status from your provider using request params
    $sessionId = request()->input('session_id');
    $reservation = Reservation::findByPaymentId($sessionId)->first();

    // No reservation matches this payment_id — it was cleared by Checkout::cancelActiveIntent
    // (customer abandoned, switched gateway, etc.). Falling back to the session reservation here
    // would show a success page for a reservation no webhook will confirm — short-circuit to the
    // failure path instead so manual reconciliation (via the stale-intent log) is the single
    // source of truth.
    if (! $reservation) {
        return [
            'status' => false,
            'reservation' => [],
        ];
    }

    $session = YourProvider::getSession($sessionId);

    if ($session->status === 'paid') {
        return [
            'status' => true,
            'reservation' => $reservation->toArray(),
        ];
    }

    return [
        'status' => false,
        'reservation' => $reservation->toArray(),
    ];
}
```

The return array `status` must be one of:
- `true` — payment succeeded, customer sees the success message
- `false` — payment failed, customer sees the failure message
- `'pending'` — payment is pending (e.g. bank transfer), customer sees the pending message

**Important**: Resrv appends `resrv_gateway=<config_key>` to the redirect URL automatically. Your provider's success/cancel URL does NOT need to include it — Resrv adds it. However, any additional query parameters your provider returns (e.g. `session_id`, `token`) will be available via `request()->input(...)` in `handleRedirectBack()`.

---

## Step 4: Implement verifyPayment() for Webhooks

**Applies to: gateways that support webhooks (supportsWebhooks() returns true)**

When using multiple gateways, configure each provider's webhook URL to include the config key:

```
POST https://yoursite.com/resrv/api/webhook/paypal
POST https://yoursite.com/resrv/api/webhook/stripe
```

The `{gateway}` segment routes the webhook to the correct gateway class. Your `verifyPayment()` method receives the raw `$request` and should:

1. Verify the webhook signature/authenticity
2. Find the reservation via `payment_id`
3. Dispatch `ReservationConfirmed` on success. Leave a failed *attempt* PENDING — a declined card is retryable, so don't cancel it or release the hold.
4. Return a 200 response

```php
public function verifyPayment($request)
{
    // 1. Verify signature
    $payload = $request->getContent();
    $signature = $request->header('X-Provider-Signature');
    if (!$this->verifySignature($payload, $signature)) {
        abort(403);
    }

    // 2. Parse event and find reservation
    $event = json_decode($payload, true);
    $paymentId = $event['data']['payment_id'];
    $reservation = Reservation::findByPaymentId($paymentId)->first();

    if (!$reservation) {
        return response()->json([], 200);
    }

    // 3. Confirm on success. A failed ATTEMPT is retryable, so leave the reservation
    //    PENDING; ExpireReservations reclaims the hold if the customer abandons.
    if ($event['type'] === 'payment.completed') {
        ReservationConfirmed::dispatch($reservation);
    }

    // 4. Respond
    return response()->json([], 200);
}
```

The legacy route (`POST /resrv/api/webhook` without a gateway segment) still works and resolves to the default gateway. Existing single-gateway webhook configurations require zero changes.

> ⚠️ The `ReservationConfirmed::dispatch()` call above is simplified for clarity. A production gateway that captures funds must route the confirmation through `Reservation::transitionTo(CONFIRMED, tolerant: true)` and handle a `false` return (a concurrent expiry that orphans the charge) — see **Step 10**. The bundled Stripe gateway also guards against terminal-state reservations and amount mismatches before confirming.

---

## Step 5: Implement refund()

**Applies to: all gateways**

The `refund()` method is called from the CP when an admin refunds a reservation, and from the customer self-cancellation flow (see **Step 11** for that flow's additional requirements). Resrv uses `PaymentGatewayManager::forReservation($reservation)` to resolve the correct gateway from the reservation's `payment_gateway` column. This means a PayPal reservation is refunded through PayPal, a Stripe reservation through Stripe, etc.

```php
public function refund($reservation)
{
    try {
        $result = YourProvider::refund($reservation->payment_id);
    } catch (\Exception $e) {
        throw new \Reach\StatamicResrv\Exceptions\RefundFailedException($e->getMessage());
    }

    return $result;
}
```

Throw `RefundFailedException` on failure — the CP controller catches it and returns an error response.

---

## Step 6: Create a Custom Blade View (Inline Gateways Only)

**Applies to: inline gateways only. Skip if your gateway uses redirects.**

If your gateway embeds a JS SDK on the checkout page, create a Blade view and return its path from `paymentView()`.

The view is rendered inside the `CheckoutPayment` Livewire component, which provides these properties via `$wire`:

| Property | Type | Description |
|----------|------|-------------|
| `$wire.clientSecret` | string | From `paymentIntent()->client_secret` |
| `$wire.publicKey` | string | From `getPublicKey()` |
| `$wire.checkoutCompletedUrl` | string | URL of the checkout-completed Statamic entry |
| `$amount` | float | Formatted payment amount (available as Blade variable) |

Example view at `resources/views/livewire/paypal-payment.blade.php`:

```blade
<div>
    <div x-data="paypalPayment">
        <div class="my-6 xl:my-8">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.payment') }}
            </div>
        </div>
        <div id="paypal-button-container" x-ref="paypalButtons"></div>
        <p x-show="errors" x-cloak x-transition class="mt-6 text-red-600">
            <span x-html="errors"></span>
        </p>
    </div>
</div>

@script
<script>
Alpine.data('paypalPayment', () => ({
    client_id: $wire.publicKey,
    checkout_completed_url: $wire.checkoutCompletedUrl,
    errors: false,

    init() {
        // Load PayPal JS SDK and render buttons
        // On approval, redirect to this.checkout_completed_url
    },
}));
</script>
@endscript
```

Make the view publishable so users can customize it. The `paymentView()` method should return the namespaced view path (e.g. `'your-package::livewire.paypal-payment'`). Users who publish and override the view continue to use their customized version because Laravel resolves published views first.

---

## Step 7: Register the Gateway in Config

Add your gateway to the application's `config/resrv-config.php`:

```php
'payment_gateways' => [
    'stripe' => [
        'class' => \Reach\StatamicResrv\Http\Payment\StripePaymentGateway::class,
        'label' => 'Credit Card',  // Optional, overrides gateway's label()
    ],
    'paypal' => [
        'class' => \App\Payment\PayPalPaymentGateway::class,
        'label' => 'PayPal',
    ],
],
```

Key rules:
- **The config key** (e.g. `'paypal'`) is what gets stored in the reservation's `payment_gateway` column and used to resolve the gateway for refunds, webhooks, and redirect callbacks. Choose a stable, descriptive slug. Do not change it after reservations have been created with it.
- **The `label`** is optional. If omitted, the gateway's `label()` method provides the default.
- **The first entry** is the default gateway (used as fallback for old reservations with a null `payment_gateway`).
- The legacy `payment_gateway` (singular) key is still used as the fallback when `payment_gateways` is empty. You do NOT need to remove it.

### Optional: Amount limits per gateway

You can disable a gateway outside a given order-value range — for example, hiding "Credit Card" for orders over €1000, or offering "Bank Transfer" only on orders over €500. Both bounds are **inclusive** (`>= min`, `<= max`) and **optional** — omit either to leave that side unbounded:

```php
'payment_gateways' => [
    'stripe' => [
        'class' => \Reach\StatamicResrv\Http\Payment\StripePaymentGateway::class,
        'amount_limits' => ['min' => 10, 'max' => 1000],
    ],
    'bank_transfer' => [
        'class' => \Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway::class,
        'amount_limits' => ['min' => 500], // large orders only
    ],
],
```

**What gets compared:** the reservation's `payment` amount (what's payable now, before any gateway surcharge). A gateway that fails the check is hidden from the picker entirely.

**This follows the same per-gateway config pattern as `surcharge`** — your gateway class needs no changes. `amount_limits` is purely a config-time concern handled by `PaymentGatewayManager`, so this setting applies to any `PaymentInterface` implementation including custom ones you write following this guide.

**Edge cases to be aware of:**
- If every configured gateway fails the check, the checkout shows an error at step 2 and cannot advance. Configure at least one gateway that accepts the amounts you expect.
- If a single surviving gateway clears the filter, it auto-selects without showing a picker.
- Free reservations (`payment = 0`) bypass the gateway picker entirely, so `amount_limits` is never evaluated for them.
- A coupon that drops `payment` below the currently-selected gateway's `min` does **not** retroactively invalidate the in-flight payment intent — `amount_limits` gates selection, not processing.
- Surcharge is **not** included in the comparison. Including it would be circular (surcharge depends on gateway, which would then depend on surcharge).
- If `min > max` in the same gateway's config, `PaymentGatewayManager` throws `InvalidArgumentException` at boot so misconfiguration fails loudly rather than silently hiding the gateway forever.

---

## Step 8: Configure Provider Webhooks

For each gateway, configure the webhook URL in the provider's dashboard:

| Gateway | Webhook URL |
|---------|-------------|
| Stripe | `https://yoursite.com/resrv/api/webhook/stripe` |
| PayPal | `https://yoursite.com/resrv/api/webhook/paypal` |

The segment after `/webhook/` must match the config key from Step 7.

The legacy route `https://yoursite.com/resrv/api/webhook` (without a segment) continues to work for single-gateway setups or as the default gateway's webhook.

---

## Complete Gateway Skeleton

Here is a complete skeleton for a redirect-based gateway (like PayPal or Mollie):

```php
<?php

namespace App\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;

class PayPalPaymentGateway implements PaymentInterface
{
    // --- Identity methods (new) ---

    public function name(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function paymentView(): string
    {
        // Redirect gateway — this view is never rendered
        return 'statamic-resrv::livewire.checkout-payment';
    }

    public function supportsManualConfirmation(): bool
    {
        return false; // Provider-verified gateway
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true; // refund() moves money through the provider's API (see Step 11)
    }

    // --- Payment flow ---

    public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        // TODO: Create a checkout session with PayPal's API
        // $amount->raw() gives the amount as a numeric string in minor units (cents)
        // config('resrv-config.currency_isoCode') gives the currency code

        // $returnUrl is the base to return the customer to. Falls back to the checkout-complete
        // entry when null (a caller that doesn't set a base). Normal checkout passes the
        // checkout-complete entry here, so the fallback is only exercised for null. See Step 12.
        $returnBase = $returnUrl ?? $this->getReturnUrl($reservation);

        $result = new \stdClass;
        $result->id = 'PAYPAL_SESSION_ID';      // stored as reservation.payment_id
        $result->redirectTo = 'https://paypal.com/checkout/...'; // customer is sent here
        return $result;
    }

    public function redirectsForPayment(): bool
    {
        return true;
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        // TODO: Check payment status using query params from PayPal's redirect
        // The request also contains resrv_gateway=<config_key> (added by Resrv)
        $token = request()->input('token');

        // TODO: Verify payment with PayPal API using $token
        $paid = true; // replace with actual check

        $reservation = Reservation::findByPaymentId($token)->first();

        if ($paid) {
            return [
                'status' => true,
                'reservation' => $reservation ? $reservation->toArray() : [],
            ];
        }

        return [
            'status' => false,
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    // --- Webhooks ---

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function verifyWebhook()
    {
        return true;
    }

    public function verifyPayment($request)
    {
        // TODO: Verify webhook signature
        // TODO: Parse payload, find reservation, dispatch event
        $payload = json_decode($request->getContent(), true);

        $reservation = Reservation::findByPaymentId($payload['resource']['id'])->first();

        if (! $reservation) {
            return response()->json([], 200);
        }

        // A denied capture is a failed attempt — leave the reservation PENDING (retryable).
        if ($payload['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            ReservationConfirmed::dispatch($reservation);
        }

        return response()->json([], 200);
    }

    // --- Refund ---

    public function refund($reservation)
    {
        // TODO: Call PayPal refund API using $reservation->payment_id
        try {
            // PayPalApi::refund($reservation->payment_id);
        } catch (\Exception $e) {
            throw new RefundFailedException($e->getMessage());
        }

        return true;
    }

    // --- Keys (may not apply to all providers) ---

    public function getPublicKey($reservation)
    {
        return config('resrv-config.paypal_client_id');
    }

    public function getSecretKey($reservation)
    {
        return config('resrv-config.paypal_secret');
    }

    public function getWebhookSecret($reservation)
    {
        return config('resrv-config.paypal_webhook_id');
    }
}
```

And here is a complete skeleton for an inline gateway (like Square or Braintree):

```php
<?php

namespace App\Payment;

use Illuminate\Support\Str;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;

class SquarePaymentGateway implements PaymentInterface
{
    // --- Identity methods (new) ---

    public function name(): string
    {
        return 'square';
    }

    public function label(): string
    {
        return 'Credit Card (Square)';
    }

    public function paymentView(): string
    {
        // Return a custom Blade view that embeds Square's Web Payments SDK
        return 'your-package::livewire.square-payment';
    }

    public function supportsManualConfirmation(): bool
    {
        return false; // Provider-verified gateway
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true; // refund() moves money through the provider's API (see Step 11)
    }

    // --- Payment flow ---

    public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        // Inline gateway: the customer returns through Square's embedded SDK, so $returnUrl is
        // ignored — the 4th param is required by the interface but unused here (see Step 12).
        // TODO: Create a payment with Square's API
        $result = new \stdClass;
        $result->id = 'SQUARE_PAYMENT_ID';         // stored as reservation.payment_id
        $result->client_secret = 'SQUARE_TOKEN';    // passed to frontend JS
        return $result;
    }

    public function redirectsForPayment(): bool
    {
        return false; // renders inline
    }

    public function handleRedirectBack(): array
    {
        // Inline gateways typically handle confirmation via webhooks,
        // but this method is still called on the checkout-completed page.
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $paymentId = request()->input('payment_id');
        $reservation = Reservation::findByPaymentId($paymentId)->first();

        // TODO: Verify payment status with Square API
        $succeeded = true; // replace with actual check

        return [
            'status' => $succeeded,
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    // --- Webhooks ---

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function verifyWebhook()
    {
        return true;
    }

    public function verifyPayment($request)
    {
        // TODO: Verify signature, parse, find reservation, dispatch event
        return response()->json([], 200);
    }

    // --- Refund ---

    public function refund($reservation)
    {
        try {
            // TODO: Call Square refund API
        } catch (\Exception $e) {
            throw new RefundFailedException($e->getMessage());
        }

        return true;
    }

    // --- Keys ---

    public function getPublicKey($reservation)
    {
        return config('resrv-config.square_application_id');
    }

    public function getSecretKey($reservation)
    {
        return config('resrv-config.square_access_token');
    }

    public function getWebhookSecret($reservation)
    {
        return config('resrv-config.square_webhook_signature_key');
    }
}
```

---

## How the Checkout Flow Works (Architecture Reference)

Understanding the flow helps when debugging custom gateways.

### Single gateway (backward-compatible)

1. Customer fills extras/options (step 1) and personal details (step 2).
2. `handleSecondStep()` detects one gateway, auto-selects it, calls `initializePayment()`.
3. `initializePayment()` saves the config key to `reservation.payment_gateway`, calls `paymentIntent()`, saves `payment_id`.
4. For inline: sets `clientSecret`, `publicKey`, `paymentView`, renders step 3.
5. For redirect: returns `redirect()->away(...)` with `resrv_gateway` appended.

### Multiple gateways

1. Steps 1 and 2 are identical.
2. `handleSecondStep()` detects multiple gateways, sets step 3 without selecting one.
3. Step 3 renders the gateway picker (`checkout-gateway-picker` component).
4. Customer clicks a gateway, dispatching the `gateway-selected` Livewire event.
5. `selectGateway()` sets `selectedGateway` and calls `initializePayment()`, returning its result (critical for redirects).
6. From here, flow continues the same as single gateway (step 4 or 5 above).

### Redirect return flow

1. Customer completes payment on the provider's site.
2. Provider redirects back to the checkout-completed Statamic page.
3. The `{{ resrv_checkout_redirect }}` tag reads `resrv_gateway` from the URL query.
4. If present, resolves the gateway via `PaymentGatewayManager::gateway($name)`.
5. Calls `handleRedirectBack()` on that gateway to check payment status.
6. Renders success, failure, or pending message.

### Webhook flow

1. Provider sends POST to `/resrv/api/webhook/{config_key}`.
2. `WebhookController::store()` resolves the gateway from the URL segment.
3. Calls `verifyPayment($request)` on that gateway.
4. Gateway verifies signature, finds reservation, and dispatches `ReservationConfirmed` on success (a failed attempt stays PENDING for retry).

### Refund flow

1. Admin clicks refund in the CP.
2. `ReservationCpController::refund()` loads the reservation.
3. Calls `PaymentGatewayManager::forReservation($reservation)` which reads `reservation.payment_gateway` and resolves the correct gateway.
4. Calls `refund($reservation)` on that gateway.

---

## Checklist

Before deploying your custom gateway:

- [ ] Class implements `Reach\StatamicResrv\Http\Payment\PaymentInterface`
- [ ] `name()` returns a unique lowercase slug
- [ ] `label()` returns a human-readable string
- [ ] `paymentView()` returns a valid Blade view path (or the default for redirect gateways)
- [ ] `supportsManualConfirmation()` returns `true` only for offline/non-provider-verified gateways, `false` for all others
- [ ] `paymentIntent()` returns an object with `->id` and either `->client_secret` (inline) or `->redirectTo` (redirect)
- [ ] `redirectsForPayment()` returns the correct boolean for your gateway type
- [ ] `handleRedirectBack()` checks `handlePaymentPending()` first, then returns the correct status array
- [ ] `verifyPayment()` verifies the webhook signature and dispatches `ReservationConfirmed` on success (a failed attempt stays PENDING for retry)
- [ ] `verifyPayment()` routes the success state change through `transitionTo(CONFIRMED, tolerant: true)`, gates `ReservationConfirmed` on its return value, and re-reads on `false` to surface orphaned charges (see Step 10)
- [ ] `refund()` throws `RefundFailedException` for **every** provider failure mode — catch the SDK's base exception class, not just invalid-request errors (see Step 11)
- [ ] `refund()` sends a stable idempotency key when the provider supports one (see Step 11)
- [ ] `supportsAutomaticRefunds()` returns `true` only when `refund()` actually moves money through the provider's API, `false` for out-of-band collection (see Step 11)
- [ ] Gateway is registered in `config/resrv-config.php` under `payment_gateways`
- [ ] Webhook URL configured in provider's dashboard with the correct config key segment
- [ ] `cancelPaymentIntent()` cancels or voids an intent at the provider (see Step 9)
- [ ] `retrievePaymentIntent()` returns the provider's intent as an object exposing `->status`, returns `null` **only** when the intent is definitively gone, and **throws** on transient failures rather than returning `null` (see Step 13)
- [ ] For redirect gateways: `retrievePaymentIntent()` also exposes `->redirectTo` on still-payable intents so an interrupted payment can resume (see Step 13)
- [ ] For inline gateways: custom Blade view works and is publishable
- [ ] For redirect gateways: return URL points to the Statamic page with `{{ resrv_checkout_redirect }}`
- [ ] `paymentIntent()` declares the 4th `?string $returnUrl = null` parameter — the interface requires it, so a three-parameter declaration fatals at boot (see Step 12)
- [ ] For redirect gateways: `paymentIntent()` builds the return/success URL from `$returnUrl ?? <checkout-complete entry>` with a **separator-aware** query append — `$returnUrl` may already carry `?ref=…&hash=…` (see Step 12)

---

## Step 9: Implement cancelPaymentIntent()

**Applies to: all gateways**

`PaymentInterface` now requires a `cancelPaymentIntent()` method. Resrv calls this whenever a customer abandons a payment intent that was already created on the provider — for example, when they click back from step 3, switch payment gateways at the picker, or when a stale intent is detected on a fresh pass through the second step.

Without this method, abandoned intents stay alive on the provider side. For redirect/3DS gateways this is a correctness issue: the customer can still complete the original intent (by re-opening a redirect URL, finishing a 3DS challenge from another tab, etc.) and the resulting webhook will confirm a reservation whose stored amount no longer matches what was actually charged.

### Signature

```php
public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void;
```

- `$paymentId` — the provider-side intent/session identifier that was originally returned from `paymentIntent()->id`.
- `$reservation` — the reservation the intent belonged to. Useful for resolving per-collection API keys via `getSecretKey($reservation)`.

The method returns nothing. Exceptions you throw are logged and swallowed by Resrv — by the time Resrv calls you, it has already cleared `reservation.payment_id` in the database, so a failed cancellation only leaves an orphan on the provider side, not inconsistent Resrv state.

### Reference: StripePaymentGateway

```php
public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
{
    Stripe::setApiKey($this->getSecretKey($reservation));

    try {
        $intent = PaymentIntent::retrieve($paymentId);

        if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture'], true)) {
            $intent->cancel();
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        Log::warning('Failed to cancel Stripe payment intent: '.$e->getMessage(), [
            'payment_id' => $paymentId,
            'reservation_id' => $reservation->id,
        ]);
    }
}
```

Key points:
- Only cancel intents in a cancellable state. Each provider has a set of statuses from which cancellation is allowed; trying to cancel a `succeeded` or already-`canceled` intent will usually throw.
- Swallow provider-side errors (log them, but don't re-throw). The intent may have completed a fraction of a second before the cancel call, or the provider may be transiently unreachable — neither should block the customer from proceeding with a new intent.
- **Don't try to "rescue" a succeeded intent here.** Resrv has already cleared `payment_id` by the time you're called, and the customer's UI has moved on. If a race causes the provider to confirm an intent you're trying to cancel, the recovery path lives in `verifyPayment()` — see below.

### Recommended: Handle stale-intent races in verifyPayment()

Because Resrv clears `payment_id` *before* calling `cancelPaymentIntent()`, a webhook that arrives after the cancellation request was issued (but before the provider actually applied it) can no longer find the reservation via `findByPaymentId`. The bundled Stripe gateway handles this by stashing `reservation_id` in the intent metadata when the intent is created, then falling back to it in `verifyPayment()`:

```php
public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
{
    return YourProvider::createIntent([
        'amount' => $amount->raw(),
        'metadata' => [
            'reservation_id' => $reservation->id, // <-- critical for the stale-intent fallback below
        ],
        // ...
    ]);
}

public function verifyPayment($request)
{
    $payload = json_decode($request->getContent(), true);
    $data = $payload['data']['object'];

    $reservation = Reservation::findByPaymentId($data['id'])->first();

    // Stale-intent fallback: payment_id was cleared by Checkout::cancelActiveIntent before
    // the provider's cancellation took effect. Use the metadata stashed at creation time
    // so the charge isn't lost.
    $isStaleIntent = false;
    if (! $reservation && isset($data['metadata']['reservation_id'])) {
        $reservation = Reservation::find($data['metadata']['reservation_id']);
        $isStaleIntent = (bool) $reservation;
    }

    if (! $reservation) {
        return response()->json([], 200);
    }

    // ... signature verification, etc ...

    if ($eventType === 'succeeded') {
        if ($isStaleIntent) {
            // The customer moved on (refresh, back, gateway switch, coupon change) before
            // this webhook arrived. The charge exists at the provider but no longer matches
            // the reservation's current state — confirming it would send emails / decrease
            // inventory against an amount or gateway the reservation is no longer tied to.
            // Log for manual reconciliation and DO NOT dispatch ReservationConfirmed.
            Log::warning('Payment intent succeeded after being abandoned by the customer — manual reconciliation may be required.', [
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $data['id'],
                'current_payment_id' => $reservation->payment_id,
                'current_payment_gateway' => $reservation->payment_gateway,
            ]);

            return response()->json([], 200);
        }

        ReservationConfirmed::dispatch($reservation);
    }

    // A failed ATTEMPT is retryable: do nothing, leaving the reservation PENDING so the customer
    // can retry the same intent (ExpireReservations reclaims the hold if abandoned).

    if ($eventType === 'canceled' && ! $isStaleIntent) {
        // A genuinely-canceled intent is dead — expire (EXPIRED, not REFUNDED: no money moved).
        // Stale intents were cancelled by us, so skip them. expire() no-ops if not PENDING.
        $reservation->expire();
    }

    return response()->json([], 200);
}
```

The "log and ignore stale success" policy is intentional. It guarantees that Resrv never confirms a reservation against an amount or gateway the customer has since walked away from — at the cost of requiring you to manually reconcile the orphan charge at the provider. Resrv ships this trade-off for Stripe; you should adopt the same trade-off for any provider that supports asynchronous confirmation.

### Reference: PayPal / redirect gateway skeleton

```php
public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
{
    try {
        // PayPal REST API: POST /v2/checkout/orders/{id}/void (for authorized but not captured orders)
        // Mollie:          $mollie->payments->get($paymentId)->cancel() if it supports cancellation
        // Adyen:           POST /cancels with originalReference=$paymentId
        //
        // Best-effort only: if the order has already been captured, the provider will throw —
        // catch it below and rely on the stale-intent fallback in verifyPayment() to handle the race.
        YourProvider::cancelOrder($paymentId);
    } catch (\Throwable $e) {
        Log::warning('Failed to cancel provider intent: '.$e->getMessage(), [
            'payment_id' => $paymentId,
            'reservation_id' => $reservation->id,
        ]);
    }
}
```

### Gateways where cancellation is a no-op

If your gateway never creates a provider-side resource that needs cleanup — for example, offline / bank-transfer gateways that just record an identifier locally, or a test/fake gateway — return early with no work:

```php
public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
{
    //
}
```

`OfflinePaymentGateway` and `FakePaymentGateway` both use this pattern.

### When Resrv calls you

1. **Customer navigates back from step 3 to step 1 or 2** (`Checkout::goToStep` → `resetPaymentState`).
2. **Customer switches gateway at the picker on step 3** (`Checkout::selectGateway` → `cancelActiveIntent` before creating the new intent).
3. **Customer re-enters step 2 with a stale intent still persisted** from a previous session/pass (`Checkout::handleSecondStep` → `resetPaymentState`).
4. **Customer reopens a time-expired PENDING checkout** (`Checkout::mount` → `cancelActiveIntent`).

In all cases, Resrv:
1. Reads `payment_id` and `payment_gateway` from a fresh reservation record.
2. Clears `payment_id` in the database *first* — so any cancellation webhook your provider fires in response can no longer look up the reservation and cannot accidentally mark it cancelled.
3. Calls `cancelPaymentIntent($oldPaymentId, $reservation)`.
4. Continues with whatever action triggered the reset (showing the gateway picker, creating a new intent for a different gateway, etc.).

### Why this matters for setups without a surcharge

The cancellation path runs whenever `payment_id` is non-empty — it is **not** gated on whether a surcharge was applied. Even before the surcharge feature, abandoned intents on single-gateway setups were being silently left behind. Implementing `cancelPaymentIntent()` fixes that regardless of surcharge configuration.

---

## Step 10: Re-read After a Skipped Confirm Transition (Orphan-Payment Race)

**Applies to: gateways that confirm via webhook and capture funds (provider-verified gateways)**

> 📅 Added 2026-06-02. If you wrote a custom gateway before this date, audit its `verifyPayment()` against this step. Earlier revisions of this guide dispatched `ReservationConfirmed` directly on a successful webhook, which is vulnerable to the race described below.

Step 9's stale-intent fallback handles a charge whose `payment_id` was already cleared. A second, subtler race remains even for a *live* intent: `ExpireReservations` runs on every availability search, and it can flip a still-`PENDING` reservation to `EXPIRED` in the window between your webhook's in-memory pre-checks and the moment it actually writes the confirmation.

Resrv's reservation state changes go through `Reservation::transitionTo()`, which re-reads the row under a `lockForUpdate` and — when called with `tolerant: true` — returns `false` (instead of throwing) if the row moved to an incompatible state under the lock. Your webhook's success path should go through this method and **gate `ReservationConfirmed` on its return value** (this replaces the bare `ReservationConfirmed::dispatch()` shown in the simplified examples in Steps 4 and 9):

```php
if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
    ReservationConfirmed::dispatch($reservation);

    return response()->json([], 200);
}
```

If you stop there, a `false` return is treated as a clean no-op and you still return `200` — but the customer was already charged. The provider will not retry, the hold has been released, and nobody is told. **Re-read the row on `false` and surface a terminal state as an orphaned charge:**

```php
// The transition was skipped because the row changed under the lock after the pre-checks
// (e.g. ExpireReservations expired it). A concurrent CONFIRMED is a clean no-op, but a terminal
// state means the captured charge is now orphaned — surface it for manual refund.
$reservation->refresh();

if (in_array($reservation->status, [
    ReservationStatus::EXPIRED->value,
    ReservationStatus::REFUNDED->value,
    ReservationStatus::PARTNER->value,
], true)) {
    Log::warning('Succeeded webhook lost the confirmation race to a terminal transition — manual refund likely required.', [
        'reservation_id' => $reservation->id,
        'reservation_status' => $reservation->status,
        'payment_intent_id' => $data['id'],
    ]);

    // Notify admins for manual reconciliation. Safe to call from any gateway; it dedupes on
    // (reservation, payment intent) so provider retries don't spam admins.
    \Reach\StatamicResrv\Mail\OrphanedPaymentNotification::dispatchFor($reservation, $data['id'], $event->id ?? null);
}

return response()->json([], 200);
```

This is the same trade-off as Step 9's stale-success policy: return `200` so the provider stops retrying, and rely on a logged/notified orphan for manual reconciliation rather than confirming a reservation whose hold is gone. The bundled `StripePaymentGateway` and `FakePaymentGateway` both do exactly this, and Resrv's webhook regression tests cover the race.

> **Manual-confirmation gateways** (`supportsManualConfirmation() === true`, e.g. offline/bank-transfer) confirm through Resrv's built-in `CheckoutPayment::confirmPayment()`, which already performs this re-read and shows the customer an "expired" error instead of a false success page. You do **not** need to add anything for that path — it lives in Resrv core, not your gateway.

---

## Step 11: Implement supportsAutomaticRefunds() and Harden refund() for Customer Self-Cancellation

**Applies to: all gateways**

> 📅 Added 2026-06-10 alongside customer self-cancellation. `supportsAutomaticRefunds()` is a required interface method — a gateway written before this date will fatal at boot until it is implemented. If you wrote a custom gateway earlier, also audit its `refund()` against the exception-coverage and idempotency notes below.

### Background

Resrv now lets customers cancel their own reservations from the reservation-status page, within the booking's free-cancellation window. A successful cancellation transitions the reservation to `REFUNDED` and tells the customer their money has been returned to their original payment method. That promise is only true if `refund()` actually moves money through the provider's API — so the interface now asks your gateway to declare it.

### Implement supportsAutomaticRefunds()

```php
/**
 * Whether refund() actually returns money through the provider's API.
 * Gateways that collect payment out of band (bank transfer, pay at
 * premises) must return false so automated flows never mark a
 * reservation refunded without money moving.
 */
public function supportsAutomaticRefunds(): bool
{
    return true; // provider-backed gateways (Stripe, PayPal, Mollie, Square, ...)
}
```

Return `false` only when your `refund()` is a bookkeeping no-op. The bundled `OfflinePaymentGateway` returns `false`; `StripePaymentGateway` and `FakePaymentGateway` return `true`.

What the flag gates:

- **`false`** — reservations paid through your gateway cannot be self-cancelled by the customer: the cancel button is hidden on the reservation-status page, and the server-side guard rejects the attempt with the "cannot be cancelled online, please contact us" message. The **CP refund flow is unchanged** — an admin can still mark such a reservation refunded after returning the money manually.
- **`true`** — eligible reservations show the cancel button, and a customer cancellation calls your `refund()`.

Reservations where no charge ever reached a gateway (partner / zero-payment bookings, `payment_id === ''`) skip the gateway entirely and can always be self-cancelled — your flag is never consulted for them.

### Wrap every provider failure in RefundFailedException

`refund()` now runs in a customer-facing request, and it executes **inside the `REFUNDED` status transition's database transaction** (a row lock is held): a throw rolls the status back, so money is never marked returned when it wasn't.

`RefundFailedException` is the contract for "the provider refused or could not process the refund". Catch your provider SDK's **whole exception hierarchy** — connection failures, authentication errors, and rate limits included, not just invalid-request errors:

```php
public function refund($reservation)
{
    try {
        return YourProvider::refund($reservation->payment_id);
    } catch (YourProviderApiException $e) { // the SDK's BASE exception class
        throw new RefundFailedException($e->getMessage());
    }
}
```

Anything else that escapes is treated as an unexpected error: the customer still gets a generic failure message instead of a 500 (Resrv catches and reports it), but it lands in the exception handler as a bug rather than being logged as a known refund failure. The bundled Stripe gateway catches `ApiErrorException` — the base class of every Stripe API exception — for exactly this reason.

Keep `refund()` down to the single provider call: the row lock is held while it runs, so don't add unrelated I/O.

### Recommended: send a stable idempotency key

If the connection drops *after* the provider processed the refund, Resrv rolls the status back to `confirmed` — but the money has moved. On retry, a naive refund call then fails forever ("already refunded"), leaving the reservation impossible to reconcile from Resrv. If your provider supports idempotency keys, derive a stable one from the reservation and intent so a retry replays the original success and the transition completes:

```php
$client->refunds->create(
    [
        'payment_intent' => $reservation->payment_id,
    ],
    [
        'idempotency_key' => 'resrv-refund-'.$reservation->id.'-'.$reservation->payment_id,
    ]
);
```

The bundled Stripe gateway uses exactly this key shape. Include the intent id, not just the reservation id, so a reservation that legitimately creates a second intent (abandoned checkout, gateway switch) never replays a stale request.

### Customer cancellation flow (architecture reference)

1. The customer looks up their reservation on the reservation-status page (email + booking reference, or an emailed deep link).
2. A cancel button shows only when the booking is live, inside its free-cancellation window, **and** either its gateway `supportsAutomaticRefunds()` or no charge ever reached a gateway.
3. Cancelling calls `ReservationRefundProcessor::cancelByCustomer()`, which runs your `refund()` inside the `REFUNDED` transition's transaction.
4. A `RefundFailedException` (or any other throw) rolls the transition back; the customer sees a generic "contact us" message and the failure is logged.
5. On success the customer is told the refunded amount — `payment + payment_surcharge`, i.e. exactly what was charged on the intent. Refunding the **full** intent (as the examples above do) is therefore the correct behavior; partial refunds would contradict the email.

---

## Step 12: Accept the `$returnUrl` Parameter in `paymentIntent()`

**Applies to: all gateways (only redirect gateways act on it)**

> 📅 Added 2026-07-06 alongside admin-created (manual) reservations, originally as an optional positional argument. **Required since 2026-07-11**: `PaymentInterface::paymentIntent()` now declares the 4th parameter, so a gateway that still declares only three parameters fatals at boot (*"Declaration must be compatible"*) until the parameter is added. This is deliberate, for the same reason `retrievePaymentIntent()` is required (Step 13): a redirect gateway that silently ignored the argument would strand pay-by-link customers on the checkout-complete page after paying — a broken return leg with no error anywhere.

### Why

A redirect gateway bakes its own return URL into the provider payment inside `paymentIntent()` — historically always the shared checkout-complete entry (the page with `{{ resrv_checkout_redirect }}`). That is correct for the normal checkout, but Resrv now collects payment from other surfaces too: the **manual-reservation pay-by-link page** sends the customer to an authenticated per-reservation URL, not the checkout-complete entry. Resrv therefore needs to tell the gateway *which* base URL to return the customer to for a given payment — and the gateway must honour it, which is why the parameter lives on the interface rather than being sniffed or silently dropped.

### The signature

The interface declares `?string $returnUrl = null` as the 4th parameter; every implementation must declare it too:

```php
public function paymentIntent($amount, Reservation $reservation, $data, ?string $returnUrl = null)
```

`$returnUrl` is the **base URL** you should append your own return parameters (`id`, `resrv_gateway`) to when building the provider's success/return URL. When it is `null` (a caller that doesn't set it), fall back to the checkout-complete entry exactly as before — so normal checkout stays byte-identical.

### Before / after

Wherever your `paymentIntent()` builds the provider's success/return URL, prefer `$returnUrl` over the checkout-complete entry:

```php
// Before — always returns to the checkout-complete entry
protected function buildReturnUrl(Reservation $reservation): string
{
    $base = $this->getCheckoutCompleteEntry()->absoluteUrl();

    return $base.'?'.http_build_query([
        'id' => $reservation->payment_id,
        'resrv_gateway' => $reservation->payment_gateway,
    ]);
}

// After — honours the caller's base, falls back to checkout-complete when absent
protected function buildReturnUrl(Reservation $reservation, ?string $returnUrl = null): string
{
    $base = $returnUrl ?? $this->getCheckoutCompleteEntry()->absoluteUrl();

    $separator = str_contains($base, '?') ? '&' : '?';

    return $base.$separator.http_build_query([
        'id' => $reservation->payment_id,
        'resrv_gateway' => $reservation->payment_gateway,
    ]);
}
```

`getCheckoutCompleteEntry()->absoluteUrl()` above stands for however your gateway already resolves the checkout-complete entry today (e.g. `\Statamic\Facades\Entry::find(config('resrv-config.checkout_completed_entry'))->absoluteUrl()`). In normal checkout Resrv passes that same checkout-complete base as `$returnUrl`, so the resolved URL is identical either way — the fallback only covers a `null` from callers that don't set a base.

> ⚠️ **`$returnUrl` may already carry a query string.** The pay-by-link page's return URL ends in its `?ref=…&hash=…` authentication pair — those parameters are how the return page identifies the customer. Appending a bare `'?'` (as the historical checkout-only pattern did) produces a malformed double-`?` URL and breaks the customer's return leg. Always use the separator-aware append shown above, exactly as Resrv's own surfaces do.

> ℹ️ **You may find `resrv_gateway` already on the base.** For redirect gateways the pay-by-link surface bakes the `resrv_gateway` marker into `$returnUrl` before calling `paymentIntent()` (its return page keys off the marker's presence and cannot depend on every gateway appending it). Keep appending your own parameters as shown above regardless — a duplicated `resrv_gateway` key is harmless, and normal checkout still relies on your append.

### What you get for free

Your existing `handleRedirectBack()` needs no change. The manual pay-by-link page (`ReservationPayment`) calls it on return — resolving your gateway from the reservation's stored `payment_gateway` (via `PaymentGatewayManager::forReservation()`), triggered by the presence of the `resrv_gateway` return marker — and maps its `status` to an interim *processing* / *retry* message. As always, the **webhook remains the source of truth**: the redirect-back only drives interim messaging and never confirms the reservation.

### Inline gateways

Inline gateways (`redirectsForPayment()` returns `false`) return the customer through the embedded SDK, whose redirect target Resrv sets separately, so they can ignore `$returnUrl` entirely — but they must still declare the parameter, because the interface requires it. The bundled `StripePaymentGateway`, `FakePaymentGateway`, and `OfflinePaymentGateway` all declare it and do nothing with it.

---

## Step 13: Implement `retrievePaymentIntent()`

**Applies to: all gateways**

> 📅 Added 2026-07-06 alongside admin-created (manual) reservations. `retrievePaymentIntent()` is a **required** interface method — a gateway written before this date will fatal at boot until it is implemented, exactly like the 4th `paymentIntent()` parameter in Step 12. (This is deliberate — see *Why a required method, not a shim* below.)

### Why

Resrv must be able to read the *current* state of a previously created intent, for two money-safety guarantees:

1. **Resume instead of double-charge.** When a customer returns to an interrupted payment (the checkout payment step, or the manual pay-by-link page), Resrv re-reads the stored intent rather than blindly minting a second one. A succeeded or in-flight intent means "money is already moving — do not create another"; a dead one may be replaced.
2. **Reconcile an out-of-band confirmation.** When an admin marks a manual reservation paid in person / by transfer, Resrv reads the customer's leftover intent to decide whether to void-and-forget it (never charged) or keep the reference (already capturing real money), so it never strands a charge or drops a live one.

Both callers branch on the returned `->status`, so the method's *semantics* matter as much as its presence — a stub that always returns `null` would defeat both guarantees (it would mint duplicate intents and discard references to live charges).

### The signature

```php
public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
```

- Return an **object exposing `->status`** (the provider's intent/charge object, or a small adapter).
- Return **`null` only when the intent is definitively gone** — deleted, or never existed (e.g. Stripe's `resource_missing` / HTTP 404). `null` tells Resrv it is safe to mint a replacement.
- On **any transient failure** (timeout, 429, 5xx, auth, connection) **throw** — do not return `null`. Returning `null` on a brownout would let Resrv replace a still-live intent with a second chargeable one, or clear the reference to a charge that later captures.

### Status vocabulary

Resrv reads `->status` against the canonical Stripe-style vocabulary:

- `succeeded`, `processing`, `requires_capture` → money is captured, settling, or held. Resrv will **not** mint another intent and will **keep** the reference. One exception: when an admin confirms a manual reservation as paid out of band, a `requires_capture` hold is still only an authorization, so Resrv **voids** it through `cancelPaymentIntent()` (verifying afterwards) instead of leaving it open to capture a duplicate payment.
- `canceled` → dead. Resrv treats it as replaceable / safe to clear.
- any other value (e.g. `requires_payment_method`, `requires_confirmation`, `requires_action`) → still payable and resumable.

If your provider uses different status names, map them to these strings on the object you return (or expose a `->status` property that already uses them) so Resrv's resume/reconcile logic reads them correctly.

### Redirect gateways: include `->redirectTo` on still-payable intents

When Resrv resumes a still-payable intent on a **redirect** gateway (`redirectsForPayment()` returns `true`), it forwards the customer to the resumed intent's `->redirectTo` URL — so include the provider's hosted-payment URL on the object you return whenever the intent is still payable. If `->redirectTo` is absent, Resrv treats the resumed intent as unmountable: it voids the intent and mints a replacement through `paymentIntent()`. That degrades safely (the customer still reaches the provider), but it burns an intent on every resume; returning `->redirectTo` gives an interrupted customer a seamless retry.

**Inline** gateways follow the same rule for `->client_secret`: Resrv re-mounts a resumed inline intent's payment view around `$intent->client_secret`, so include it whenever the intent is still payable. If it is absent, Resrv voids the intent and mints a replacement the same way — never rendering a payment form with an empty secret.

In both void-and-remint cases the superseded intent's credentials were already handed to the customer (an earlier tab or the provider's hosted page can still complete it), so Resrv **verifies the void** before minting: it calls `cancelPaymentIntent()`, then re-reads the intent through `retrievePaymentIntent()` and only mints when the provider reports it dead (`canceled`, or `null`). A read that still shows the intent payable aborts the attempt with a retryable error, and one that shows the money already moving surfaces the processing state — never two chargeable intents for one reservation. For this to work, your `retrievePaymentIntent()` must report `canceled` for an intent your `cancelPaymentIntent()` just voided.

### Reference: StripePaymentGateway (inline)

```php
public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
{
    try {
        return $this->getClient($reservation)->paymentIntents->retrieve($paymentId);
    } catch (InvalidRequestException $e) {
        // Only a definitely-gone intent may be replaced with a fresh one. Every transient
        // failure must propagate so a brownout on this read can't orphan a live intent.
        if ($e->getError()?->code === 'resource_missing' || $e->getHttpStatus() === 404) {
            return null;
        }

        throw $e;
    }
}
```

### Gateways with no retrievable intents

An offline gateway (bank transfer / pay-on-arrival) never creates a provider intent, so it has nothing to retrieve — return `null`:

```php
public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
{
    // Offline payments have no remote intent to resume.
    return null;
}
```

This is safe because offline gateways also `supportsManualConfirmation()`: Resrv reconciles them through the manual-confirmation branch and never relies on a retrieved status for them.

### Why a required method, not a shim

You may wonder why Resrv doesn't guard the call sites with `method_exists()` or an optional companion interface so older gateways keep booting. Both call sites depend on the method's money-safety *semantics*, not merely its presence: a missing-method default that returned `null` would make the resume path always mint a fresh intent (the double-charge this method exists to prevent) and make the out-of-band reconciliation drop the reference to a possibly-captured charge (a silently stranded charge). A boot-time fatal is the *safe* failure mode — it forces the gateway author to implement correct behavior before the gateway can process a payment, rather than degrading silently in production. This mirrors how `cancelPaymentIntent()` (Step 9) and `supportsAutomaticRefunds()` (Step 11) were introduced.
