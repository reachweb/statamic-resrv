# Rate System Implementation Tasks

## Instructions for Claude Code

**Read PLAN.md first** for full architectural context before starting any task.

**How to use this file:**
1. Find the next uncompleted task (marked with `[ ]`)
2. Read the task description, files involved, and acceptance criteria
3. Complete the task
4. Mark it as `[x]` when done
5. Run the verification step if one is listed
6. Move to the next task

**Rules:**
- Complete tasks in order unless noted otherwise (some tasks can run in parallel)
- Run `vendor/bin/pint` after code changes
- Run `vendor/bin/phpunit --stop-on-defect 2>&1` after each task to catch regressions early
- Do NOT modify files outside the task's scope
- Refer to existing code patterns (sibling files, similar models/controllers/tests) for conventions
- All database code must work on SQLite, MySQL, AND PostgreSQL

**Key project conventions:**
- Namespace: `Reach\StatamicResrv\`
- Models: `src/Models/`, Traits: `src/Traits/`, Livewire: `src/Livewire/`
- Tests: `tests/`, use Orchestra Testbench with SQLite in-memory
- Admin Vue: `resources/js/components/`
- Frontend Blade: `resources/views/livewire/`
- Config: `config/config.php` (merged as `resrv-config`)
- Money: `moneyphp/money`, stored as cents, cast via `PriceClass`
- PHP 8.2+, constructor property promotion, explicit return types

---

## Phase 1: Foundation (Database + Rate Model)

### Task 1.1: Create `resrv_rates` migration
- [x] **Status: Complete**

**Description:** Create the migration file for the `resrv_rates` table.

**Files to create:**
- `database/migrations/2026_03_01_000001_create_resrv_rates_table.php`

**Schema (see PLAN.md for full details):**
```
id, statamic_id, title, slug, description,
pricing_type (default 'independent'), base_rate_id (nullable FK to self),
modifier_type (nullable), modifier_operation (nullable), modifier_amount (nullable),
availability_type (default 'independent'), max_available (nullable),
date_start (nullable), date_end (nullable), min_days_before (nullable),
min_stay (nullable), max_stay (nullable), refundable (default true),
order (default 0), published (default true), soft_deletes, timestamps
```

**Indexes:** `(statamic_id)`, `UNIQUE(statamic_id, slug)`, `(base_rate_id)`

**Reference patterns:**
- Look at `database/migrations/2021_04_23_203023_create_extras_table.php` for similar structure
- Use `$table->softDeletes()`, `$table->timestamps()`
- Use `$table->foreignId('base_rate_id')->nullable()->constrained('resrv_rates')->nullOnDelete()`

**Acceptance criteria:**
- Migration runs on SQLite, MySQL, and PostgreSQL
- `composer test` still passes (migration doesn't break existing tests)

---

### Task 1.2: Create Rate model and factory
- [x] **Status: Complete**

**Description:** Create the `Rate` Eloquent model with relationships, business methods, and a factory.

**Files to create:**
- `src/Models/Rate.php`
- `src/Database/Factories/RateFactory.php`

**Rate model requirements:**
- Table: `resrv_rates`
- Uses: `HasFactory`, `SoftDeletes`
- `$keyType = 'string'` (PostgreSQL polymorphic compatibility, same as Extra model)
- Fillable: all columns except id/timestamps/deleted_at
- Casts: `published` -> boolean, `refundable` -> boolean, `date_start` -> date, `date_end` -> date, `modifier_amount` -> `decimal:2`

**Relationships:**
- `entry()`: BelongsTo Entry via `statamic_id` -> `item_id`
- `availabilities()`: HasMany Availability via `rate_id`
- `baseRate()`: BelongsTo self via `base_rate_id`
- `dependentRates()`: HasMany self via `base_rate_id`
- `reservations()`: HasMany Reservation via `rate_id`
- `fixedPricing()`: HasMany FixedPricing via `rate_id`

**Business methods:**
- `isRelative(): bool` — returns `$this->pricing_type === 'relative'`
- `isShared(): bool` — returns `$this->availability_type === 'shared'`
- `isAvailableForDates(string $dateStart, string $dateEnd): bool` — checks date_start/date_end restrictions
- `meetsStayRestrictions(int $duration): bool` — checks min_stay/max_stay
- `meetsBookingLeadTime(string $dateStart): bool` — checks min_days_before
- `calculatePrice(Price $basePrice): Price` — applies modifier to base price, returns new Price. Uses the `Price` class from `src/Money/Price.php`

**Factory states:**
- Default: independent pricing, independent availability
- `relative()`: relative pricing with modifier fields
- `shared()`: shared availability type
- `withRestrictions()`: date range and stay limits

**Reference patterns:**
- Look at `src/Models/Extra.php` for model structure, `$keyType = 'string'`, SoftDeletes
- Look at `src/Database/Factories/ExtraFactory.php` for factory patterns
- Look at `src/Money/Price.php` for price calculation methods (`increasePercent`, `decreasePercent`, `add`, `subtract`)

**Acceptance criteria:**
- Model can be created via factory
- Relationships return correct types
- `calculatePrice()` correctly handles percent increase/decrease and fixed increase/decrease
- `isAvailableForDates()` returns false when dates are outside range
- `composer test` still passes

---

### Task 1.3: Create Rate CP controller and routes
- [x] **Status: Complete**

**Description:** Create the CRUD controller for managing rates in the admin CP, plus routes.

**Files to create:**
- `src/Http/Controllers/RateCpController.php`

**Files to modify:**
- `routes/cp.php` — add rate routes

**Controller methods:**
- `index(string $statamicId)` — list rates for entry, ordered by `order`
- `store(Request $request)` — create rate, validate slug uniqueness within entry
- `update(Request $request, Rate $rate)` — update rate
- `destroy(Rate $rate)` — soft delete (reject if rate has active reservations or is base for other rates)
- `order(Request $request)` — reorder rates (accept array of `{id, order}`)

**Validation rules:**
- `statamic_id`: required, string
- `title`: required, string, max:255
- `slug`: required, string, max:255, unique within statamic_id
- `pricing_type`: required, in:independent,relative
- `base_rate_id`: required_if:pricing_type,relative, exists:resrv_rates,id, must belong to same statamic_id
- `modifier_type`: required_if:pricing_type,relative, in:percent,fixed
- `modifier_operation`: required_if:pricing_type,relative, in:increase,decrease
- `modifier_amount`: required_if:pricing_type,relative, numeric, min:0
- `availability_type`: required, in:independent,shared
- `max_available`: nullable, integer, min:1
- `date_start`, `date_end`: nullable, date
- `min_days_before`, `min_stay`, `max_stay`: nullable, integer, min:0
- `refundable`: boolean
- `published`: boolean

**Routes (add to `routes/cp.php`):**
```php
Route::get('/rate/{statamic_id}', [RateCpController::class, 'index']);
Route::post('/rate', [RateCpController::class, 'store']);
Route::patch('/rate/{rate}', [RateCpController::class, 'update']);
Route::delete('/rate/{rate}', [RateCpController::class, 'destroy']);
Route::post('/rate/order', [RateCpController::class, 'order']);
```

**Reference patterns:**
- Look at `src/Http/Controllers/ExtraCpController.php` for CRUD structure
- Look at `routes/cp.php` for existing route registration

**Acceptance criteria:**
- All CRUD operations work
- Validation prevents invalid data (relative rate without base, duplicate slugs)
- Cannot delete rate that is referenced as base_rate_id by other rates
- `composer test` still passes

---

### Task 1.4: Write Rate CP tests
- [x] **Status: Complete**

**Description:** Write comprehensive tests for the Rate CRUD operations.

**Files to create:**
- `tests/Rate/RateCpTest.php`

**Test cases:**
- Can list rates for an entry (empty and with rates)
- Can create an independent rate
- Can create a relative rate with base_rate_id
- Validation: rejects duplicate slug for same entry
- Validation: rejects relative rate without base_rate_id
- Validation: rejects base_rate_id from different entry
- Can update a rate
- Can soft-delete a rate
- Cannot delete a rate that is base for other rates
- Can reorder rates
- Relative rate `calculatePrice()` works for percent increase/decrease and fixed increase/decrease

**Reference patterns:**
- Look at `tests/Extra/ExtraCpTest.php` for test structure
- Use `$this->signInAdmin()` for authentication
- Use `$this->makeStatamicItemWithResrvAvailabilityField()` for entry creation

**Acceptance criteria:**
- All tests pass with `vendor/bin/phpunit tests/Rate/RateCpTest.php`

---

### Task 1.5: Add `rate_id` columns migration (alongside property)
- [x] **Status: Complete**

**Description:** Add `rate_id` column to existing tables WITHOUT removing `property` yet. This allows both systems to coexist during migration.

**Files to create:**
- `database/migrations/2026_03_01_000002_add_rate_id_to_existing_tables.php`

**Changes:**
- `resrv_availabilities`: add `rate_id` bigint unsigned nullable, add index
- `resrv_reservations`: add `rate_id` bigint unsigned nullable, add index
- `resrv_child_reservations`: add `rate_id` bigint unsigned nullable, add index
- `resrv_fixed_pricing`: add `rate_id` bigint unsigned nullable, add index

**Important:** Do NOT add foreign key constraints yet (the rates table may not have data). Do NOT remove `property` column yet.

**Acceptance criteria:**
- Migration runs on all 3 database drivers
- Existing tests still pass (property column still exists)

---

## Phase 2: Repository + Pricing Layer

### Task 2.1: Update AvailabilityRepository for rate_id
- [x] **Status: Complete**

**Description:** Update all methods in `AvailabilityRepository` to filter by `rate_id` instead of (or alongside) `property`. During this transitional phase, support both.

**Files to modify:**
- `src/Repositories/AvailabilityRepository.php`

**Key changes:**
- All methods that accept `$advanced` array: add alternative path for `$rateId` parameter
- `availableBetween()`: when `$rateId` provided, filter `->where('rate_id', $rateId)` instead of `->whereIn('property', $advanced)`
- `itemAvailableBetween()`: same pattern
- `itemPricesBetween()`: same pattern
- `itemAvailableBetweenForAllProperties()`: duplicate as `itemAvailableBetweenForAllRates()` that groups by `rate_id` and joins `resrv_rates` for metadata
- `decrement()` / `increment()`: accept `$rateId`, resolve Rate model, check if shared

**New method: `decrementShared()`:**
1. Begin DB transaction
2. Get the Rate, resolve `base_rate_id`
3. Lock base rate's availability rows (`sharedLock()`)
4. Check base rate's `available` >= requested quantity
5. If rate has `max_available`: count active reservations for this rate on these dates, check cap
6. Decrement base rate's availability
7. Commit transaction

**New method: `incrementShared()`:**
- Reverse of decrementShared: increment base rate's availability within transaction

**Reference:**
- Read the existing `AvailabilityRepository.php` carefully for driver-specific SQL patterns
- The `groupConcat()` static method handles PostgreSQL vs MySQL differences

**Acceptance criteria:**
- New rate_id-based methods work correctly
- Old property-based methods still work (nothing breaks)
- `composer test` passes

---

### Task 2.2: Update HandlesAvailabilityDates trait
- [x] **Status: Complete**

**Description:** Add rate_id support to the trait that sets up availability query parameters.

**Files to modify:**
- `src/Traits/HandlesAvailabilityDates.php`

**Changes:**
- Add `protected $rateId;` property
- Add `private function setRate($data)` method: reads `rate_id` from data array, stores as `$this->rateId`
- Update `initiateAvailability()` and `initiateAvailabilityUnsafe()`: call `setRate($data)` in addition to `setAdvanced($data)` (keep both during transition)
- Keep `setAdvanced()` for backward compatibility during migration

**Acceptance criteria:**
- `$this->rateId` is set when data contains `rate_id`
- `$this->advanced` still works as before
- `composer test` passes

---

### Task 2.3: Add relative rate pricing to HandlesPricing trait
- [x] **Status: Complete**

**Description:** Update the pricing calculation to handle relative rates.

**Files to modify:**
- `src/Traits/HandlesPricing.php`

**Changes to `getPrices()` method:**
After computing the base price (from availability rows or fixed pricing), add a check:
1. If the current context has a `rate_id`, load the Rate
2. If the Rate `isRelative()`, get the base rate's prices for the same dates
3. Apply the Rate's `calculatePrice()` to each day's price
4. Continue with fixed pricing check for this specific rate
5. Continue with dynamic pricing as before

**Important:** The relative pricing calculation must work per-day (each day in the range gets the modifier applied individually), not on the total.

**Reference:**
- Read `src/Traits/HandlesPricing.php` for current flow
- Read `src/Money/Price.php` for price manipulation methods

**Acceptance criteria:**
- A relative rate with -20% percent modifier returns 80% of base rate's prices
- A relative rate with +20 fixed increase adds 20.00 to each day
- Standard (independent) rate pricing is unchanged
- `composer test` passes

---

### Task 2.4: Write rate availability tests
- [x] **Status: Complete**

**Description:** Test the rate-aware availability and pricing logic.

**Files to create:**
- `tests/Rate/RateAvailabilityTest.php`
- `tests/Rate/RateSharedAvailabilityTest.php`

**RateAvailabilityTest cases:**
- Independent rate: availability query returns correct results when filtering by rate_id
- Relative rate: price calculation returns modified price (percent + fixed, increase + decrease)
- Rate with date_start/date_end: not returned outside date range
- Rate with min_stay: rejected when duration too short
- Rate with max_stay: rejected when duration too long
- Rate with min_days_before: rejected when booking too close to start date

**RateSharedAvailabilityTest cases:**
- Shared rate: booking decrements base rate's availability (not the shared rate's)
- Shared rate with max_available: respects cap
- Shared rate: cancellation increments base rate's availability
- Multiple shared rates on same base: each decrement correctly
- Shared rate: overbooking prevented when base pool exhausted
- Shared rate max_available: overbooking prevented when cap reached even though base pool has capacity

**Reference:**
- Look at `tests/AdvancedAvailability/AdvancedAvailabilityCpTest.php` for property-based patterns
- Look at `tests/Availability/AvailabilityCpTest.php` for availability test patterns
- Use `tests/CreatesEntries.php` helpers

**Acceptance criteria:**
- All tests pass with `vendor/bin/phpunit tests/Rate/`

---

## Phase 3: Model Updates

### Task 3.1: Update Availability model
- [x] **Status: Complete**

**Description:** Add rate relationship and update methods to use rate_id.

**Files to modify:**
- `src/Models/Availability.php`

**Changes:**
- Add `'rate_id'` to `$fillable`
- Add `rate(): BelongsTo` relationship to `Rate` model
- Remove `$dispatchesEvents` array (removes `AvailabilityChanged` event dispatch)
- Remove `getPropertyLabel()` method — replaced by `$this->rate->title`
- Remove `getProperties()` static method — replaced by `Rate::where('statamic_id', ...)->get()`
- Remove `getConnectedAvailabilitySettings()` method
- Update `decrementAvailability()`: if rate is shared, call repository's `decrementShared()`
- Update `incrementAvailability()`: if rate is shared, call repository's `incrementShared()`
- Keep `property` in fillable temporarily (migration hasn't dropped it yet)

**Acceptance criteria:**
- `$availability->rate` returns the Rate model
- Shared rate decrement/increment works through the base rate
- `AvailabilityChanged` event no longer fires on availability updates
- `composer test` still passes (some tests may need temporary adjustments)

---

### Task 3.2: Update Reservation and ChildReservation models
- [x] **Status: Complete**

**Description:** Add rate_id support to reservation models.

**Files to modify:**
- `src/Models/Reservation.php`
- `src/Models/ChildReservation.php`

**Reservation changes:**
- Add `'rate_id'` to `$fillable`
- Add `rate(): BelongsTo` relationship to Rate
- Add `getRateLabel(): string` method — returns `$this->rate?->title ?? 'Default'`
- Keep `property` in fillable temporarily
- Update `getPropertyAttribute()` — for backward compat, return rate slug if rate exists

**ChildReservation changes:**
- Add `'rate_id'` to `$fillable`
- Add `rate(): BelongsTo` relationship to Rate
- Add `getRateLabel(): string` method

**Acceptance criteria:**
- `$reservation->rate` returns the Rate model
- `$reservation->getRateLabel()` returns the rate title
- Existing tests still pass

---

### Task 3.3: Update Entry, FixedPricing, DynamicPricing models
- [x] **Status: Complete**

**Description:** Add rate relationships to supporting models.

**Files to modify:**
- `src/Models/Entry.php` — add `rates(): HasMany` via `statamic_id` -> `item_id`
- `src/Models/FixedPricing.php` — add `rate_id` to fillable, add `rate(): BelongsTo`, update `scopeGetFixedPricing()` to accept and filter by rate_id
- `src/Models/DynamicPricing.php` — add `rates(): MorphedByMany` relationship (same pattern as `extras()` and `entries()`)

**Reference:**
- Look at how `DynamicPricing::entries()` is defined for the morphedByMany pattern
- Look at `FixedPricing::scopeGetFixedPricing()` for the current query

**Acceptance criteria:**
- `$entry->rates` returns collection of Rate models
- `FixedPricing` can be scoped by rate_id
- `DynamicPricing` can be assigned to rates
- Existing tests still pass

---

### Task 3.4: Update factories
- [x] **Status: Complete**

**Description:** Update existing factories to support rate_id and add rate creation helpers.

**Files to modify:**
- `src/Database/Factories/AvailabilityFactory.php` — add `rate_id` field (nullable default)
- `src/Database/Factories/ReservationFactory.php` — add `rate_id` field
- `src/Database/Factories/ChildReservationFactory.php` — add `rate_id` field (if exists)
- `tests/CreatesEntries.php` — add helper methods:
  - `createRateForEntry(Entry $entry, array $attributes = []): Rate`
  - `createRelativeRate(Entry $entry, Rate $baseRate, array $attributes = []): Rate`
  - `createSharedRate(Entry $entry, Rate $baseRate, array $attributes = []): Rate`
  - Update `makeStatamicItemWithAvailability()` to also create a default Rate
  - Update `createEntries()` to create default rates for each entry
  - Update `createAdvancedEntries()` to create rates instead of using property strings

**Acceptance criteria:**
- `Availability::factory()->create(['rate_id' => $rate->id])` works
- `createRateForEntry()` creates a rate and returns it
- Existing test helpers still work (backward compatible)

---

## Phase 4: Events + Listeners

### Task 4.1: Remove connected availability system
- [x] **Status: Complete**

**Description:** Remove the event-driven connected availability sync system entirely.

**Files to delete:**
- `src/Events/AvailabilityChanged.php`
- `src/Listeners/UpdateConnectedAvailabilities.php`

**Files to modify:**
- `src/Providers/ResrvProvider.php`:
  - Remove `AvailabilityChanged::class => [UpdateConnectedAvailabilities::class]` from `$listen`
  - Remove any imports for these classes
- `src/Models/Availability.php`:
  - Remove `$dispatchesEvents` array entirely (if not already done in Task 3.1)

**Tests to delete:**
- `tests/AdvancedAvailability/ConnectedAvailabilityCpTest.php`
- `tests/AdvancedAvailability/ConnectedAvailabilityFrontTest.php`

**Acceptance criteria:**
- No references to `AvailabilityChanged` or `UpdateConnectedAvailabilities` remain in code
- `composer test` passes (some tests may need updating if they tested connected availability)

---

### Task 4.2: Update DecreaseAvailability and IncreaseAvailability listeners
- [x] **Status: Complete**

**Description:** Update reservation lifecycle listeners to use rate_id and handle shared rates.

**Files to modify:**
- `src/Listeners/DecreaseAvailability.php`:
  - Get `rate_id` from `$event->reservation->rate_id`
  - Load the Rate model
  - If rate `isShared()`: use repository's `decrementShared()` method
  - If rate is independent: use existing decrement with rate_id filter
  - Pass rate_id instead of property to availability queries

- `src/Listeners/IncreaseAvailability.php`:
  - Same pattern but for increment
  - If rate `isShared()`: use repository's `incrementShared()` method

**Reference:**
- Read `src/Listeners/DecreaseAvailability.php` for current implementation
- Read `src/Listeners/IncreaseAvailability.php`

**Acceptance criteria:**
- Booking an independent rate decrements that rate's availability
- Booking a shared rate decrements the base rate's availability
- Cancelling restores availability correctly for both types
- `composer test` passes

---

## Phase 5: Livewire Frontend

### Task 5.1: Update AvailabilityData form and HandlesStatamicQueries
- [x] **Status: Complete**

**Description:** Update the Livewire form object and the trait that fetches entry metadata.

**Files to modify:**
- `src/Livewire/Forms/AvailabilityData.php`:
  - Rename `$advanced` to `$rate` (nullable string: rate_id or 'any')
  - Update rules: `'rate' => ['nullable', 'string']`
  - Update `toResrvArray()`: replace `'advanced'` key with `'rate_id'`, map 'any' to null

- `src/Livewire/Traits/HandlesStatamicQueries.php`:
  - Remove `getProperties()`, `getPropertiesFromBlueprint()`, `getEntryProperties()` methods
  - Add `getRatesForEntry(string $entryId): Collection` — queries `Rate::where('statamic_id', $entryId)->published()->orderBy('order')->get()`

**Acceptance criteria:**
- `AvailabilityData::toResrvArray()` returns `rate_id` key
- `getRatesForEntry()` returns rates from database

---

### Task 5.2: Update HandlesAvailabilityQueries and HandlesReservationQueries traits
- [x] **Status: Complete**

**Description:** Update the core Livewire traits that handle availability queries and reservation creation.

**Files to modify:**
- `src/Livewire/Traits/HandlesAvailabilityQueries.php`:
  - Replace `$this->data->advanced` with `$this->data->rate` throughout
  - Replace `'advanced'` key in data arrays with `'rate_id'`
  - Rename `queryAvailabilityForAllProperties()` to `queryAvailabilityForAllRates()` — iterate rates from DB, key results by rate_id instead of property slug
  - Update `toResrvArray()` calls
  - Update `queryBaseAvailabilityForEntry()` to pass rate_id
  - Update `getAvailabilityCalendar()` to pass rate_id

- `src/Livewire/Traits/HandlesReservationQueries.php`:
  - Update `createReservation()`: replace `'property' => $this->data->advanced` with `'rate_id' => $this->data->rate`
  - Update `getAvailabilityDataFromReservation()`: replace property with rate_id

- `src/Livewire/Traits/HandlesPricing.php` (Livewire version):
  - Update parameter names from property to rate_id where applicable

**Acceptance criteria:**
- Availability queries use rate_id
- Reservation creation stores rate_id
- No references to `advanced` or `property` in these traits (except backward compat)

---

### Task 5.3: Update AvailabilitySearch, AvailabilityResults, AvailabilityList components
- [x] **Status: Complete**

**Description:** Update the main Livewire components for search and results.

**Files to modify:**
- `src/Livewire/AvailabilitySearch.php`:
  - Replace `#[Locked] public bool|array $advanced = false` with `#[Locked] public bool $rates = false` (whether entry has multiple rates)
  - Replace `#[Locked] public bool $anyAdvanced = false` with equivalent logic for rates
  - Replace `advancedProperties()` computed property with `entryRates()` — fetches from DB via `getRatesForEntry()`
  - Update `search()` method: if rates mode and no rate selected, set to 'any'
  - Update `overrideProperties` with `overrideRates` (or remove if not needed)

