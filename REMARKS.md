# PR #189 — "Fix/expired reservation handling" — Review Remarks

**Reviewed by:** 5 parallel Opus 4.7 agents (3 initial investigators + 2 tie-breakers)
**Branch:** `fix/expired-reservation-handling` (tip `70da4a8`) vs `main` (`45e435a`)
**Scope:** `src/Livewire/Checkout.php`, `src/Livewire/Traits/HandlesReservationQueries.php`, `tests/Livewire/CheckoutTest.php`

---

## TL;DR

**The PR is necessary — not over-engineering.** Your intuition that "reservations expire elsewhere, no harm if they expire during checkout, let them complete" is **factually wrong** on two fronts that the agents confirmed with traces. However, the PR does contain **one real ordering bug** that's currently masked by coincidence, plus test quality and out-of-scope findings worth acting on.

**Recommendation:** Merge with one small fix (re-order two lines in `handleFirstStep`) and consider the follow-ups listed at the bottom.

---

## Why the "no harm no foul" hypothesis is wrong

### 1. The Stripe webhook resurrection race is real

Without this PR, this sequence happens today:

1. User reaches step 3, `initializePayment()` persists `payment_id` + `payment_gateway` on the reservation (`Checkout.php:377-381`), creates a Stripe intent with `metadata.reservation_id`.
2. User walks away past `minutes_to_hold`.
3. The intent eventually succeeds on Stripe's side (3-D Secure completes, delayed authorization, etc.) — or user returns and completes without our checkout being aware they'd expired.
4. `payment_intent.succeeded` hits `WebhookController@store` → `StripePaymentGateway::verifyPayment()` (`src/Http/Payment/StripePaymentGateway.php:204`).
5. Webhook looks up by `payment_id` (line 210) — still matches the expired reservation. The `isStaleIntent` branch only triggers when `payment_id` has already been cleared (lines 212-219). An expired-but-intact `payment_id` **bypasses** the stale-intent guard.
6. Status short-circuit at line 227 only checks `CONFIRMED` — `EXPIRED` passes through.
7. `ReservationConfirmed::dispatch()` fires (line 258-278), `ConfirmReservation` listener flips it to confirmed, welcome emails go out.

**Net effect:** a reservation that was logically expired (and may have had its availability released to another user) gets promoted to CONFIRMED with no checks.

The PR closes this specifically by cancelling the dangling intent + clearing `payment_id` in `Checkout::mount()` when the user returns to an expired PENDING reservation (`Checkout.php:79-89`).

### 2. Availability overbooking is also real

I asked an agent to tie-break on this — the trace shows overbooking is achievable today:

- `Availability::getAvailable()` at `src/Models/Availability.php:110` calls `ExpireReservations::dispatchSync()` **before** reading availability (line 114).
- That sweep expires stale PENDING reservations → fires `ReservationExpired` → `IncreaseAvailability` listener bumps the `available` column → the subsequent read sees the freed slot.
- `ReservationConfirmed` / `ConfirmReservation` (`src/Listeners/ConfirmReservation.php:10-17`) performs **zero** availability checks at the confirmation step.
- `Reservation::checkAvailability()` exists at `Models/Reservation.php:247-263` but is **never called anywhere** in `src/` (dead code — see out-of-scope note below).

**Minimal overbooking scenario** (one unit, `minutes_to_hold=15`):

1. User A reserves → `available: 1→0`, `pending=[A]`.
2. 16 minutes pass; A's Stripe intent is in flight.
3. User B hits any page that calls `getAvailable()`. Sweep fires, expires A, `available: 0→1`.
4. User B books → `available: 1→0`, `pending=[B]`.
5. A's webhook lands — `ConfirmReservation` flips A to CONFIRMED with no guard.
6. **Two bookings for one unit.**

### 3. Expiration is *not* "handled elsewhere" in the way you assume

