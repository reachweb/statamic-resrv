# Upgrading Custom Payment Gateways for Resrv Multiple Payment Methods

This document provides step-by-step instructions for updating a custom payment gateway class (e.g. PayPal, Mollie, Square) to work with Resrv's multiple payment methods system.

## Background

Resrv now supports multiple concurrent payment gateways. The `PaymentInterface` has five new required methods (`name()`, `label()`, `paymentView()`, `supportsManualConfirmation()`, and `cancelPaymentIntent()` — the first four are covered in Step 1, and `cancelPaymentIntent()` is covered in Step 9). Gateways are registered in `config/resrv-config.php` under the `payment_gateways` key. During checkout, customers pick a gateway from a list, and the selected config key is stored on the reservation's `payment_gateway` column. Webhooks, refunds, and redirect callbacks all resolve the correct gateway using that stored key.

There are two gateway types:
- **Inline gateways** (e.g. Stripe) — render a payment form directly on the checkout page via a Blade view. `redirectsForPayment()` returns `false`.
- **Redirect gateways** (e.g. PayPal, Mollie) — redirect the customer to the provider's hosted page. `redirectsForPayment()` returns `true`.

Both types follow the same interface. The sections below are marked with which type they apply to.

---

## Step 1: Add the Four Identity Methods

**Applies to: all gateways**

> ℹ️ This step covers four of the five new interface methods. The fifth — `cancelPaymentIntent()` — is covered in **Step 9**. Don't ship without implementing it; the interface requires it and your gateway will fatal at boot otherwise.

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
public function paymentIntent($amount, Reservation $reservation, $data)
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
public function paymentIntent($amount, Reservation $reservation, $data)
{
    $session = YourProvider::createCheckout([
        'amount' => $amount->raw(),
        'currency' => Str::lower(config('resrv-config.currency_isoCode')),
        'success_url' => $this->getReturnUrl($reservation),
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
3. Dispatch `ReservationConfirmed` or `ReservationCancelled`
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

    // 3. Dispatch appropriate event
    if ($event['type'] === 'payment.completed') {
        ReservationConfirmed::dispatch($reservation);
    } elseif ($event['type'] === 'payment.failed') {
        ReservationCancelled::dispatch($reservation);
    }

    // 4. Respond
    return response()->json([], 200);
}
```

The legacy route (`POST /resrv/api/webhook` without a gateway segment) still works and resolves to the default gateway. Existing single-gateway webhook configurations require zero changes.

---

## Step 5: Implement refund()

**Applies to: all gateways**

The `refund()` method is called from the CP when an admin refunds a reservation. Resrv uses `PaymentGatewayManager::forReservation($reservation)` to resolve the correct gateway from the reservation's `payment_gateway` column. This means a PayPal reservation is refunded through PayPal, a Stripe reservation through Stripe, etc.

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
use Reach\StatamicResrv\Events\ReservationCancelled;
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

    // --- Payment flow ---

    public function paymentIntent($amount, Reservation $reservation, $data)
    {
        // TODO: Create a checkout session with PayPal's API
        // $amount->raw() gives the amount as a numeric string in minor units (cents)
        // config('resrv-config.currency_isoCode') gives the currency code

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

        if ($payload['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            ReservationConfirmed::dispatch($reservation);
        } elseif ($payload['event_type'] === 'PAYMENT.CAPTURE.DENIED') {
            ReservationCancelled::dispatch($reservation);
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
use Reach\StatamicResrv\Events\ReservationCancelled;
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

    // --- Payment flow ---

    public function paymentIntent($amount, Reservation $reservation, $data)
    {
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
4. Gateway verifies signature, finds reservation, dispatches `ReservationConfirmed` or `ReservationCancelled`.

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
- [ ] `verifyPayment()` verifies the webhook signature and dispatches `ReservationConfirmed` or `ReservationCancelled`
- [ ] `refund()` throws `RefundFailedException` on failure
- [ ] Gateway is registered in `config/resrv-config.php` under `payment_gateways`
- [ ] Webhook URL configured in provider's dashboard with the correct config key segment
- [ ] `cancelPaymentIntent()` cancels or voids an intent at the provider (see section below)
- [ ] For inline gateways: custom Blade view works and is publishable
- [ ] For redirect gateways: return URL points to the Statamic page with `{{ resrv_checkout_redirect }}`

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
public function paymentIntent($amount, Reservation $reservation, $data)
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

    if ($eventType === 'failed' || $eventType === 'canceled') {
        // Stale intents were cancelled deliberately by us; ignore their failure / cancellation
        // webhooks so we don't cascade-cancel a reservation the customer is still using.
        if (! $isStaleIntent) {
            ReservationCancelled::dispatch($reservation);
        }
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
