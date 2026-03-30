# Confirmed Issues — Rates Branch Code Review

Each issue below was found by independent Opus review agents and then verified by a separate confirmation agent against the actual source code. Issues are ordered by severity.

---

## CRITICAL

### Issue 1: `sharedLock()` instead of `lockForUpdate()` allows concurrent overbooking

**File:** `src/Repositories/AvailabilityRepository.php`, line 238
**Method:** `getLockedAvailabilities()`

`getLockedAvailabilities()` uses `sharedLock()` (SQL `LOCK IN SHARE MODE`), which allows multiple concurrent transactions to read the same rows simultaneously. Two concurrent bookings can both read `available = 1`, both pass the availability check, and both decrement to 0 — resulting in overbooking. `sharedLock()` should be replaced with `lockForUpdate()` to acquire an exclusive lock that serializes concurrent access.

**Fix:** Change `->sharedLock()` to `->lockForUpdate()` on line 238.

---

### Issue 2: Non-shared rate `decrement()` does not validate `available >= quantity`

**File:** `src/Repositories/AvailabilityRepository.php`, lines 154–171
**Method:** `decrement()`

The `decrement()` method (for non-shared rates) calls `addToPending()` without checking that `$availability->available >= $quantity`. This can cause `available` to go negative. By contrast, `decrementShared()` (lines 208–211) correctly validates this before decrementing. The initial availability check (`availableBetween`) runs in a separate earlier request, so between that check and `decrement()`, another booking could have reduced availability.

**Fix:** Add a validation check inside the `decrement()` transaction, before calling `addToPending()`:
```php
foreach ($availabilities as $availability) {
    if ($availability->available < $quantity) {
        throw new AvailabilityException(__('Not enough availability.'));
    }
}
```

---

### Issue 3: `validateMaxAvailableForDateRange` reads reservation tables without locks

**File:** `src/Repositories/AvailabilityRepository.php`, lines 334–373
**Method:** `validateMaxAvailableForDateRange()`, called from `decrementShared()`

Inside `decrementShared()`, the transaction acquires a shared lock on `resrv_availabilities` rows, then calls `validateMaxAvailableForDateRange()` which queries `resrv_reservations` and `resrv_child_reservations` with no locks. Two concurrent bookings can both read the reservation counts, both see room under `max_available`, and both proceed — exceeding the maximum.

**Fix:** The reservation count queries inside `validateMaxAvailableForDateRange` should also use `lockForUpdate()`, or the max_available enforcement should use an atomic approach (e.g., a counter column with `WHERE` guard on the UPDATE).

---

## HIGH

### Issue 4: Off-by-one in `itemsExistAndHavePrices` — `<=` vs `<` date boundary mismatch

**File:** `src/Repositories/AvailabilityRepository.php`, line 118
**Method:** `itemsExistAndHavePrices()`

This method uses `->where('date', '<=', $date_end)` (inclusive), while every other method in the repository (`availableBetween`, `itemAvailableBetween`, `itemPricesBetween`, `getLockedAvailabilities`) uses `->where('date', '<', $date_end)` (exclusive). Additionally, line 125 compounds this with `addDay()` on the expected days calculation. This means the validation counts one more day than the actual booking queries.

**Example:** A 3-night stay from Jan 1 to Jan 4: `availableBetween` checks dates Jan 1, 2, 3 (3 days). But `itemsExistAndHavePrices` checks dates Jan 1, 2, 3, 4 and expects 4 days. If Jan 4 has no availability row, validation fails even though the booking would succeed.

**Fix:** Change line 118 to `->where('date', '<', $date_end)` and fix the `expectedDays` calculation on line 125 to `Carbon::parse($date_start)->diffInDays(Carbon::parse($date_end))` (without `addDay()`).

---

### Issue 5: Percentage decrease modifier > 100% creates negative prices

**File:** `src/Models/Rate.php`, line 237; `src/Http/Controllers/RateCpController.php`, line 201

The validation for `modifier_amount` is `'numeric', 'min:0'` with no maximum. If a rate is configured with `modifier_type=percent`, `modifier_operation=decrease`, and `modifier_amount=150`, the `decreasePercent(150)` call computes `(100 - 150) * 0.01 = -0.50`, multiplying the price by -0.50 and producing a **negative price**. This could flow into payment processing.