- `ExpireReservations` is the *only* expiration mechanism (no scheduler entry anywhere — `grep -r 'schedule' src/Providers/` is empty).
- It only runs opportunistically inside `Availability::getAvailable()` / `getAvailabilityForEntry()` — i.e. **when someone else searches**.
- On a low-traffic site, a dormant PENDING reservation can rot indefinitely without ever being swept.

---

## Verdict on each piece of the PR

| Piece | File | Status |
|---|---|---|
| (a) Time-based expiry check in `getReservation()` | `HandlesReservationQueries.php:40-45` | **KEEP — load-bearing** |
| (b) `mount()` cancels dangling intent on expired PENDING | `Checkout.php:77-89` | **KEEP — closes the webhook race** |
| (c) `handleFirstStep` split of recoverable vs terminal | `Checkout.php:140-173` | **KEEP but FIX** (see below) |
| (d) `handleSecondStep` + `handleReservationWithoutPayment` set `$reservationError` | `Checkout.php:211-213, 405-407` | **KEEP — closes a 500-loop window** |
| (e) Tests | `tests/Livewire/CheckoutTest.php` | **KEEP but one test is weaker than it looks** |

### Why (d) is not over-engineering

If a user's reservation expires mid-Livewire-roundtrip (they were on step 1 at 9:59, server validation completes at 10:01 past `minutes_to_hold`):

- **Old behavior:** inline `addError` only. On the next click, `$this->reservation` (a `Computed(persist: true)` property whose throw is NOT cached) re-fires `getReservation()`, throws unhandled → Livewire 500. Broken retry loop.
- **New behavior:** `$reservationError` is set → `render()` swaps to `checkout-error` on the same response. User gets a clear terminal page, no 500.

---

## ⚠️ The one real bug in the PR — please fix before merging

### Problem

In `handleFirstStep()` (`Checkout.php:140-173`), the order is:

```php
try {
    $this->confirmReservationIsValid();           // line 149
} catch (OptionsException $e) { ... }
  catch (ExtrasException $e) { ... }
  catch (ReservationException $e) {               // line 158  ← RECOVERABLE catch
    $this->addError('reservation', $e->getMessage());
    return;
  }

try {
    $this->confirmReservationHasNotExpired();     // line 166
} catch (ReservationException $e) {               // line 168  ← TERMINAL catch
    $this->reservationError = $e->getMessage();
    return;
}
```

`confirmReservationIsValid()` (`Checkout.php:444-460`) touches `$this->reservation` (via `calculateReservationTotals()` and `validateReservation()`). When the reservation is time-expired, `getReservation()` in the trait throws `ReservationException('This reservation has expired. Please start over.')` at `HandlesReservationQueries.php:44` — **identical string** to `confirmReservationHasNotExpired()`'s throw.

That "expired" exception is caught by the **recoverable** block at line 158 and demoted to an inline `addError`, *not* the terminal block. The split is misclassifying the exact case it was added to distinguish.

This is currently masked by coincidence:
- When `enableExtrasStep=false`, `mount()` auto-invokes `handleFirstStep()` (line 94), but `$reservationError` was already set in `mount()`'s own catch (line 70), so `render()` shows the error view anyway.
- When `enableExtrasStep=true`, the user has to click through step 1 explicitly — at which point mount's throw would have already landed them on the error page, so `handleFirstStep` doesn't normally run on an expired reservation.

But the bug is still latent: any future refactor that relies on this split actually distinguishing error types will break.

### Fix (two-line change)

Move the expiration check **before** the validation check in `handleFirstStep()`:

```php
public function handleFirstStep(): void
{
    $this->validate();

    // Check terminal expiration FIRST so the recoverable catch below
    // can't swallow a "reservation expired" exception re-thrown from
    // $this->reservation computed property.
    try {
        $this->confirmReservationHasNotExpired();
    } catch (ReservationException $e) {
        $this->addError('reservation', $e->getMessage());
        $this->reservationError = $e->getMessage();
        return;
    }

    try {
        $this->confirmReservationIsValid();
    } catch (OptionsException $e) { ... }
      catch (ExtrasException $e) { ... }
      catch (ReservationException $e) {
        $this->addError('reservation', $e->getMessage());
        return;
    }

    // ...rest unchanged
}
```

