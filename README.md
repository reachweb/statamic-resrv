# Statamic Resrv, a Statamic reservation engine

![Resrv logo](https://github.com/user-attachments/assets/36e959ff-976d-4e2b-8268-5d48769fe546)

**Statamic Resrv** is a content agnostic reservation engine built using Laravel and "living" inside a Statamic CMS installation. It integrates with the power of Statamic to provide a powerful and flexible reservation system for any possible use.

Resrv is designed to be flexible and cater to many different business scenarios like a hotel, a restaurant, a conference, car rental, boat rental, doctors and more.

Besides the core functionality, it also has many extra functionalities that can be used to build your reservation system. That way, every business can have an in house reservation system on their website and take reservations without paying huge reservation fees or expensive monthly subscriptions.

## Documentation

Full documentation: https://resrv.dev/

Upgrading from v5? Read the upgrade guide first: https://resrv.dev/upgrading

## Requirements

* PHP 8.4+
* Laravel 12 or 13
* Statamic 6.11+
* Livewire 3.6.4+ or Livewire 4

## Features

* Availability and price management: set the available items and the price per day.
* Rates: create multiple rate plans per collection with independent or relative pricing, independent or shared inventory, date and stay restrictions, and per rate cancellation policies.
* Ready to use frontend availability search and checkout built with Laravel Livewire.
* Extra frontend components: a live availability list for a whole collection, a list of upcoming available dates, and cart based multi rate or multi date booking in a single reservation.
* Online reservation and payment with several gateways at checkout. Stripe works out of the box, a built in Offline gateway covers bank transfer or pay at the premises, and Mollie is available as a separate package. Each gateway can add its own surcharge and amount limits.
* Flexible charging: take the full amount, a downpayment, or nothing at all, with a built in way to offer refunds for cancelled reservations.
* Email notifications to admins and customers, customisable per event and per checkout form, plus opt in abandoned reservation recovery emails.
* Reservation rules: set the maximum or minimum days for a reservation, the minimum days before arrival, or charge an extra day based on drop off time.
* Reservation list and calendar, so you never let a reservation slip away.
* Options: selectable choices per booking, with or without an extra charge, that apply to a whole collection or to selected entries, with values you can disable per entry.
* Extras: global items offered with or without an extra charge, that can be conditionally shown, hidden, or required.
* Surcharges: add a flat fee when a customer's choice in one option differs from another, for example a one way fee when the pickup and drop off locations are different.
* Fixed pricing: set a fixed price schedule for specific date ranges.
* Dynamic pricing: increase or decrease prices based on a set of rules, including coupon codes.
* Affiliates: track referrals with cookie based attribution and per affiliate commissions.
* Easily adjustable checkout forms using the Statamic form editor.
* Control Panel settings: manage business, reservation, checkout, email, and currency settings right in the Control Panel.
* Reporting and CSV export: see a quick summary of how your business is doing, run reports by reservation date or booking date, and export reservations to CSV with filters.
* Highly tested codebase: over 1,200 tests with thousands of assertions.

## Testing

The addon ships two independent, additive test suites.

**Headless suite (default).** Over 1,200 PHPUnit tests on Orchestra Testbench with in-memory SQLite. This suite owns validation, pricing and availability math, cutoff/quantity/date rules, checkout orchestration and every state transition.

```bash
composer test          # full suite (SQLite in-memory)
composer test-pgsql    # the same suite against PostgreSQL
vendor/bin/pint        # code style
```

### Browser tests

A separate [`orchestra/testbench-dusk`](https://packagist.org/packages/orchestra/testbench-dusk) suite — still plain PHPUnit, no Pest — drives a **real headless Chrome** through the frontend Livewire funnel. It covers only what the headless suite cannot: Alpine/JS behaviour (the date calendar, steppers, the phone combobox), real `/livewire/update` round-trips through Statamic's routing, asset load order, and the whole search → checkout → confirmed flow in a real DOM. Browser tests are **not** a second copy of the headless suite; everything else stays headless.

**Prerequisites:** Google Chrome (or Chromium) installed locally, plus a matching ChromeDriver:

```bash
php vendor/bin/testbench dusk:chrome-driver --detect
```

**Running:**

```bash
# 1. Build the Workbench host app: creates, migrates and seeds the shared file
#    SQLite DB and publishes the frontend bundle. Re-run after editing the
#    seeder or adding Statamic fixtures.
php vendor/bin/testbench workbench:build

# 2. Run the suite (headless), or headed to watch it in a visible browser:
composer test:browser
composer test:browser:headed
```

`composer test` never launches Chrome: the browser suite has its own `phpunit.dusk.xml` and is deliberately absent from the default and PostgreSQL suites.

**Adding a scenario.** Drop a `*Test.php` in `tests/Browser/` extending `Reach\StatamicResrv\Tests\Browser\BrowserTestCase`. The base boots the same Statamic + addon + Livewire stack as the served app, truncates and re-seeds the shared DB before each test, and clears the session cart. Fixtures come from the `SeedsBookableContent` trait (a collection, a bookable entry, a rate, a wide availability window, an extra, an option, checkout entries); add per-test variants as needed, and register any per-served-app config (e.g. a second payment gateway) through testbench-dusk's `#[BeforeServing]` hook rather than `Config::set()` in the test body.

**Gotchas** (full reasoning in [`browser-testing.md`](browser-testing.md)):

* `resrv-frontend.js` must load **before** Livewire's scripts, and there must be exactly one Alpine — a second instance silently kills the calendar.
* The browser runs in a **separate process** from the test, so both share a **file** SQLite DB and a **file** session (never `:memory:` / `array`).
* The frontend bundle publishes to `public/vendor/statamic-resrv/frontend/…` under the addon slug tag `statamic-resrv`.
* Keep `APP_URL` aligned with the Dusk serve port (default `8001`).

**What is and isn't browser-tested**

| Headless suite owns | Browser suite owns |
| --- | --- |
| Validation, pricing & availability math, cutoff/quantity/date rules | The Alpine date calendar (open / range / clear) |
| Checkout step orchestration & state transitions | Quantity steppers, rate dropdowns, the `dictionary_phone` combobox, toggles |
| Extras/Options enable-disable & price math | The full search → checkout → offline-**confirmed** funnel in a real DOM |
| All non-JS field types | Coupon / dynamic-pricing reactivity, the `window.L` global-leak guard, cross-collection rate reconciliation |

## License

When you are ready to deploy to production you need to buy a license at the Statamic Marketplace.
Statamic Resrv is **not** free software.

## Issues and pull requests

Feel free to open an issue right here on Github. Email us directly for a security issue: iosif@reach.gr
