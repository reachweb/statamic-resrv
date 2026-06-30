# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Statamic Resrv is a reservation engine addon for Statamic CMS. It provides availability management, pricing, checkout, and payment processing for booking systems (hotels, rentals, appointments, etc.).

- **Namespace:** `Reach\StatamicResrv\`
- **PHP:** 8.4+
- **Laravel:** 12.*, 13.*
- **Statamic:** 6.*
- **Documentation:** https://resrv.dev/

## Commands

```bash
# Run all tests
vendor/bin/phpunit

# Run all tests, stop on first failure
vendor/bin/phpunit --stop-on-defect

# Run tests with PostgreSQL
vendor/bin/phpunit --configuration phpunit.pgsql.xml
vendor/bin/phpunit --configuration phpunit.pgsql.xml --stop-on-defect

# Run tests with coverage
composer test-coverage

# Run specific test file
vendor/bin/phpunit tests/Availability/AvailabilityTest.php

# Run specific test method
vendor/bin/phpunit --filter "testMethodName"

# Code style (Laravel Pint)
vendor/bin/pint

# Run artisan commands (package context — no php artisan available)
vendor/bin/testbench <command>

# Build admin (CP) assets
npm run cp:build

# Build frontend assets
npm run frontend:build

# Development with watch
npm run cp:dev           # admin
npm run frontend:dev     # frontend
```

## Architecture

### Bootstrap

- **ResrvProvider** (`src/Providers/ResrvProvider.php`) — Main provider: routes, middleware, commands, fieldtypes, tags, scopes, events. Extends Statamic's `AddonServiceProvider`.
- **ResrvLivewireProvider** — Registers Livewire components.
- **Entry point:** `StatamicResrvServiceProvider` registers both providers.

### Event-Driven Reservation Lifecycle

The reservation lifecycle is fully event-driven. **All side effects happen through listeners**, not in controllers:

- `ReservationCreated` → DecreaseAvailability, AddDynamicPricings, AddAffiliate, AddReservationIdToSession
- `ReservationConfirmed` → ConfirmReservation, SendNewReservationEmails
- `ReservationCancelled` → CancelReservation, IncreaseAvailability
- `ReservationExpired` → IncreaseAvailability
- `ReservationRefunded` → SendRefundEmails, IncreaseAvailability, CancelAffiliateCommission
- `CouponUpdated` → UpdateCouponApplied, AssociateAffiliateFromCoupon
- `AvailabilityChanged` → UpdateConnectedAvailabilities

Statamic entry events (`EntrySaved`, `EntryDeleted`, `BlueprintSaved`) sync CMS content to the `resrv_entries` table and clear caches.

### `BuildingReservationEmail` hook (for sibling addons)

`Reach\StatamicResrv\Events\BuildingReservationEmail` is dispatched from `Mailable::dispatchBuildingEvent()` while a reservation mailable is in `build()` and **before** it gets sent. The event carries `public Mailable $mailable` + `public ?Reservation $reservation`, so listeners can mutate the outgoing mail — `attachData()`, `withSymfonyMessage(fn ($email) => $email->embed(...))`, `with([...])`, `subject(...)`, anything Laravel's mailable API supports.

Subclasses opt in by calling `$this->dispatchBuildingEvent($this->reservation)` at the end of their `build()` method (after `markdown(...)` is set so listeners can introspect the template too). `ReservationConfirmed` already opts in; other mailables can be wired the same way without touching listeners.

Listeners that mutate the mailable **must be synchronous** (do not `implements ShouldQueue`), because the event fires inside the same `build()` call that's about to send. Queueing would let the mail go before the mutation lands.

Canonical consumer: `reachweb/statamic-resrv-vouchers` (`AttachVoucherToReservationEmail` listener), which uses the hook to attach a PDF voucher and embed an inline QR PNG on top of the existing confirmation email.

### Availability System (Three Modes)

1. **Standard** — Simple daily availability count and price per Statamic entry.
2. **Advanced** — Per-"property" availability/pricing (e.g., room types in a hotel). Enabled via `advanced_availability` fieldtype setting. Property stored in `availability.property` column.
3. **Connected** — Links entries so booking one property automatically updates related entries (e.g., double room + single room share inventory). Enabled via `connected_availabilities` fieldtype setting. Dispatches `AvailabilityChanged`.

`AvailabilityRepository` encapsulates the complex availability queries with driver-specific SQL (e.g., `string_agg` for Postgres, `group_concat` for MySQL).

### Pricing Layers (Applied in Order)

1. **Base pricing** — From Availability model, per day
2. **Fixed pricing** — Schedule-based overrides for date ranges (`resrv_fixed_pricing`)
3. **Dynamic pricing** — Rule-based adjustments: flat or percentage, conditions on duration/quantity/dates, coupon codes, expiration. Can `overrides_all`. (`resrv_dynamic_pricing`)
4. **Extras & Options** — Per-booking add-ons with their own pricing logic

All monetary values use `moneyphp/money` (stored as cents). Models cast price fields via the custom `PriceClass` Eloquent cast.

### Livewire Checkout Flow

Multi-step checkout in `src/Livewire/`:
1. **AvailabilitySearch** — Date/quantity/property selection (session-persisted with `#[Session]`)
2. **Checkout Step 1** — Extras & Options
3. **Checkout Step 2** — Customer details & review
4. **Checkout Step 3** — Payment (Stripe PaymentIntent)