This matches the ordering already used by `handleSecondStep` (`Checkout.php:208`).

---

## Test-quality remarks

### `test_time_expired_pending_reservation_cancels_dangling_payment_intent_on_mount` is weaker than it looks

`tests/Livewire/CheckoutTest.php:225`. It asserts `payment_id` is cleared on the reservation, but `FakePaymentGateway::cancelPaymentIntent()` (`src/Http/Payment/FakePaymentGateway.php:38-41`) is a no-op — so the test does NOT prove the gateway's cancel was actually invoked. A regression that skips the gateway call but still clears `payment_id` would pass.

**Suggested fix:** use a spy/mock gateway (or extend `FakePaymentGateway` with a `$cancelledIntents` array property) and assert that `cancelPaymentIntent('stale_intent_expired')` was called.

### Add a regression test for the ordering bug above

Once you apply the fix in `handleFirstStep`, add a test that:
1. Starts with `enableExtrasStep=true`.
2. Travels past `minutes_to_hold`.
3. Calls `handleFirstStep` directly.
4. Asserts the view is `checkout-error` and `$reservationError` is set (not just an inline error).

This would fail on the current PR's code and pass after the reorder.

---

## Changes I recommend for the checkout process (out-of-scope follow-ups)

These surfaced during review; they are NOT part of PR #189 but the agents flagged them as real issues you should consider addressing separately:

### Critical

1. **`ConfirmReservation` has no availability guard.** (`src/Listeners/ConfirmReservation.php:10-17`) The confirmation listener flips status to CONFIRMED with no check that the reservation's slot is still available. This is the root cause of the overbooking scenario above. Add an availability check at confirmation; if it fails, refund the payment and mark the reservation in a reconciliation state.

2. **`StripePaymentGateway::verifyPayment()` doesn't short-circuit on EXPIRED.** (`src/Http/Payment/StripePaymentGateway.php:227`) Only CONFIRMED is checked. An EXPIRED reservation with intact `payment_id` will be promoted to CONFIRMED by the webhook. Add an `EXPIRED` short-circuit that refunds the intent and leaves the reservation in an ops-review state.

3. **`ExpireReservations` job is never scheduled.** Relies entirely on availability-search traffic. On a low-traffic listing, PENDING rows and held inventory can live forever. Add `$schedule->command('resrv:expire-reservations')->everyFiveMinutes()` (or similar) — and wire an Artisan command around the job if one doesn't exist.

### Important

4. **`ExpireReservations::handle()` eagerly expires the current-session reservation.** (`src/Jobs/ExpireReservations.php:25-28`) Calling `getAvailable()` in the same session as an in-progress reservation can wipe the user's own hold. Scope the sweep to exclude the current `session('resrv_reservation')`.

5. **`handleSecondStep` does not re-validate availability/price.** (`Checkout.php:197-241`) Only expiration is checked. If availability or pricing drifted between step 1 and gateway init, the user pays the old amount. Re-run `confirmReservationIsValid()` in step 2.

6. **`Reservation::checkAvailability()` is dead code.** (`Models/Reservation.php:247-263`) Either call it from `validateReservation()` or delete it — carrying unused logic is a future-footgun.

### Nice to have

7. **Webhook doesn't verify amount equality.** The webhook confirms on `payment_intent.succeeded` without checking the intent amount matches the reservation's current `payment`. A pricing drift between intent creation and webhook arrival gets silently accepted.

---

## Bottom line for you

- **Merge PR #189** — your hypothesis was wrong, the fix is justified on both webhook-race and overbooking grounds.
- **Fix the `handleFirstStep` ordering** before merge (two-line change shown above).
- **Consider tightening the payment-intent-cancel test** to actually prove the gateway was called.
- **Open follow-up issues** for the out-of-scope items, especially the three "critical" ones — they are the broader shape of the problem this PR only partially patches at the Livewire layer.