- `src/Livewire/AvailabilityResults.php`:
  - Replace `$advanced` with `$rates`
  - Replace `checkoutProperty(string $property)` with `checkoutRate(string $rateId)`
  - Replace `advancedProperties()` with `entryRates()`
  - Update `checkout()` method

- `src/Livewire/AvailabilityList.php`:
  - Same pattern as AvailabilityResults

**Acceptance criteria:**
- Search component shows rate selector when entry has multiple rates
- Results component displays rates with prices
- `checkoutRate()` creates reservation with correct rate_id

---

### Task 5.4: Update Checkout component and multi-rate support
- [x] **Status: Complete (single-rate checkout works; multi-rate deferred)**

**Description:** Update the checkout orchestrator for rate support and implement multi-rate booking.

**Files to modify:**
- `src/Livewire/Checkout.php`:
  - Rate info flows through via reservation's rate_id (set in Task 5.2)
  - For multi-rate: when `enable_multi_rate_booking` is true and reservation type is 'parent', load child reservations with their rate info

**Note:** Multi-rate booking is the more complex case. For the initial implementation, focus on single-rate checkout working correctly. Multi-rate (parent + children per rate) can be a follow-up within this task if time permits.

**Acceptance criteria:**
- Single-rate checkout works end-to-end
- Rate name displayed in reservation details
- `composer test` passes

