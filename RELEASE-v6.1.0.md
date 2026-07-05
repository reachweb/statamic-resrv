# Statamic Resrv v6.1.0

Resrv v6.1 introduces **manual reservations**: create bookings for your customers straight from the Control Panel, send them a secure pay-by-link email, and optionally give the booking a payment deadline that cancels itself when it lapses.

> Run `php please migrate` after updating — this release adds columns to the reservations table.

## Highlights

### 🧑‍💼 Manual reservations (admin-created bookings)
A new **Create reservation** page (Reservations → Create reservation) lets Control Panel users book on a customer's behalf: pick an entry, dates, rate and quantity, add extras and options (required ones are enforced, exactly like checkout), fill in the entry's checkout form for the customer, and choose how much to request — the **standard** deposit (exactly what checkout would charge), the **full** total, or a **custom** amount. The grand total can be overridden (the base price is back-computed); availability is always checked, with a deliberate **overbook** escape hatch that skips stock movements in both directions. Affiliates can be attributed when the affiliate system is enabled.

Manual bookings are created in a new **`awaiting_payment`** status — not confirmed, not holding a checkout session, exempt from the checkout hold expiry and abandoned-cart machinery. They confirm through the normal payment webhook or a CP **Confirm payment** action (for offline/bank-transfer money), and can be cancelled from the CP at any time before payment.

### 🔗 Pay-by-link page
A new **`reservation-payment`** Livewire component (configure its entry under **Resrv → Settings → Checkout → Manual reservations**) lets the customer pay an admin-created reservation from an emailed deep link — HMAC-authenticated (`?ref=&hash=`), rate-limited, no login or re-entry of details. The page resumes an interrupted payment intent instead of creating a second one, refuses lapsed deadlines, and the existing webhook remains the single confirmation path. Without a configured payment page, online gateways are disabled for manual reservations (offline methods still work with CP confirmation).

### ✉️ Payment request email
A new **Customer payment request** email event (themable, per-form overridable, enabled by default) is sent on creation (toggleable per reservation) and can be resent from the CP; the send is stamped on the reservation. Offline-gateway bookings get a no-link variant with payment instructions.

### ⏳ Hold windows
Optionally hold a manual reservation for X days. The new **`resrv:cancel-lapsed-holds`** command (schedule it alongside your other Resrv commands) cancels overdue holds — releasing inventory only if the booking took it, cancelling any open payment intent, and notifying **both** the customer and the admins with dedicated "payment hold lapsed" wording. A payment that lands after the sweep is reported as an orphaned charge, never silently lost.

## Other changes

- The reservations index gains an **Awaiting payment** badge, filter and row actions (confirm payment, cancel, resend payment request, copy payment link); the reservation detail view gains an awaiting-payment card with the same actions plus hold deadline, email stamp and payment link. `awaiting_payment` is also exportable.
- The activity log records CP confirmations with a dedicated **Confirmed in the Control Panel** reason.
- Orphaned-payment notifications now also fire when a succeeded charge lands on a **cancelled** reservation.
- New `created_by` audit column: manual reservations record the Statamic user who created them.

## For developers

- **`PaymentInterface` gained a method**: `retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object`. Custom gateway implementations must add it — return the gateway's intent object (so an interrupted payment can resume) or `null` when it cannot be retrieved.
- `ReservationCancelled` (event) carries an optional `context` (`ReservationCancelled::CONTEXT_HOLD_LAPSED`), threaded through the cancellation mailables for wording. Existing dispatch sites are unaffected.
- `ReservationConfirmed` (event) gained `VIA_CP` alongside `VIA_CHECKOUT`/`VIA_WEBHOOK`.
- A reusable `HandlesDirectGatewayPayment` Livewire trait mounts gateway payments outside the checkout session (create-or-resume intent, redirect gateways, gateway view mounting).
- New `ReservationData` flags for CP-driven flows: `viaCp` (skips the checkout session write) and `skipDynamicPricings`.
- `affects_availability` (default `true`) now gates **both** the decrement on creation and every restore (expire/cancel/refund) — a reservation that never took stock can never restore it.
