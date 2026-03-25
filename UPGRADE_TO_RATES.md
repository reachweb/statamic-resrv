# Upgrading to the Rates System

This guide covers migrating an existing Resrv installation from the property-based availability system to the new rate-based system. It is written for both humans and LLMs performing the upgrade.

## What Changed

The old system used a `property` string column on availability and reservation records to differentiate pricing tiers (e.g., "single-room", "double-room"). Properties were configured per-entry via the `advanced_availability` blueprint field.

The new system replaces properties with **Rates** — first-class database records scoped to a **collection**, not individual entries. Rates support independent or relative pricing, independent or shared availability, date restrictions, stay restrictions, and selective entry assignment.

### Key differences

| Aspect | Old (Properties) | New (Rates) |
|---|---|---|
| Scope | Per entry | Per collection |
| Identifier | `property` string column | `rate_id` foreign key |
| Configuration | Blueprint field config | Database records + admin UI |
| Pricing | Base price only | Independent or relative (modifier off a base rate) |
| Availability | Independent per property | Independent or shared (uses another rate's pool) |
| Assignment | Implicit in availability data | `apply_to_all` flag or explicit pivot table |
| Cross-entry sync | `connected_availabilities` field config | Removed (use custom event listeners) |

### Removed features

- **Connected availabilities** — The `connected_availabilities` blueprint config no longer functions. Cross-entry availability sync must be handled via custom event listeners on `ReservationCreated` / `ReservationCancelled`, or by consolidating entries into one entry with multiple rates.
- **`NoAdvancedAvailabilitySet` exception** — Deleted. Custom code catching this exception must be updated.
- **`property` column** — Removed from `resrv_availabilities`, `resrv_reservations`, and `resrv_child_reservations`.
- **`resrv_advanced_availabilities` table** — Dropped.

---

## Step 1: Back Up Your Database

Before doing anything else, create a full backup of your database. The migration is destructive — it removes columns and drops tables.

```bash
# MySQL/MariaDB
mysqldump -u root -p your_database > backup_before_rates.sql

# PostgreSQL
pg_dump your_database > backup_before_rates.sql
```

---

## Step 2: Update the Package

Update Resrv to the version that includes the rates system:

```bash
composer update reachweb/statamic-resrv
```

---

## Step 3: Run Migrations

The update includes four migrations that run in sequence:

1. **Creates `resrv_rates` and `resrv_rate_entries` tables**
2. **Adds `rate_id` column** to `resrv_availabilities`, `resrv_reservations`, `resrv_child_reservations`, and `resrv_fixed_pricing`
3. **Migrates existing property data to rates** — groups availability records by collection, creates Rate records for each unique property (or a "default" rate for standard availability), and sets `rate_id` on all existing rows
4. **Finalizes** — removes orphaned rows, drops the `property` column, drops old constraints, creates new unique constraint `(statamic_id, date, rate_id)`, drops `resrv_advanced_availabilities` table

```bash
php artisan migrate
```

Check the output for any warnings about orphaned records.

---

## Step 4: Run the Upgrade Command

The upgrade command reads your blueprint configurations and updates the migrated rate records with proper titles and slugs.

**Always run with `--dry-run` first:**

```bash
php artisan resrv:upgrade-to-rates --dry-run
```

This shows:
- Which rate titles would be updated (from property slug to human-readable label from your blueprint config)
- Whether any connected availability configurations were detected (and what to do about them)

**Then run for real:**

```bash
php artisan resrv:upgrade-to-rates
```

### What the command does

- **Standard availability collections** (no properties): Renames the "default" rate to "Standard Rate" with slug `standard-rate`
- **Advanced availability collections** (with properties): Updates rate titles from the `advanced_availability` field labels in your blueprint. For example, if your blueprint had `{"double-room": "Double Room"}`, the rate with slug `double-room` gets title "Double Room"
- **Connected availabilities**: Warns you if any blueprints used cross-entry connections and suggests alternatives

---

## Step 5: Update Published Blade Templates

If you have **not** published or customized Resrv's Livewire views, skip this step — the default views are already updated.

If you **have** published views (typically to `resources/views/vendor/statamic-resrv/`), apply these changes:

### 5a. Rename `availability-advanced` to `availability-rates`

If you have a published `livewire/components/availability-advanced.blade.php`, rename it:

```bash
mv resources/views/vendor/statamic-resrv/livewire/components/availability-advanced.blade.php \
   resources/views/vendor/statamic-resrv/livewire/components/availability-rates.blade.php
```

The component name changed from `x-resrv::availability-advanced` to `x-resrv::availability-rates`.

Inside the file, the default option text changed:

```blade
{{-- Old --}}
<option selected value="any">{{ trans('statamic-resrv::frontend.selectProperty') }}</option>

{{-- New --}}
<option selected value="any">{{ trans('statamic-resrv::frontend.selectRate') }}</option>
```

### 5b. Update `availability-search.blade.php`

If you published this file, the rate selector component reference changed:

```blade
{{-- Old --}}
@if ($advanced)
<x-resrv::availability-advanced
    wire:model.live="data.advanced"
    :entryRates="$this->entryRates"
    :errors="$errors"
/>
@endif

{{-- New --}}
@if ($rates)
<x-resrv::availability-rates
    wire:model.live="data.rate"
    :entryRates="$this->entryRates"
    :errors="$errors"
/>
@endif
```

Changes:
- `$advanced` → `$rates`
- `x-resrv::availability-advanced` → `x-resrv::availability-rates`
- `wire:model.live="data.advanced"` → `wire:model.live="data.rate"`

### 5c. Update `availability-results.blade.php`

If you published this file, the multi-rate display changed:

```blade
{{-- Old --}}
@if ($advanced == true)
<x-resrv::availability-results-advanced :$availability :entryRates="$this->entryRates" />
@else
    @if (data_get($availability, 'request.property') !== 'any')
    ...
    @elseif (data_get($availability, 'request.property') === 'any')
    ...
    @endif
@endif

{{-- New --}}
@if ($rates == true)
<x-resrv::availability-results-advanced :$availability :entryRates="$this->entryRates" />
@else
    @if (data_get($availability, 'message.status') === true)
    ...
    @elseif (data_get($availability, 'message.status') === false)
    ...
    @endif
@endif
```

Changes:
- `$advanced` → `$rates`
- Removed `data_get($availability, 'request.property')` checks — use `data_get($availability, 'message.status')` instead

### 5d. Update `checkout-reservation-details.blade.php`

If you published this file:

```blade
{{-- Old --}}
@if ($reservation->property)
<div class="py-3 md:py-4 border-b border-gray-200">
    <p class="font-medium text-gray-500 truncate">
        {{ trans('statamic-resrv::frontend.property') }}
    </p>
    <p class="text-gray-900 truncate">
        {{ $reservation->getPropertyAttributeLabel() }}
    </p>
</div>
@endif

{{-- New --}}
@if ($reservation->rate_id)
<div class="py-3 md:py-4 border-b border-gray-200">
    <p class="font-medium text-gray-500 truncate">
        {{ trans('statamic-resrv::frontend.property') }}
    </p>
    <p class="text-gray-900 truncate">
        {{ $reservation->getRateLabel() }}
    </p>
</div>
@endif
```

Changes:
- `$reservation->property` → `$reservation->rate_id`
- `$reservation->getPropertyAttributeLabel()` → `$reservation->getRateLabel()`

### 5e. Update email templates

If you published email views that reference property information:

```php
// Old
$reservation->property
$reservation->getPropertyAttributeLabel()

// New
$reservation->rate_id
$reservation->getRateLabel()
```

---

## Step 6: Update Custom Code

If you have custom PHP code interacting with Resrv models, apply these changes:

### 6a. Property references → Rate references

```php
// Old: accessing the property
$reservation->property;
$availability->property;

// New: accessing the rate
$reservation->rate_id;
$reservation->rate;         // Rate model relationship
$reservation->rate->title;  // Human-readable name
$reservation->rate->slug;   // Machine name
```

### 6b. Querying availability

```php
// Old: query by property
Availability::where('statamic_id', $entryId)
    ->where('property', 'double-room')
    ->get();

// New: query by rate
$rate = Rate::forEntry($entryId)
    ->where('slug', 'double-room')
    ->first();

Availability::where('statamic_id', $entryId)
    ->where('rate_id', $rate->id)
    ->get();
```

### 6c. Finding rates for an entry

```php
use Reach\StatamicResrv\Models\Rate;

// Get all rates available for an entry (respects apply_to_all and pivot)
$rates = Rate::forEntry($statamicId)->get();

// Get all rates for a collection
$rates = Rate::forCollection('rooms')->get();

// Get or create the default rate for an entry
$defaultRate = Rate::findOrCreateDefaultForEntry($statamicId);
```

### 6d. API request/response changes

```php
// Old: availability data array
[
    'date_start' => '2026-04-01',
    'date_end' => '2026-04-05',
    'quantity' => 1,
    'advanced' => 'double-room',  // REMOVED
]

// New: availability data array
[
    'date_start' => '2026-04-01',
    'date_end' => '2026-04-05',
    'quantity' => 1,
    'rate_id' => 3,               // integer rate ID
]
```

### 6e. Connected availabilities replacement

If you relied on `connected_availabilities`, create event listeners:

```php
// In your EventServiceProvider or listener
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationCancelled;

// When a reservation is created, sync availability to related entries
Event::listen(ReservationCreated::class, function ($event) {
    // Your custom sync logic here
});

Event::listen(ReservationCancelled::class, function ($event) {
    // Your custom reverse-sync logic here
});
```

---

## Step 7: Update Language Overrides

If you have published language files, these keys have updated default values:

| Key | Old Default | New Default |
|---|---|---|
| `selectProperty` | "Select property" | "Select rate" |
| `selectRate` | *(new key)* | "Select rate" |
| `property` | "Property" | "Rate" |
| `multipleAvailable` | "Multiple properties available." | "Multiple rates available." |
| `pleaseSelectProperty` | "Please select a property..." | "Please select a rate..." |
| `pleaseSelectPropertyToBook` | "Please select a property to book" | "Please select a rate to book" |
| `pleaseSelectRateToBook` | *(new key)* | "Please select a rate to book" |
| `multipleRatesAvailable` | *(new key)* | "Multiple rates available." |
| `pleaseSelectRate` | *(new key)* | "Please select a rate..." |

Old keys are preserved for backward compatibility. New duplicate keys (`selectRate`, `pleaseSelectRate`, `pleaseSelectRateToBook`, `multipleRatesAvailable`) are added alongside the old ones. Update your overrides to use whichever terminology suits your site (e.g., "Room type", "Package", "Plan").

---

## Step 8: Review the Admin CP

After migration, visit the new **Rates** section in the Statamic CP sidebar (under Resrv). This is where rates are now managed instead of through blueprint field configuration.

Verify:
- Each collection has the expected rates with correct titles
- Rates have `apply_to_all` enabled (default after migration) or are assigned to specific entries via the entry picker
- Availability data appears correctly when viewing individual entries

---

## Step 9: Clean Up Blueprint Config (Optional)

The `advanced_availability` and `connected_availabilities` field configs in your blueprints are no longer functional but are preserved for reference. You can safely remove them from your blueprint YAML files after confirming the migration was successful.

---

## Verification Checklist

- [ ] Database backed up before starting
- [ ] `composer update` completed without errors
- [ ] `php artisan migrate` ran all four rate migrations successfully
- [ ] `php artisan resrv:upgrade-to-rates --dry-run` shows expected changes
- [ ] `php artisan resrv:upgrade-to-rates` completed successfully
- [ ] Rates admin section shows correct rates per collection
- [ ] Availability search works on the frontend
- [ ] Multi-rate entries show rate selector and pricing
- [ ] Checkout flow completes successfully
- [ ] Existing reservations display correct rate labels
- [ ] Published Blade templates updated (if applicable)
- [ ] Custom PHP code updated (if applicable)
- [ ] Language file overrides updated (if applicable)
- [ ] Connected availability replacements implemented (if applicable)

---

## Troubleshooting

### Migration fails with duplicate key error
The migration creates a unique constraint on `(collection, slug)`. If your old data had duplicate property slugs within the same collection, the migration groups them. Check for edge cases in your data.

### Rates show with slug as title
Run `php artisan resrv:upgrade-to-rates` — this reads your blueprint labels and updates rate titles.

### "No availability" after migration
Check that availability records have a valid `rate_id`. The migration logs any orphaned rows it deletes. Verify in the database:

```sql
SELECT COUNT(*) FROM resrv_availabilities WHERE rate_id IS NULL;
-- Should be 0 after migration
```

### Frontend shows "Select rate" when it shouldn't
The rate selector only appears when an entry has multiple rates. If a collection should have only one rate, ensure it has a single rate with `apply_to_all = true`.