---

### Task 5.5: Update Blade views
- [x] **Status: Complete**

**Description:** Update all Livewire Blade templates to use rate terminology.

**Files to modify:**
- `resources/views/livewire/availability-search.blade.php` — rate selector instead of property
- `resources/views/livewire/availability-results.blade.php` — rate display
- `resources/views/livewire/availability-list.blade.php` — rate display
- `resources/views/livewire/components/availability-advanced.blade.php` — rename to `availability-rates.blade.php` or update in-place
- `resources/views/livewire/components/availability-results-advanced.blade.php` — update for rates
- `resources/views/livewire/components/checkout-reservation-details.blade.php` — show rate name
- `resources/views/livewire/checkout.blade.php` — rate info in summary

**Acceptance criteria:**
- All views render without errors
- Rate names display correctly where property names used to show

---

## Phase 6: Admin CP (Vue)

### Task 6.1: Create RatesList and RatePanel Vue components
- [x] **Status: Complete**

**Description:** Build the admin interface for managing rates per entry.

**Files to create:**
- `resources/js/components/RatesList.vue`
- `resources/js/components/RatePanel.vue`

**RatesList.vue:**
- Receives `statamicId` prop
- Fetches rates via `GET /cp/resrv/rate/{statamicId}`
- Draggable list (vue-draggable) with: title, slug, pricing_type badge, availability_type badge, published toggle
- "Add rate" button opens RatePanel
- Click row opens RatePanel in edit mode
- Drag reorder calls `POST /cp/resrv/rate/order`