Traits in `src/Livewire/Traits/` compose the functionality: `HandlesAvailabilityQueries`, `HandlesPricing`, `HandlesReservationQueries`, `HandlesExtrasQueries`, `HandlesOptionsQueries`, `HandlesStatamicQueries`, `HandlesCutoffValidation`, `HandlesAffiliates`.

### Payment Gateway

`PaymentInterface` contract (`src/Http/Payment/PaymentInterface.php`). Implementations: `StripePaymentGateway`, `FakePaymentGateway` (testing). Webhook handling in `WebhookController` transitions reservations: pending → webhook → confirmed.

### Key Patterns

- **Traits over inheritance** — Cross-cutting concerns (pricing, dates, ordering, comparisons) are composed via traits in `src/Traits/`
- **Facades** — `Price`, `Availability`, `AvailabilityField` provide static access
- **Soft deletes** on Extras, Options, OptionValues (preserve reservation history)
- **Cache-forever** — Availability properties and connected settings cached with `Cache::rememberForever()`, cleared on `BlueprintSaved`
- **Polymorphic relationships** — DynamicPricing uses `morphedByMany` for assignment to both Extras and Availability

## Testing

Tests use Orchestra Testbench with SQLite in-memory database by default.

Key test helpers in `TestCase.php`:
- `makeStatamicItem()` / `makeStatamicItemWithResrvAvailabilityField()` — Create Statamic entries with the Resrv fieldtype
- `signInAdmin()` — Authenticate as a super admin
- `assertDatabaseHasJsonColumn()` — Cross-driver JSON column assertion (handles Postgres `::text` cast)

### PostgreSQL Testing

1. Create database: `createdb resrv_test`
2. Configure `phpunit.pgsql.xml` credentials
3. Run: `composer test-pgsql`

## Database Compatibility

Migrations must work across SQLite (testing), MySQL/MariaDB, and PostgreSQL.

### Type Compatibility

PostgreSQL is strict about type comparisons. When joining tables where one column is a string and another is an integer:
- Set `protected $keyType = 'string';` on models that participate in polymorphic relationships with string foreign keys
- Example: `Extra` model uses `$keyType = 'string'` because `dynamic_pricing_assignments.dynamic_pricing_assignment_id` is varchar

### JSON Columns

When querying JSON columns, use driver-specific handling:

```php
$driver = DB::connection()->getDriverName();
if ($driver === 'pgsql') {
    // Cast JSON to text: column::text
} elseif ($driver === 'mysql' || $driver === 'mariadb') {
    // Use JSON_TYPE(), JSON_LENGTH()
} else {
    // SQLite: direct comparison
}
```

Use `assertDatabaseHasJsonColumn()` in tests for cross-driver JSON assertions.

## Configuration

All config is read through `config('resrv-config.*')`, assembled at boot in `ResrvProvider::mergeAddonSettings()` as `array_merge(blueprint defaults, config('resrv-config'), CP-saved settings)`. One owner per key:

- **CP-managed keys** (business info, reservation rules, currency, checkout, emails): defaults come from `default:` lines in `resources/blueprints/settings.yaml` (the ONLY source of these defaults — `SettingsBlueprint::defaults()` walks top-level fields, never grid sub-fields); overrides come from the CP settings store (`resources/addons/statamic-resrv.yaml` on sites). The CP value always wins.
- **Developer keys** (only keys in `config/config.php`, publishable as a stub): `payment_gateway`, `payment_gateways`, `stripe_*` env lookups, and the nested `checkout_forms` / `reservation_emails` override structures.
- `settings()->raw()` is used deliberately — `all()`/`get()` stringify booleans/integers via Antlers.
- `php please resrv:settings:migrate` seeds CP settings from a legacy published config file; a CP warning section appears while a published file still defines CP-managed keys.
- Tests override values with post-boot `Config::set('resrv-config.*', ...)`; `tests/Settings/SettingsDefaultsParityTest` pins blueprint defaults against drift.

## Frontend Stack

- **Admin CP:** Vue 2, Tailwind CSS, Vite (`resources/js/components/`)
- **Frontend:** Livewire (3.x / 4.x), Vanilla Calendar Pro, Tailwind CSS

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.17

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run cp:build` (admin) or `npm run frontend:build` (frontend). Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

</laravel-boost-guidelines>