**Fix:** Add a conditional max validation in `RateCpController::validationRules()`:
```php
'modifier_amount' => [
    'nullable', 'required_if:pricing_type,relative', 'numeric', 'min:0',
    Rule::when(/* modifier_type is percent && operation is decrease */, 'max:100'),
],
```

---

### Issue 6: `RateCpController::destroy()` has no transaction — partial deletes possible

**File:** `src/Http/Controllers/RateCpController.php`, lines 124–126
**Method:** `destroy()`

The method hard-deletes availability rows and fixed pricing rows, then soft-deletes the rate, without wrapping in `DB::transaction()`. If the soft delete fails after the hard deletes succeed, availability and pricing data is permanently lost for a rate that still exists.

**Fix:** Wrap lines 124–126 in `DB::transaction(function () { ... })`.

---

### Issue 7: `ProcessDataImport` crashes on null cache

**File:** `src/Jobs/ProcessDataImport.php`, line 28
**Method:** `handle()`

`Cache::get('resrv-data-import')` returns `null` if the cache has expired or been evicted. The next line calls `->prepare()` on the result with no null check, causing a fatal error. Queued jobs may execute minutes or hours after dispatch, making cache expiration a real possibility.

**Fix:** Add a null check after the cache retrieval:
```php
$dataImport = Cache::get('resrv-data-import');
if (! $dataImport) {
    Log::error('Data import cache expired before job could process.');
    return;
}
```

---

## MEDIUM

### Issue 8: `max(available)` inflates displayed availability count

**File:** `src/Repositories/AvailabilityRepository.php`, lines 74, 89, 143
**Methods:** `availableBetween()`, `itemAvailableBetween()`, `itemPricesBetween()`

The queries use `max(available) as available` when grouping across a date range. This returns the highest availability across any day, not the bottleneck. If an item has availability [5, 1, 5] across 3 days, the result says `available = 5` when only 1 is available on the bottleneck day. Booking correctness is not affected (the per-day `WHERE available >= quantity` guard works), but the frontend displays misleading numbers.

**Fix:** Change `max(available)` to `min(available)` in the selectRaw statements on lines 74, 89, and 143.

---

### Issue 9: `FixedPricing` cache is never invalidated when data changes

**File:** `src/Models/FixedPricing.php`, lines 57–60
**Related:** `src/Http/Controllers/FixedPricingCpController.php`

The `getFixedPricing` scope caches the entire `resrv_fixed_pricing` table under key `fixed_pricing_table` for 60 seconds. No `Cache::forget('fixed_pricing_table')` exists anywhere in the codebase. When fixed pricing is created, updated, or deleted via `FixedPricingCpController`, stale cached data is served for up to 60 seconds.

**Fix:** Add `Cache::forget('fixed_pricing_table')` in the `FixedPricingCpController` after any create/update/delete operation. Or use a model observer on `FixedPricing` to flush the cache on save/delete.

---

### Issue 10: `AvailabilityResults::checkout()` fails to extract rate_id when `rates=true`

**File:** `src/Livewire/AvailabilityResults.php`, lines 141–149
**Method:** `checkout()`

When `$this->rates = true`, `$this->availability` is a collection keyed by rate IDs (from `queryAvailabilityForAllRates()`). But `checkout()` tries `data_get($this->availability, 'data.rate_id')` which won't resolve — there is no top-level `data` key. The normal flow uses `checkoutRate()` which restructures the data first, but if `checkout()` is called directly (e.g., from a template wired to `checkout` instead of `checkoutRate`), `$rateFromResults` will be null and the reservation will be created with no rate.

**Fix:** Add a guard at the start of `checkout()` that redirects to an error state if `$this->rates` is true and `$this->data->rate` is null or `'any'`.

---

### Issue 11: N+1 queries in `getExhaustedDatesForRate` called per-rate in loops

**File:** `src/Repositories/AvailabilityRepository.php`, lines 300–332
**Callers:** `src/Models/Availability.php`, lines 686 and 829