**RatePanel.vue:**
- Slide-out or modal panel
- Fields: title, slug (auto-generated from title with debounce), description
- Pricing section: radio independent/relative
  - If relative: dropdown for base_rate (other rates for same entry), modifier_type radio, modifier_operation radio, modifier_amount input
- Availability section: radio independent/shared
  - If shared: max_available input (optional)
- Restrictions: date_start, date_end pickers, min_days_before, min_stay, max_stay inputs
- Refundable toggle
- Published toggle
- Save / Delete buttons

**Reference:**
- Look at `resources/js/components/ExtrasList.vue` and `resources/js/components/ExtrasPanel.vue` for structure
- Look at `resources/js/components/FixedPricingList.vue` for simpler list pattern

**Acceptance criteria:**
- Can create, edit, delete, reorder rates via admin UI
- Relative rate form shows modifier fields
- Shared rate form shows max_available field

---

### Task 6.2: Update availability admin components for rates
- [x] **Status: Complete**

**Description:** Update the availability calendar and modals to work with rates instead of properties.

**Files to modify:**
- The fieldtype Vue component — show rate tabs/selector loaded from database (via RatesList) instead of blueprint-defined property tabs
- `resources/js/components/AvailabilityModal.vue` — replace property references with rate
- `resources/js/components/MassAvailabilityModal.vue` — replace property multi-select with rate multi-select

