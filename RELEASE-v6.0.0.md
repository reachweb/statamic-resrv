# Statamic Resrv v6.0.0

Resrv v6 is a major release that brings the add-on to **Statamic 6**, introduces a brand‑new **Rates** system, adds **multiple payment gateways**, and modernises the entire Control Panel.

> ⚠️ **This release contains breaking changes.** Back up your database and read the **[Upgrade Guide](https://resrv.dev/upgrading)** before updating an existing site.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- Statamic 6.11+
- Livewire 3.6.4+ or 4

## Highlights

### 🏷️ Rates — replacing advanced & connected availability
The old per‑entry "advanced availability" properties and "connected availabilities" are gone, replaced by a first‑class, **collection‑scoped Rates system** managed in a dedicated Control Panel section. Rates support independent or relative pricing, independent or shared inventory, date and stay restrictions, per‑rate cancellation policies, and selective entry assignment. → [Rates docs](https://resrv.dev/rates)

### 💳 Multiple payment gateways
Offer several payment methods at checkout with a new gateway picker. Ships with a built‑in **Offline gateway** (bank transfer / pay at premises), per‑gateway **surcharges** and **amount limits**, per‑gateway webhooks, and a documented interface for writing your own. Stripe works out of the box; Mollie remains available as a separate add‑on. → [Payment gateways docs](https://resrv.dev/payment-gateways)

### 🛒 New frontend components
- **`availability-collection`** — a live, no‑extra‑package availability list for a whole collection.
- **`availability-multi-results`** — cart‑based multi‑rate / multi‑date booking in a single reservation.
- **`availability-list`** — a list of upcoming available dates.

### 🧮 Conditional surcharges & global options
- A new **Surcharges** primitive adds a flat fee to a booking when a customer's choice in one Option differs from (or matches) their choice in another — e.g. a rental **one‑way fee** when pickup ≠ drop‑off location. Managed under **Resrv → Surcharges**. The fee is always collected up front (even on deposit bookings) and is never discounted by coupons. *(This is distinct from the per‑gateway **payment** surcharge mentioned above.)* → [Surcharges docs](https://resrv.dev/surcharges)
- **Options are now collection‑scoped** (mirroring Rates): an option can apply to a whole collection or selected entries, and individual option **values can be disabled per entry** from the entry's Options editor. Existing per‑entry options are migrated automatically. → [Options docs](https://resrv.dev/options)

### ⚙️ Settings moved to the Control Panel
Business, reservation, checkout, email and currency settings now live under **Resrv → Settings** (stored in YAML), instead of `config/resrv-config.php`, which now only holds developer keys. The `php please resrv:settings:migrate` command moves legacy values across.

### ✉️ Emails & cancellation policies
- Per‑event and per‑checkout‑form email customisation from the Control Panel.
- Opt‑in **abandoned reservation** recovery emails.
- Per‑rate **cancellation policies** (free‑cancellation window or non‑refundable).
- The Logo field now renders in emails automatically.
- **Email theme fallback fix:** an empty or partial `resources/views/vendor/statamic-resrv/email/theme/` directory is no longer treated as a complete custom theme. The packaged theme is always registered as a fallback, so a leftover empty directory (e.g. after un‑publishing the theme) can no longer silently drop the logo header. Publishing only one or two components now overrides just those, keeping the rest of the themed styling intact.

### 🧾 Customer status page & self‑service cancellations *(opt‑in)*
A new **`reservation-status`** Livewire component lets customers view — and optionally cancel — their own reservation. Confirmation emails gain a "Manage your booking" deep link (booking‑scoped HMAC, no login needed), with an email + reference lookup form as fallback, rate‑limited per reference **and** per IP. Two nested, **off‑by‑default** toggles in **Resrv → Settings → Checkout** control it:

- **Enable the reservation status page** — the email link and the component itself (renders nothing while off).
- **Enable customer cancellations** — inside the free‑cancellation window the charge is refunded automatically; after it closes (or under a non‑refundable policy) the customer can still cancel with a heavily‑emphasised **no‑refund** warning, and the payment stays with you. Offline‑gateway and partner bookings stay view‑only ("contact us to cancel").

### 🚫 New CANCELLED reservation status
`REFUNDED` now strictly means *the gateway returned the charge*. Terminations where no money moves back land in a new terminal **`cancelled`** status: customer no‑refund cancellations and CP "refunds" of bookings that never held a charge (partner / zero‑payment). Cancelled bookings restore availability, appear in CP filters/exports, and email the customer a cancellation notice (never a refund claim). Affiliate commissions follow the money: voided on refunds and no‑charge voids, **kept** when a payment is retained. The **Reports** page follows the same rule: cancelled bookings that kept their payment count toward reservation totals, revenue, best sellers and affiliate sales/commission — so a still‑payable commission never disappears from the affiliate report.

### 📊 Reservations & reporting
- New **CSV export** with date‑range, status and column filtering.
- Reports can now run against the booking‑creation date as well as the reservation date.
- Refunds route through whichever gateway took the payment. If a reservation's recorded gateway is **no longer configured**, the refund now **fails closed** with an explicit "handle manually" error instead of silently routing the charge to the current default gateway (only blank legacy rows still fall back). Such reservations are view‑only on the customer status page.
- Commission exports keep **soft‑deleted affiliates** in filters and columns, and when filtered by affiliate they serialize **that** affiliate's data (a reservation can carry both a cookie and a coupon attribution).

### 🎛️ Modernised Control Panel
The entire CP was rebuilt on Statamic 6 (Vue 3 + Inertia + Tailwind v4). The frontend calendar moved from Vanilla Calendar Pro to **`@reachweb/alpine-calendar`**.

### 🔒 Laravel 13 cache hardening
Laravel 13's application skeleton ships `config/cache.php` with `'serializable_classes' => false`, which makes serializing cache stores (`file`, `database`, `redis`, `memcached`) reject cached **objects** on read — returning `__PHP_Incomplete_Class` instead. Resrv caches a handful of framework objects (pricing rows, the availability field, the CSV‑import job payload), so this would otherwise break availability‑field lookups and silently drop dynamic‑pricing discounts on warm reads.

Resrv now handles this for you, with **no configuration required**: it allow‑lists only the pure‑data classes it caches via Statamic's `registerSerializableClasses()` hook (purely additive — your other classes stay locked down, and it's a no‑op if you haven't enabled the hardening), and it no longer caches the availability `Field` object at all. If you've set `cache.serializable_classes` to your own explicit array, Resrv's classes are merged in automatically — you do **not** need to add them yourself.

## Breaking changes (summary)

- Platform floor raised to PHP 8.4 / Laravel 12–13 / Statamic 6.11+ / Livewire 3.6.4+.
- The `property` column, `advanced_availability` and `connected_availabilities` are removed — run `php artisan resrv:upgrade-to-rates` after migrating.
- Custom payment gateways must implement six new `PaymentInterface` methods (including `supportsAutomaticRefunds()`).
- A new terminal **`cancelled`** reservation status exists. CP "refunds" of partner / zero‑payment bookings now land in `cancelled` (with a cancellation email) instead of `refunded` (with a refund email) — site code that matches on the `refunded` status string should account for `cancelled` too. `cancelled` is terminal: a later goodwill refund must be issued from the payment provider's dashboard.
- The Reports page now includes cancelled bookings that **kept their payment** (no‑refund cancellations) in all totals, so reservation counts, revenue and affiliate commissions can read higher than in v5 for the same date range. Refunds and no‑charge voids stay excluded.
- Refunding a reservation whose recorded gateway is no longer configured now fails with an error instead of falling back to the default gateway. Re‑add the gateway configuration or refund the charge manually through the provider.
- The `{{ resrv:reservation_from_uri }}` tag now verifies a booking‑scoped hash (HMAC of `id|email`, exposed as `Reservation::customerLookupHash()`) instead of the previous email‑only HMAC, so a leaked link can no longer be replayed against the same customer's other reservations. Links built by site code with the old formula stop resolving — generate them with `$reservation->customerStatusUrl()` instead.
- Options were refactored from per‑entry to **collection‑scoped**: the `item_id` column on `resrv_options` is replaced by a `collection` column plus a `resrv_option_entries` pivot. Existing options migrate automatically on `php artisan migrate` — **verify your options still appear on the expected entries afterwards**. Multisite installs that bound an option to a non‑default site's localized entry may need to re‑attach it on the default (root) site.
- CP‑managed settings move out of `config/resrv-config.php` into the Control Panel.
- Published frontend views, language overrides and custom gateways need updating.
- The frontend calendar's CSS variables were renamed.
- The `resetOnBoot` attribute was removed from the `availability-search` component. Stale rate selections are now healed automatically across every availability component (search, list, results, multi‑results and collection each reconcile the rate against their own context, dropping a foreign rate and auto‑selecting when a single valid rate exists). **Remove any `:resetOnBoot` attribute from your templates** — Livewire throws a “property does not exist” error for callers that still pass it.

👉 **Full step‑by‑step instructions: [Upgrade Guide → resrv.dev/upgrading](https://resrv.dev/upgrading)**