`getExhaustedDatesForRate()` executes 2 queries (Reservation + ChildReservation) per call. It is called inside `foreach ($rates as $rate)` loops in `expandCalendarWithPublishedRates()` and `expandSharedRatesForDates()`. For a property with 10 shared rates that have `max_available` set, this means 20 database queries just for exhausted date checks.

**Fix:** Batch the reservation and child-reservation queries outside the loop — fetch all relevant data for all rate IDs at once, then filter in memory per rate.

---

### Issue 12: Static `$entryCollectionCache` in Rate.php leaks memory in long-running processes

**File:** `src/Models/Rate.php`, line 24

`private static array $entryCollectionCache = []` persists across requests in queue workers and Laravel Octane. `resetEntryCollectionCache()` exists (line 313) but is only called in test teardown (`tests/TestCase.php:45`), never in production code. The cache grows unbounded for every unique entry ID encountered.

**Fix:** Register a `RequestTerminated` listener (for Octane) or queue middleware that calls `Rate::resetEntryCollectionCache()`. Or replace the static cache with Laravel's request-scoped cache (`Context` in Laravel 11+, or `app()->instance()`).

---

### Issue 13: `findOrCreateDefaultForEntry` restores trashed rates with stale configuration

**File:** `src/Models/Rate.php`, lines 278–294
**Method:** `findOrCreateDefaultForEntry()`

When a soft-deleted "default" rate exists, it is restored via `$rate->restore()` with all its old configuration (modifier amounts, availability type, published status, etc.). An admin who deleted a rate because its config was wrong will see it reappear with the same incorrect settings.

**Fix:** After restoring, reset key fields to safe defaults:
```php
if ($rate && $rate->trashed()) {
    $rate->restore();
    $rate->update([
        'published' => true,
        'apply_to_all' => true,
        'pricing_type' => 'independent',
        'modifier_amount' => null,
    ]);
}
```

---

### Issue 14: `AvailabilityCpController::updateAvailability()` has no transaction

**File:** `src/Http/Controllers/AvailabilityCpController.php`, lines 69–110
**Method:** `updateAvailability()`

The method loops over a `CarbonPeriod` calling `Availability::updateOrCreate()` per day without a `DB::transaction()`. A date range could span hundreds of days. If an error occurs mid-loop, some days are updated and others are not, leaving availability in an inconsistent half-updated state.

**Fix:** Wrap the foreach loop in `DB::transaction(function () { ... })`.

---

### Issue 15: `AvailabilityCpController::index()` keyBy date overwrites multi-rate data

**File:** `src/Http/Controllers/AvailabilityCpController.php`, lines 17–31
**Method:** `index()`

When `$identifier` is null, the query returns availability for all rates. `->keyBy('date')` then keeps only the last row per date, silently dropping data from other rates. While the frontend likely always passes a rate identifier, the backend does not enforce this.

**Fix:** Either require `$identifier` (remove the nullable), or when it's null, group by both `date` and `rate_id`.

---

## LOW

### Issue 16: `Rate::$keyType = 'string'` on an auto-incrementing integer primary key

**File:** `src/Models/Rate.php`, line 32

Setting `$keyType = 'string'` on a model with a `bigint` auto-incrementing primary key causes Eloquent to cast the ID to a string. Strict comparisons like `$rate->id === 1` will fail. The comment says this is for PostgreSQL compatibility with the polymorphic `dynamic_pricing_assignments` table.

**Fix:** Instead of changing the model's key type, fix the polymorphic column types in the `dynamic_pricing_assignments` table to use the correct type, or ensure all comparison code uses loose equality.

---

### Issue 17: Duplicate `Rate::find()` calls in `getAvailableDatesFromDate`

**File:** `src/Models/Availability.php`, lines 701 and 724
**Method:** `getAvailableDatesFromDate()`

When `$rateId` is provided and `$showAllRates` is false, `Rate::find($rateId)` is called at line 701 for validation, then again at line 724 for price transformation. The second call should reuse the result from the first.

**Fix:** Replace line 724 with `$rate = $rateCheck;` (reuse the variable from line 701).