**Acceptance criteria:**
- Availability calendar shows rate selector
- Mass edit works with rate_id
- `npm run build` succeeds

---

## Phase 7: Config + Fieldtype

### Task 7.1: Update config and fieldtype
- [x] **Status: Complete**

**Description:** Remove old config flags and update the Statamic fieldtype.

**Files to modify:**
- `config/config.php`:
  - Remove `'enable_advanced_availability' => false`
  - Remove `'enable_connected_availabilities' => false`

- `src/Fieldtypes/ResrvAvailability.php`:
  - Remove entire `advanced_availability` config from `configFieldItems()`
  - Remove entire `connected_availabilities` grid config
  - Remove `disable_connected_availabilities_on_cp` toggle
  - Add `enable_multi_rate_booking` toggle (default false, instructions: "Allow customers to book multiple rates in a single reservation")
  - Update `preload()`: remove `advanced_availability` from return, add `rates` (or handle in Vue component via API call)
  - Update `augment()`: consider rate context when returning availability data

**Acceptance criteria:**
- Blueprint editor no longer shows advanced_availability or connected_availabilities config
- New `enable_multi_rate_booking` toggle appears
- `composer test` passes

---

## Phase 8: Data Migration + Upgrade Tool

### Task 8.1: Write data migration
- [x] **Status: Complete**

