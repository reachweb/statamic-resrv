# Statamic Resrv v6.5.0

Resrv v6.5 introduces **manual reservations**. You can create bookings for your customers straight from the Control Panel, email them a secure pay-by-link page, and optionally give the booking a payment deadline after which it cancels itself.

> ⚠️ **Custom payment gateways must be updated before you upgrade.** `PaymentInterface` changed. A configured gateway that doesn't implement the changes causes a fatal error on every request that loads Resrv's gateways (checkout, webhooks, the CP reservation pages). See [For developers](#for-developers).

## Upgrade steps

1. **Update custom payment gateways first** (see *Breaking: custom payment gateways* under For developers). If you use the Mollie or PayPal add-on, update it to a release that implements `retrievePaymentIntent()` and the 4-parameter `paymentIntent()` in the same deploy.

2. **Run the migrations** before serving traffic:

   ```bash
   php please migrate
   ```

   This release adds three migrations:

   | Migration | Adds |
   | --- | --- |
   | `2026_07_05_000000_add_manual_reservation_columns_to_resrv_reservations_table` | `resrv_reservations.affects_availability` (boolean, default `true`), `created_by`, `hold_expires_at`, `payment_request_email_sent_at` |
   | `2026_07_21_000000_add_payment_unresolved_to_resrv_reservations_table` | `resrv_reservations.payment_unresolved` (boolean, default `false`) |
   | `2026_09_14_000000_add_total_column_to_resrv_reservation_extra_table` | `resrv_reservation_extra.total` (nullable) |

   Reservation extras are now always loaded with the pivot `total` column, so any page or email that lists a reservation's extras fails until the migrations have run. Existing rows need no backfill: the defaults keep every existing reservation affecting availability, and a `null` extra total falls back to price × quantity.

3. **Schedule the lapsed-hold sweep.** Resrv registers `resrv:cancel-lapsed-holds` but does not schedule it. Add it to `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('resrv:cancel-lapsed-holds')->everyFifteenMinutes()->withoutOverlapping();
   ```

   Scheduled commands only run if your server's cron runs `php artisan schedule:run` every minute.

   Once a hold's deadline passes, the payment link and the "send payment request" action stop working straight away, whether or not the sweep has run. The sweep is what then cancels the reservation, releases its availability, voids any open payment intent and sends the "payment not received" emails. If it isn't scheduled, lapsed holds stay **Awaiting payment** indefinitely, keep their availability, and nobody is emailed. The CP flags such holds with "Hold expired — will be cancelled by the next sweep". Run `php please resrv:cancel-lapsed-holds --dry-run` to see how many holds would be cancelled.

4. **Set up the payment page.** Create a published, non-private entry whose template renders the pay component:

   ```antlers
   {{ livewire:reservation-payment }}
   ```

   Then select that entry under **Resrv → Settings → Checkout → Manual reservations → Payment Page Entry**. Without it, online payment methods are disabled for manual reservations. Offline (manually confirmed) methods still work, and you mark those bookings as paid in the CP.

5. **Optional: add Offline Payment Instructions** in the same settings section, e.g. your bank-transfer details. They are shown as plain text, with line breaks kept, on the pay page and in the payment request email of reservations that use a manually confirmed payment method. Leave the setting empty and customers are told that payment is arranged directly with you and to quote their booking reference.

6. **Use a cache store that supports locks.** The pay page serializes payment attempts with `Cache::lock()`. The `file`, `database`, `redis`, `memcached`, `dynamodb` and `array` stores support locks; `apc` does not. On a store without locks, every online payment on the pay page fails with "We could not start the payment process". If you run more than one app server, use a shared store (`redis`, `database` or `memcached`). The create-reservation page uses a lock as well when one is available: without lock support it still turns a retried submit into the original reservation, but it can't serialize two simultaneous submits.

7. **Re-publish or merge customised views and translations.**
   - **Emails** (published with the `resrv-emails` tag, or custom templates set per email event or form): `cancelled`, `cancelled-customer`, `confirmed`, `made`, `orphaned-payment` and `refunded` changed, and `payment-request` is new. Old copies keep rendering, but they ignore the new `$holdLapsed`, `$paymentInFlight`, `$paymentCollected`, `$context`, `$refundedAutomatically` and `$returnedSeparately` data and fall back to the old wording. For example, an old `cancelled` template tells admins that a lapsed hold was "cancelled by the customer". An unpublished `payment-request` falls back to the packaged copy.
   - **Checkout views** (`resrv-checkout-views`): only new files were added (`livewire/reservation-payment.blade.php` and `livewire/components/partials/business-contact.blade.php`). Unpublished, they fall back to the packaged copies.
   - **Frontend language file** (`resrv-language`): `frontend.php` gained 23 keys for the pay page: `completeYourPayment`, `paymentHeadingInstructions`, `paymentHeadingProcessing`, `paymentHeadingConfirmed`, `paymentHeadingExpired`, `paymentHeadingUnavailable`, `amountToPay`, `paymentDeadline`, `paymentDueBy`, `paymentDueByArrival`, `remainingBalance`, `remainingBalanceNote`, `paymentMethodIs`, `contactPhone`, `paymentLinkFailed`, `paymentLinkExpired`, `paymentArrivalPassed`, `paymentAlreadyCompleted`, `paymentProcessing`, `paymentUnavailable`, `paymentOfflineInstructions`, `paymentOfflineNoInstructions`, `paymentMountFailed`. Only English ships, so add them to every published locale.
   - New email and CP sentences go through `__()`. If you translate Resrv through `lang/<locale>.json`, add the new sentences there.

## Highlights

### 🧑‍💼 Create reservations from the Control Panel
A **Create reservation** button on the Reservations listing opens a new two-column page. The main column holds the form, and a sticky summary sidebar shows the itemised price and what will happen when you create the booking.

- **Booking:** pick an entry (grouped by collection), dates, rate and quantity. Availability and the price are re-quoted live. If there isn't enough availability, an inline **Overbook anyway** action creates the booking without taking stock. Every night still needs a configured price.
- **Extras & options:** priced for the selected stay. Extras that the entry's extra conditions make required are badged with the reason ("Required for these dates", "Required because … is selected"), and the form can't be submitted until they and any required options are chosen. The server enforces the same rules.
- **Customer:** the entry's checkout form, with its widths, instructions and input types. Confirm/repeat email fields fill themselves in.
- **Payment:** request the **standard** amount (what checkout would charge, capped at the total), the **full** total, or a **custom** amount. Choose the payment method; each one explains how the customer pays, and methods that can't be used are disabled with the reason. A booking that collects nothing is confirmed immediately. Its email switch then sends the customer a confirmation instead of a payment request.
- **Hold for a limited time:** optionally cancel the booking automatically if it is still unpaid after 1–365 days. The page previews the deadline and warns when the hold ends after the arrival day.
- **Advanced:** **Override total** (the difference is applied to the base price, and dynamic pricing is not recorded for overridden totals), **Decrease availability** (off = view-only booking that never takes or restores stock) and **Affiliate** (when affiliates are enabled).
- **Safe submits:** if any figure changed since it was shown (another admin's price edit, an expiring dynamic pricing…), creation is refused with "The total changed from … to …" and the page re-quotes. Each page session sends an idempotency key. A retried or double-clicked submit returns the reservation that was already created instead of booking twice. A submit with different input gets a link to the existing reservation. Errors appear under the field they belong to, written for admins (e.g. "The start date is in the past", "Maximum quantity per reservation is 4"), and leaving the page with unsaved changes asks first.

### ⏳ New `awaiting_payment` status
Manual bookings that request a payment are created as **Awaiting payment**. They hold availability (unless overbooked) and are exempt from the checkout hold expiry, abandoned-reservation emails and housekeeping. They leave this status only by being paid (webhook or **Mark as paid**) or cancelled (by an admin or the lapse sweep).

- **Reservations listing:** awaiting-payment rows are **shown by default**. They get a status badge, an overdue badge once they can no longer be paid, an "(unpaid)" note on the amount, a filter, and row actions: Mark as paid…, Send/Resend payment request, Copy payment link, Extend hold… and Cancel reservation.
- **Reservation page:** a header with the status badge, who created the booking, a one-line summary of who is paying what, by when and how, the payment link as a copyable field, and actions that follow the payment method. Overdue holds are flagged "Hold expired — will be cancelled by the next sweep" or "Arrival date passed".
  - **Mark as paid** records the payment (plus any payment surcharge) as received outside Resrv, confirms the booking, sends the confirmation emails and cancels any online payment the customer had started.
  - **Send / Resend payment request** asks for confirmation first. It is refused once the payment window has closed, when the customer email is invalid, or when the email is disabled in the email settings.
  - **Extend hold** sets a new deadline a number of days from now, or removes it, and can email the request again. It also works after a deadline has passed, as long as the sweep hasn't cancelled the booking yet.
  - **Cancel reservation** releases availability the booking took and cancels any open online payment. An **Email the customer a cancellation notice** checkbox is on by default only if a payment request was ever sent.
- **Calendar:** awaiting-payment bookings that took availability now appear on the CP calendar, styled distinctly, with a legend entry and the deadline in the tooltip.
- **Reports:** a new **Outstanding payments** tile shows how many reservations in the report range await payment and the total requested, linking to the filtered listing. Revenue figures are unchanged.
- **Activity log:** new **Created in the Control Panel** and **Confirmed in the Control Panel** reasons.
- **Export:** `awaiting_payment` can be exported; status labels are human-readable.

### 🔗 Pay-by-link page
The new `reservation-payment` Livewire component lets the customer pay from the emailed link. The link is HMAC-authenticated (`?ref=&hash=`) and rate-limited, and needs no login or re-entry of details. The page shows the amount, the remaining balance, the payment method and a "please pay by" line. An interrupted payment resumes instead of creating a second one, and the gateway webhook remains the only thing that confirms the booking.

- **States:** pay now; how to pay (offline methods: your Offline Payment Instructions, or "payment is arranged directly with us", plus the booking reference and your contact details); payment processing; reservation confirmed; payment link expired; arrival date passed; reservation unavailable.
- **Arrival-day cut-off:** a booking can't be paid online once its arrival day has ended (app time zone), even without a hold. The "please pay by" line shows whichever comes first, the hold deadline or the end of the arrival day. Nothing cancels these bookings automatically; the CP flags them for you to mark as paid or cancel.

### ✉️ Emails
- **Customer payment request** is a new email event, enabled by default and overridable per form like the others. It is sent on creation (switchable per booking) and from the CP's send/resend action. It includes a greeting, the amount and deadline, the reservation details, extras and options, the remaining balance and a **Pay now** button; offline methods get the payment instructions instead. A resend opens with "Here is the payment link for your reservation again" (or "Here are the payment details…" for offline methods); one sent from **Extend hold** also says "Your payment deadline is now …". Every send is stamped on the reservation.
- **Lapsed holds** email the customer ("We did not receive your payment by …, so your reservation has been cancelled") and the admins ("cancelled because its payment hold lapsed"). The customer email follows the **Customer cancelled** email event and the admin email the **Admin cancelled (by customer)** event, so disabling either one also silences these.
- If a reservation is cancelled while its online payment is going through, the customer is told the charge will be refunded (even if you unticked the cancellation email), and the admins get a reconciliation alert.
- **Reconciliation alerts** (the orphaned-payment email) gained specific wording and a recommended action for each manual-payment case, e.g. a booking marked as paid while an online payment also went through ("The customer may have paid twice").

## Behaviour changes for existing sites

- **Reservations listing default filter** is now Confirmed + Partner + Awaiting payment (was Confirmed + Partner).
- **Permissions:** the existing `use resrv` permission now also lets a CP user create reservations, mark them as paid, cancel them and extend their holds.
- **Availability edits:** setting an absolute availability is still blocked only by checkouts in progress (pending/webhook), and the error now names their references. Awaiting-payment holds don't block it. If such a hold is later cancelled or lapses, its quantity is added back on top of the value you set, as when a confirmed booking is refunded. Deleting availability is blocked by awaiting-payment holds that took stock. Deleting an entry is blocked by any awaiting-payment reservation, like other non-final reservations.
- **Confirmation emails** (`confirmed`, `made`): when nothing was charged online (offline gateways, bookings marked as paid in the CP) the payment row reads "Amount already paid" instead of "Already paid by credit card". The payment rows now also appear in the "everything" payment mode whenever less than the total was paid.
- **Customer cancellation email:** the "Date" row is now labelled "Cancelled on".
- **Refund email:** when a refund is not returned through the gateway (offline payments, bookings marked as paid in the CP), the email now adds "Your payment of … will be returned to you separately", or "If you have already paid for this reservation, …" when Resrv doesn't know whether the customer paid.
- **CP refunds of offline or out-of-band payments** now show "Refund recorded — return the payment to the customer manually", and the reservation page keeps a "Return the payment manually" notice, instead of claiming the money was refunded.
- **Checkout bookings paid through an offline (manually confirmed) gateway** are no longer shown in the CP as paid: Resrv can't know whether the money arrived, so the amount is shown neutrally as "Payment" and the manual-refund messages are hedged.
- **Orphaned-payment alerts** now also fire when a successful payment lands on a **cancelled** reservation (e.g. a lapsed hold), except for the retained charge of a no-refund cancellation.
- **CP dates** on the reservation page now show their time zone, and status labels in filters, badges and exports are translated, human-readable labels.

## Fixes

- **Relative rates:** frontend calendar prices on relative rates no longer render as `NaN` (calendar price writes now stay decimal strings instead of serializing as money objects).
- **Multi-cart checkout:** a stale add-on price snapshot in the session no longer causes a dead-end "reservation changed" error when checkout loads. Checkout now re-prices session extras and options against the actual reservation, and the Extras component re-prices on load and when the multi-cart selection changes. If you added app-level workarounds for either issue, you can remove them after updating.
- **Options:** option value descriptions are no longer dropped when saving in the CP.
- **Frontend assets:** `@reachweb/alpine-calendar` updated to 1.3.0 and the frontend assets rebuilt.

## For developers

### ⚠️ Breaking: custom payment gateways
Both changes are enforced by PHP when a gateway class loads. `PaymentGatewayManager` checks every configured gateway class on construction, so a non-compliant gateway breaks checkout, webhooks and the CP reservation pages, not just its own payments. The bundled Stripe, Offline and Fake gateways are already updated. Full details are in [UPGRADE-PAYMENT-GATEWAYS.md](UPGRADE-PAYMENT-GATEWAYS.md), Steps 12–14.

- **`paymentIntent()` gained a 4th parameter**, `?string $returnUrl = null`, and every implementation must declare it. Otherwise the class fails to load with *"Declaration of …::paymentIntent() must be compatible with …"*. Redirect gateways must build their return URL from `$returnUrl ?? <checkout-complete entry>` and append their own parameters separator-aware, because the pay page's URL already carries `?ref=…&hash=…`. Inline gateways can ignore the value. (Step 12)
- **`retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object` is required.** (Step 13)
  - Return an object exposing `->status` in the Stripe vocabulary (`succeeded`, `processing`, `requires_capture`, `canceled`, `requires_*`). While the intent is still payable, include `->redirectTo` (redirect gateways) or `->client_secret` (inline gateways).
  - Return `null` **only** when the provider says the intent definitively doesn't exist (e.g. HTTP 404 / `resource_missing`).
  - **Throw** on any transient failure (timeout, 429, 5xx, auth, connection). Never wrap the call in a catch-all that returns `null`: Resrv treats `null` as "gone". It would then mint a second chargeable intent next to a live one, drop the reference to a charge that later captures, or skip the gateway refund of a real charge.
- **`verifyPayment()` must confirm `awaiting_payment` reservations**, not just `pending` ones. Confirm through `transitionTo(ReservationStatus::CONFIRMED, tolerant: true)` with no `pending`-only pre-check. Online gateways are offered for manual reservations only when `supportsWebhooks()` returns `true`. (Step 14)

### Other changes
- **New status `ReservationStatus::AWAITING_PAYMENT`** (`awaiting_payment`). Code with an exhaustive `match` over the enum throws `UnhandledMatchError` until it handles the new case. It is created directly (never transitioned into), can move only to `CONFIRMED` or `CANCELLED`, and is in none of `live()`, `terminal()` or `inFlight()`. `inFlight()` is still `PENDING` + `WEBHOOK`. The enum also gained `label()` and `labelFor(?string)` for translated labels.
- **New email event** `ReservationEmailEvent::CustomerPaymentRequest` (`customer_payment_request`) with the `Mail\ReservationPaymentRequest` mailable. Exhaustive `match`es over `ReservationEmailEvent` need the new case too.
- **`ReservationCancelled`** is now `__construct(Reservation $reservation, ?string $context = null, bool $notifyCustomer = true)`.
  - Contexts: `CONTEXT_HOLD_LAPSED` (lapse sweep), `CONTEXT_UNPAID_HOLD` (admin cancelled an awaiting-payment booking) and `CONTEXT_PAYMENT_IN_FLIGHT` (a payment was going through at cancellation). The cancellation mailables use the context for their wording.
  - `CancelAffiliateCommission` voids the commission for all three contexts, even when a `payment_id` is still present.
  - `notifyCustomer: false` makes the bundled listener skip the customer email only. Custom listeners that email customers should honour it. A payment-in-flight cancellation always notifies.
- **`ReservationConfirmed`** gained `VIA_CP` (alongside `VIA_CHECKOUT` / `VIA_WEBHOOK`) and a 4th parameter, `bool $notifyCustomer = true`. `false` skips the customer confirmation email; the admin email still goes out. The queued `Jobs\SendNewReservationEmails` takes the same flag, and payloads queued before the upgrade still work.
- **`payment_unresolved`** marks a `payment_id` that is *not* proof this booking was paid through the gateway. It is set when Resrv keeps an intent reference only for reconciliation: an unpaid hold was cancelled but its open intent couldn't be verified as voided (or turned out to be capturing money), or a booking was marked as paid in the CP while an online intent still existed. While it is set:
  - `amountPaidOnline()` returns zero, and Reports exclude cancelled rows that carry it from retained revenue.
  - Customers can't self-cancel, and `refundIsAutomatic()` is `false`.
  - A CP refund reads the intent first and only calls the gateway's `refund()` when it is `succeeded` or `processing`.
  - A later succeeded webhook for the kept intent on a cancelled row raises an orphaned-payment alert.

  Don't treat `payment_id !== ''` alone as revenue.
- **`affects_availability`** (default `true`): `false` marks a view-only (overbooked) reservation. It never decrements or restores stock, the availability guards and overlap counts ignore it, and the CP calendar doesn't show it while it awaits payment. `DecreaseAvailability` / `IncreaseAvailability` skip only an explicit `false`, so a `null` (column not selected) is treated as `true`.
- **Extras pivot `total`:** `Reservation::extras()` now loads `withPivot(['quantity', 'price', 'total'])`. `Extra::priceFromPivot()` returns the stored line total when present, otherwise price × quantity. Manual reservations store it because a dynamic-pricing minimum or maximum can make the charged line differ from price × quantity. Use `priceFromPivot()` rather than multiplying the pivot price yourself.
- **`Reservation` model:**
  - New columns / casts: `affects_availability`, `created_by` (Statamic user id, string), `hold_expires_at`, `payment_request_email_sent_at`, `payment_unresolved`.
  - New helpers: `isAwaitingPayment()`, `holdDeadlinePassed()`, `arrivalDayPassed()`, `paymentWindowClosed()`, `paymentDueAt()`, `amountDue()` (payment + surcharge), `amountReceived()` (`null` = unknown), `customerName()`, `customerPaymentUrl()`, `confirmedWithoutGatewayCharge()`, `paymentGatewaySupportsManualConfirmation()` and the static `resolveCustomerPageEntry()`.
- **Activity log reasons:** `ReservationLogReason::CpCreated` (`cp_created`) and `CpConfirmed` (`cp_confirmed`).
- **`OrphanedPaymentNotification`** gained a `$context` with `CONTEXT_OUT_OF_BAND_DUPLICATE`, `CONTEXT_OUT_OF_BAND_STILL_PAYABLE`, `CONTEXT_OUT_OF_BAND_UNVERIFIED`, `CONTEXT_CANCELLED_CAPTURED` and `CONTEXT_CANCELLED_UNVERIFIED`. `notifyIfOrphaned()` now treats `CANCELLED` as terminal, except for a cancelled booking's own retained charge.
- **`ReservationData`** gained `viaCp` (skips writing the frontend checkout session) and `skipDynamicPricings` (used for overridden totals).
- **Traits:** `Livewire\Traits\HandlesDirectGatewayPayment` mounts a gateway payment outside the checkout (create-or-resume under a lock, redirect and inline gateways). `Livewire\Traits\HandlesCustomerLookup` holds the HMAC deep-link lookup and rate limiting, now shared by `reservation-status` and `reservation-payment`.
- **`ActiveReservationsGuard`:** both range guards ignore view-only (`affects_availability = false`) reservations. The new `inFlightReservationReferencesForRange()` returns the blocking references.
- **Exceptions:** `ManualReservationException` (now with `field` and `reason`), `ReservationNoLongerPayable` and `HoldNoLongerLapsed`.
- **Console:** `resrv:cancel-lapsed-holds {--dry-run}`.
- **New CP routes** (all behind `can:use resrv`; names as passed to `cp_route()`):
  - `GET resrv/reservations/create` (`resrv.reservations.create`)
  - `GET resrv/manual/entries` (`resrv.manual.entries`), `GET resrv/manual/entry/{item_id}` (`resrv.manual.entry`), `POST resrv/manual/quote` (`resrv.manual.quote`), `POST resrv/manual` (`resrv.manual.store`)
  - `POST resrv/reservation/{id}/confirm-payment` (`resrv.reservation.confirmPayment`), `/cancel-awaiting` (`resrv.reservation.cancelAwaiting`, accepts `notify_customer`), `/send-payment-request` (`resrv.reservation.sendPaymentRequest`) and `/extend-hold` (`resrv.reservation.extendHold`, accepts `hold_days` 1–365 or `null` and `resend`)
- **Changed CP JSON:**
  - The calendar list (`resrv.reservations.calendar.list`) now also returns awaiting-payment reservations that took availability, each with `status` and `hold_expires_at`.
  - The refund endpoint returns `refund_is_automatic` and translated errors, and its "wrong status" 422 includes the current `status`.
  - Reservation listing rows and the reservation detail gained status labels, ISO dates, payment-state flags and formatted amounts.
  - The Reports payload gained `outstanding: {count, amount}`.