**Description:** Migrate existing property data to rate records.

**Files to create:**
- `database/migrations/2026_03_01_000003_migrate_properties_to_rates.php`

**Migration logic:**
1. Get all distinct `(statamic_id, property)` from `resrv_availabilities`
2. Group by `statamic_id`
3. For each entry:
   - If only has `property='none'`: create one "Default" rate (slug='default')
   - If has named properties: create a rate per unique property slug, use slug as title (will be enhanced by upgrade command with proper labels from blueprint)
4. Update `resrv_availabilities.rate_id` from property → rate mapping
5. Update `resrv_reservations.rate_id` from property → rate mapping
6. Update `resrv_child_reservations.rate_id` from property → rate mapping
7. Update `resrv_fixed_pricing.rate_id` (assign to default rate of entry)

**Important:** This migration must be idempotent (safe to run again if interrupted).

**Acceptance criteria:**
- Every availability record has a rate_id after migration
- Every reservation has a rate_id after migration
- Rate slugs match original property slugs
- `composer test` passes

---

### Task 8.2: Write schema cleanup migration
- [x] **Status: Complete**

**Description:** Drop old columns and add constraints after data migration.

**Files to create:**
- `database/migrations/2026_03_01_000004_finalize_rate_migration.php`

**Changes:**
- Drop `property` column from `resrv_availabilities`
- Drop `property` column from `resrv_reservations`
- Drop `property` column from `resrv_child_reservations`
- Make `rate_id` NOT NULL on `resrv_availabilities`
- Update unique index on `resrv_availabilities`: `(statamic_id, date, rate_id)`
- Add foreign key: `resrv_availabilities.rate_id` references `resrv_rates.id`
- Update unique index on `resrv_fixed_pricing`: `(statamic_id, days, rate_id)`
- Drop `resrv_advanced_availabilities` table if it exists

**Acceptance criteria:**
- `property` column no longer exists in any table
- All constraints are in place
- `composer test` passes on all 3 drivers

---

### Task 8.3: Create upgrade artisan command
- [x] **Status: Complete**

**Description:** Create a guided upgrade command for existing users.

**Files to create:**
- `src/Console/Commands/UpgradeToRates.php`

**Command: `resrv:upgrade-to-rates`**

**Options:**
- `--dry-run`: show what would be migrated without making changes

**Behavior:**
1. Check if migration has already been run (rates table exists and has data)
2. Read blueprint configs to get property labels (from `advanced_availability` settings)
3. Update rate titles with proper labels from blueprint
4. Detect entries with cross-entry connected availabilities (types: `same_slug`, `specific_slugs`, `entries`)
5. Output detailed warnings for these entries:
   - List the entries and their connected groups
   - Explain that cross-entry connections are removed
   - Suggest workarounds (see PLAN.md "Cross-Entry" section)
6. In dry-run mode: output the full migration plan without executing
7. In normal mode: update rate titles, output summary

**Register in `ResrvProvider` commands array.**

**Acceptance criteria:**
- `--dry-run` shows plan without changes
- Normal run updates rate titles from blueprint labels
- Cross-entry connected availability warnings are clear and actionable

---

## Phase 9: Resources + Display

### Task 9.1: Update reservation resources and views
- [x] **Status: Complete**

**Description:** Update the admin display of reservations to show rate info.

**Files to modify:**
- `src/Resources/ReservationResource.php` — replace property column with rate title column
- `src/Resources/ReservationCalendarResource.php` — use `getRateLabel()` instead of `getPropertyAttributeLabel()`
- `src/Resources/AvailabilityResource.php` — replace property with rate data
- `src/Resources/AvailabilityItemResource.php` — same
- Any reservation blade templates that display property info

**Acceptance criteria:**
- Reservation list shows rate name column
- Reservation calendar shows rate labels
- Availability index shows rate info

---

## Phase 10: Test Suite Overhaul

### Task 10.1: Update existing test files
- [x] **Status: Complete**

**Description:** Update all test files that reference `property` or `advanced` to use `rate_id`.

**Files to modify (all in `tests/`):**
- `Availability/AvailabilityCpTest.php` — rate_id instead of property
- `Availability/AvailabilityEventsTest.php` — remove connected availability event tests
- `Availability/AvailabilityHookTest.php` — update property references
- `Availability/AvailabilityScopeTest.php` — update property references
- `AdvancedAvailability/AdvancedAvailabilityCpTest.php` — rewrite as `Rate/` tests or delete
- `DynamicPricing/DynamicPricingApplyTest.php` — rate_id in data arrays
- `DynamicPricing/DynamicPricingCpTest.php` — rate assignment type
- `FixedPricing/FixedPricingApplyTest.php` — rate_id context
- `FixedPricing/FixedPricingCpTest.php` — rate_id
- `Reservation/ReservationCheckoutTest.php` — rate_id in reservation creation
- `Reservation/ReservationCpTest.php` — rate_id
- `Multisite/MultisiteAvailabilityTest.php` — update
- All 11 Livewire test files — replace `advanced` with `rate`, `property` with `rate_id`

**Strategy:** Search for `'property'`, `'advanced'`, `'none'` across all test files and update systematically.

**Acceptance criteria:**
- `composer test` passes with zero failures
- `composer test-pgsql` passes (if PostgreSQL available)
- No remaining references to `property` column (except in migration files)

---

### Task 10.2: Write migration test
- [x] **Status: Complete**

**Description:** Test the data migration correctness.

**Files to create:**
- `tests/Rate/RateMigrationTest.php`

**Test cases:**
- Entry with property='none' gets one "Default" rate
- Entry with properties 'a','b','c' gets three rates with correct slugs
- Availability records mapped correctly to rate_ids
- Reservation property values mapped correctly to rate_ids
- Fixed pricing gets rate_id assigned
- Migration is idempotent (running twice doesn't create duplicates)

**Acceptance criteria:**
- All migration tests pass

---

## Phase 11: Final Cleanup

### Task 11.1: Remove all remaining property/connected references
- [x] **Status: Complete**

**Description:** Final sweep to remove all traces of the old system.

**Search and clean:**
- `grep -r "property" src/` — remove column references (keep "property" in unrelated contexts like CSS properties)
- `grep -r "advanced_availability" src/` — remove all references
- `grep -r "connected_availabilities" src/` — remove all references
- `grep -r "enable_advanced_availability" src/` — remove config references
- `grep -r "enable_connected_availabilities" src/` — remove config references
- Remove `src/Helpers/AvailabilityFieldHelper.php` property/connected cache logic
- Remove `AvailabilityChanged` imports anywhere they remain
- Remove the `AdvancedAvailability/` test directory

**Acceptance criteria:**
- No stale references to old system
- `vendor/bin/pint` passes
- `composer test` passes
- `npm run build` succeeds

---

### Task 11.2: Update Extra model for rate context
- [x] **Status: Complete**

**Description:** The Extra model's pricing methods reference property for availability context. Update to use rate_id.

**Files to modify:**
- `src/Models/Extra.php` — update `priceForDates()`, `calculatePrice()`, `priceForReservation()` to pass rate_id instead of property to availability queries
- `src/Livewire/Traits/HandlesExtrasQueries.php` — update data arrays

**Acceptance criteria:**
- Extra pricing calculations work with rate context
- `composer test` passes
