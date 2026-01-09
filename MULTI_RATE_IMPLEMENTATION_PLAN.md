# Multi-Rate System Implementation Plan

## Overview

This document outlines the implementation plan for adding a multi-rate system to Resrv. This is a **major version upgrade** that introduces the concept of **Rates** - pricing variations that can be linked to a base price or completely independent.

### Key Design Decisions (Based on Discussion)

1. **Rates are global** - defined once, assignable to multiple entries
2. **Properties remain** - rates work alongside existing "Advanced Availability" properties
3. **Fixed Pricing applies to default rate** - other rates calculate from the result
4. **Dynamic Pricing is global** - applies to all rates (with optional rate restrictions later)
5. **Rate selection in search results** - users see all available rates per entry
6. **Extras are rate-agnostic** - available for all rates
7. **Migration tool provided** - for users upgrading from previous versions
8. **Multi-rate reservations** - a single reservation can have multiple rates (e.g., 2 adults + 2 children)
9. **Deprecate quantity field** - replaced by sum of rate quantities

---

## Phase 1: Database Schema

### New Tables

#### 1. `resrv_rates` - Rate Definitions

```php
Schema::create('resrv_rates', function (Blueprint $table) {
    $table->id();
    $table->string('slug')->unique();           // 'adult', 'child', 'non-refundable'
    $table->string('title');                    // 'Adult Rate'
    $table->text('description')->nullable();

    // Pricing Linkage
    $table->foreignId('parent_rate_id')         // NULL = base rate (independent pricing)
          ->nullable()
          ->constrained('resrv_rates')
          ->nullOnDelete();
    $table->enum('link_type', ['fixed', 'percent'])->default('percent');
    $table->enum('link_operation', ['increase', 'decrease'])->default('decrease');
    $table->decimal('link_amount', 10, 2)->default(0);

    // Availability Settings
    $table->enum('availability_type', ['shared', 'independent', 'limited'])->default('shared');
    $table->integer('availability_limit')->nullable();  // For 'limited' type

    // Display
    $table->integer('order')->default(0);
    $table->boolean('published')->default(true);

    $table->timestamps();
});
```

#### 2. `resrv_entry_rate` - Entry-Rate Assignments

```php
Schema::create('resrv_entry_rate', function (Blueprint $table) {
    $table->id();
    $table->string('entry_id');                 // Statamic entry ID
    $table->foreignId('rate_id')->constrained('resrv_rates')->cascadeOnDelete();
    $table->boolean('is_default')->default(false);

    // Optional per-entry overrides
    $table->decimal('override_link_amount', 10, 2)->nullable();

    // Rate constraints (per-entry, for multi-rate reservations)
    $table->integer('min_quantity')->default(0);      // Minimum required (e.g., 1 adult)
    $table->integer('max_quantity')->nullable();       // Maximum allowed (e.g., max 4 children)
    $table->foreignId('requires_rate_id')              // This rate requires another (child requires adult)
          ->nullable()
          ->constrained('resrv_rates')
          ->nullOnDelete();

    // Availability consumption (for multi-rate)
    $table->boolean('consumes_availability')->default(true);  // Does booking this rate consume availability?

    $table->unique(['entry_id', 'rate_id']);
    $table->index('entry_id');
    $table->timestamps();
});
```

#### 3. `resrv_rate_availabilities` - Rate-Specific Availability/Pricing

This table stores **overrides** for specific rates on specific dates. It's sparse - only populated when a rate needs different availability or pricing than calculated from its parent.

```php
Schema::create('resrv_rate_availabilities', function (Blueprint $table) {
    $table->id();
    $table->string('statamic_id')->index();
    $table->foreignId('rate_id')->constrained('resrv_rates')->cascadeOnDelete();
    $table->string('property')->default('none');
    $table->date('date')->index();

    // What's being overridden
    $table->boolean('override_price')->default(false);
    $table->decimal('price', 10, 2)->nullable();

    $table->boolean('override_availability')->default(false);
    $table->integer('available')->nullable();

    $table->boolean('disabled')->default(false);  // Rate not available on this date

    // Pending reservations for this rate (for independent/limited availability)
    $table->json('pending')->nullable();

    $table->unique(['statamic_id', 'rate_id', 'property', 'date'], 'rate_avail_unique');
    $table->timestamps();
});
```

#### 4. `resrv_reservation_rate` - Multi-Rate Reservation Breakdown

This is the key table for multi-rate reservations. Instead of a single `rate_id` on the reservation, we track each rate and its quantity separately.

```php
Schema::create('resrv_reservation_rate', function (Blueprint $table) {
    $table->id();
    $table->foreignId('reservation_id')
          ->constrained('resrv_reservations')
          ->cascadeOnDelete();
    $table->foreignId('rate_id')
          ->constrained('resrv_rates')
          ->cascadeOnDelete();

    $table->integer('quantity');                    // How many of this rate (e.g., 2 adults)
    $table->decimal('unit_price', 10, 2);           // Price per unit at booking time (frozen)
    $table->decimal('subtotal', 10, 2);             // quantity × unit_price

    $table->unique(['reservation_id', 'rate_id']);
    $table->index('reservation_id');
    $table->timestamps();
});
```

**Example Data:**
| reservation_id | rate_id | quantity | unit_price | subtotal |
|----------------|---------|----------|------------|----------|
| 123            | 1       | 2        | 100.00     | 200.00   | (2 adults @ €100)
| 123            | 2       | 2        | 50.00      | 100.00   | (2 children @ €50)

Total reservation price: €300

### Table Modifications

#### `resrv_reservations` - Deprecate quantity, add computed total

```php
Schema::table('resrv_reservations', function (Blueprint $table) {
    // Mark quantity as deprecated - will be computed from reservation_rate sum
    // Keep for backwards compatibility during migration period
    $table->integer('quantity')->default(1)->comment('DEPRECATED: Use reservation_rate table');

    // Remove single rate_id - replaced by resrv_reservation_rate table
    // $table->foreignId('rate_id') -- NOT ADDED, use pivot table instead
});
```

#### `resrv_child_reservations` - Similar changes

```php
Schema::table('resrv_child_reservations', function (Blueprint $table) {
    // Child reservations also support multi-rate
    // The rate breakdown is still on the parent reservation
    // Child reservations inherit the rate breakdown from parent
    $table->integer('quantity')->default(1)->comment('DEPRECATED: Use parent reservation_rate');
});
```

### Migration Order

1. `create_resrv_rates_table.php`
2. `create_resrv_entry_rate_table.php`
3. `create_resrv_rate_availabilities_table.php`
4. `create_resrv_reservation_rate_table.php`
5. `deprecate_quantity_on_resrv_reservations_table.php`

---

## Multi-Rate Reservations: Detailed Design

This section explains how reservations with multiple rates work, including availability consumption logic.

### Concept Overview

**Old Model (Single Rate):**
```
Reservation #123
├── quantity: 2
├── rate_id: 1 (adult)
└── price: €200 (2 × €100)
```

**New Model (Multi-Rate):**
```
Reservation #123
├── rates:
│   ├── adult (id: 1): quantity 2, unit_price €100, subtotal €200
│   └── child (id: 2): quantity 2, unit_price €50, subtotal €100
├── total_quantity: 4 (computed)
└── price: €300 (sum of subtotals)
```

### Availability Consumption Logic

The key insight: **availability consumption depends on each rate's `availability_type` setting**.

#### Scenario 1: Hotel Room (Occupancy-Based)

**Setup:**
- Entry: "Deluxe Room" with base availability = 3 rooms
- Rates: Adult (shared), Child (shared)

**Booking: 2 adults + 2 children**

Since both rates have `availability_type = 'shared'`:
- This is **occupancy-based** - the 4 people occupy rooms, not consume individual units
- The availability consumed = **1 room** (configured per entry, see below)
- Remaining availability = 2 rooms

For occupancy-based entries, we need a new field on `resrv_entry_rate`:
```php
$table->boolean('consumes_availability')->default(true);  // Does this rate consume availability?
```

**Hotel configuration:**
- Adult rate: `consumes_availability = true` (each adult booking uses 1 room)
- Child rate: `consumes_availability = false` (children don't consume extra rooms)

Availability consumed = sum of quantities where `consumes_availability = true`

#### Scenario 2: Van Excursion (Seat-Based)

**Setup:**
- Entry: "Island Tour" with base availability = 10 seats
- Rates: Adult (shared), Child (shared)

**Booking: 2 adults + 1 child**

Both rates have `availability_type = 'shared'` and `consumes_availability = true`:
- Each person takes a seat
- Availability consumed = **3 seats**
- Remaining availability = 7 seats

#### Scenario 3: Limited Child Spots

**Setup:**
- Entry: "Cooking Class" with base availability = 20 spots
- Rates: Adult (shared), Child (limited, limit = 5)

**Booking: 2 adults + 2 children**

- Adult rate: `availability_type = 'shared'`, consumes from base pool
- Child rate: `availability_type = 'limited'`, consumes from base pool BUT limited to 5 children per day

Availability consumed from base = 4 (2 adults + 2 children)
Child rate bookings for this day = 2 (against limit of 5)

#### Scenario 4: Independent VIP Availability

**Setup:**
- Entry: "Restaurant Table" with base availability = 10 tables
- Rates: Standard (shared), VIP (independent, own pool of 2)

**Booking: 1 VIP table**

- VIP rate: `availability_type = 'independent'`
- Consumes from VIP's own pool in `resrv_rate_availabilities`
- Base availability remains 10

### Availability Consumption Algorithm

```php
class AvailabilityConsumptionCalculator
{
    public function calculateConsumption(
        array $rateQuantities,  // [rate_id => quantity]
        string $entryId
    ): array {
        $sharedConsumption = 0;
        $independentConsumptions = [];  // [rate_id => quantity]
        $limitedConsumptions = [];      // [rate_id => quantity]

        foreach ($rateQuantities as $rateId => $quantity) {
            $entryRate = EntryRate::where('entry_id', $entryId)
                ->where('rate_id', $rateId)
                ->first();

            $rate = $entryRate->rate;

            // Skip if this rate doesn't consume availability
            if (!$entryRate->consumes_availability) {
                continue;
            }

            switch ($rate->availability_type) {
                case 'shared':
                    $sharedConsumption += $quantity;
                    break;

                case 'independent':
                    $independentConsumptions[$rateId] = $quantity;
                    break;

                case 'limited':
                    $sharedConsumption += $quantity;  // Still uses shared pool
                    $limitedConsumptions[$rateId] = $quantity;  // Also tracked against limit
                    break;
            }
        }

        return [
            'shared' => $sharedConsumption,
            'independent' => $independentConsumptions,
            'limited' => $limitedConsumptions,
        ];
    }
}
```

### Rate Constraints Validation

Before accepting a multi-rate booking, validate constraints:

```php
class RateConstraintValidator
{
    public function validate(
        array $rateQuantities,  // [rate_id => quantity]
        string $entryId
    ): bool {
        foreach ($rateQuantities as $rateId => $quantity) {
            $entryRate = EntryRate::where('entry_id', $entryId)
                ->where('rate_id', $rateId)
                ->first();

            // Check minimum
            if ($quantity < $entryRate->min_quantity) {
                throw new RateConstraintException(
                    "Minimum {$entryRate->min_quantity} required for {$entryRate->rate->title}"
                );
            }

            // Check maximum
            if ($entryRate->max_quantity && $quantity > $entryRate->max_quantity) {
                throw new RateConstraintException(
                    "Maximum {$entryRate->max_quantity} allowed for {$entryRate->rate->title}"
                );
            }

            // Check dependency (e.g., children require adults)
            if ($entryRate->requires_rate_id) {
                $requiredQuantity = $rateQuantities[$entryRate->requires_rate_id] ?? 0;
                if ($requiredQuantity < 1) {
                    $requiredRate = Rate::find($entryRate->requires_rate_id);
                    throw new RateConstraintException(
                        "{$entryRate->rate->title} requires at least 1 {$requiredRate->title}"
                    );
                }
            }
        }

        return true;
    }
}
```

### Entry-Rate Table Addition

Add to `resrv_entry_rate`:

```php
// Whether booking this rate consumes base availability
$table->boolean('consumes_availability')->default(true);
```

### Frontend: Rate Quantity Selection

The UI should show quantity selectors for each rate:

```
┌─────────────────────────────────────────────┐
│ Select Guests                               │
├─────────────────────────────────────────────┤
│ Adults (€100/night)          [−] 2 [+]      │
│ Children (€50/night)         [−] 2 [+]      │
│ Infants (Free)               [−] 1 [+]      │
├─────────────────────────────────────────────┤
│ Total: €300                                 │
│ 5 guests selected                           │
└─────────────────────────────────────────────┘
```

### Reservation Model Changes

```php
class Reservation extends Model
{
    // New relationship
    public function rates(): BelongsToMany
    {
        return $this->belongsToMany(Rate::class, 'resrv_reservation_rate')
            ->withPivot(['quantity', 'unit_price', 'subtotal'])
            ->withTimestamps();
    }

    // Computed total quantity (replaces deprecated quantity field)
    public function getTotalQuantityAttribute(): int
    {
        return $this->rates->sum('pivot.quantity');
    }

    // Get rate breakdown for display
    public function getRateBreakdown(): Collection
    {
        return $this->rates->map(fn ($rate) => [
            'rate' => $rate,
            'quantity' => $rate->pivot->quantity,
            'unit_price' => Price::create($rate->pivot->unit_price),
            'subtotal' => Price::create($rate->pivot->subtotal),
        ]);
    }

    // Calculate total from rates (for validation)
    public function calculateTotalFromRates(): PriceClass
    {
        return $this->rates->reduce(
            fn ($total, $rate) => $total->add(Price::create($rate->pivot->subtotal)),
            Price::create(0)
        );
    }
}
```

---

## Phase 2: Models

### New Models

#### `Rate` Model

```
src/Models/Rate.php
```

**Relationships:**
- `parentRate()` - BelongsTo self (for linked rates)
- `childRates()` - HasMany self (rates linked to this one)
- `entries()` - BelongsToMany via `resrv_entry_rate`
- `availabilities()` - HasMany `RateAvailability`
- `reservations()` - HasMany `Reservation`

**Key Methods:**
- `calculatePrice(PriceClass $basePrice): PriceClass` - Applies link calculation
- `isBaseRate(): bool` - Returns true if no parent
- `getEffectivePrice(string $entryId, PriceClass $basePrice): PriceClass` - Considers entry overrides
- `scopeForEntry($query, string $entryId)` - Filter rates assigned to entry
- `scopePublished($query)` - Filter published rates
- `scopeOrdered($query)` - Order by order field

#### `RateAvailability` Model

```
src/Models/RateAvailability.php
```

**Relationships:**
- `rate()` - BelongsTo `Rate`
- `entry()` - BelongsTo `Entry` (via statamic_id)

**Key Methods:**
- `isDisabled(): bool`
- `hasOverridePrice(): bool`
- `hasOverrideAvailability(): bool`
- `getEffectivePrice(PriceClass $calculated): PriceClass`
- `getEffectiveAvailability(int $baseAvailability): int`

#### `EntryRate` Model (Pivot with extras)

```
src/Models/EntryRate.php
```

**Purpose:** Handle the pivot table with additional columns

### Modified Models

#### `Availability` Model Changes

**New Methods:**
- `getAvailableRates(string $statamic_id, string $date, ?string $property): Collection`
- `getRatePricing(Rate $rate, string $statamic_id, array $dates, ?string $property): array`

**Modified Methods:**
- `getPricing()` - Add optional `$rate` parameter
- `getAvailable()` - Consider rate availability modes
- `decrementAvailability()` - Handle rate-specific decrements for independent/limited modes
- `incrementAvailability()` - Handle rate-specific increments

#### `Reservation` Model Changes

**New Relationships:**
- `rates()` - BelongsToMany `Rate` via `resrv_reservation_rate` pivot table

**New Attributes:**
- `getTotalQuantityAttribute()` - Computed sum of all rate quantities (replaces deprecated `quantity`)
- `getRateBreakdown()` - Collection of rate details with quantities and prices

**New Methods:**
- `calculateTotalFromRates(): PriceClass` - Sum of all rate subtotals
- `syncRates(array $rateQuantities)` - Sync rate breakdown to pivot table
- `validateRateConstraints()` - Ensure booking meets min/max/dependency rules

**Deprecated:**
- `quantity` field - Use `total_quantity` attribute instead
- `rate()` single relationship - Use `rates()` collection instead

**Modified Methods:**
- `getPrices()` - Include all rates in calculation
- `validateReservation()` - Add rate constraint validation

#### `FixedPricing` Model Changes

No structural changes needed. Fixed pricing applies to the **base availability price**, then rate calculations are applied on top.

#### `DynamicPricing` Model Changes

For now, no changes. Dynamic pricing applies globally. In a future version, we can add:
- `rate_ids` column for rate-specific dynamic pricing

---

## Phase 3: Services & Repositories

### New Services

#### `RateService`

```
src/Services/RateService.php
```

**Responsibilities:**
- Calculate effective prices for rates
- Determine rate availability for date ranges
- Handle rate assignment to entries

**Key Methods:**

```php
class RateService
{
    /**
     * Get all available rates for an entry on given dates
     */
    public function getAvailableRatesForEntry(
        string $entryId,
        string $dateStart,
        string $dateEnd,
        int $quantity,
        ?string $property = null
    ): Collection;

    /**
     * Calculate the price for a specific rate
     */
    public function calculateRatePrice(
        Rate $rate,
        string $entryId,
        PriceClass $basePrice,
        ?string $dateStart = null,
        ?string $dateEnd = null
    ): PriceClass;

    /**
     * Check if rate has availability for dates
     */
    public function checkRateAvailability(
        Rate $rate,
        string $entryId,
        string $dateStart,
        string $dateEnd,
        int $quantity,
        ?string $property = null
    ): bool;

    /**
     * Get rate-specific availability count
     */
    public function getRateAvailableCount(
        Rate $rate,
        string $entryId,
        string $date,
        ?string $property = null
    ): int;
}
```

#### `RatePricingCalculator`

```
src/Services/RatePricingCalculator.php
```

**Responsibilities:**
- Encapsulate pricing calculation logic
- Handle the pricing chain: Base Price → Fixed Pricing → Rate Calculation → Dynamic Pricing

```php
class RatePricingCalculator
{
    public function calculate(
        string $entryId,
        Rate $rate,
        string $dateStart,
        string $dateEnd,
        int $quantity,
        ?string $property = null
    ): array {
        // 1. Get base calendar prices
        $basePrices = $this->getCalendarPrices($entryId, $dateStart, $dateEnd, $property);

        // 2. Check for fixed pricing override
        $duration = $this->calculateDuration($dateStart, $dateEnd);
        if ($fixedPrice = FixedPricing::getFixedPricing($entryId, $duration)) {
            $baseTotal = $fixedPrice;
        } else {
            $baseTotal = $this->sumPrices($basePrices);
        }

        // 3. Apply rate calculation
        $ratePrice = $rate->calculatePrice($baseTotal, $entryId);

        // 4. Check for rate-specific date overrides
        $ratePrice = $this->applyRateOverrides($rate, $entryId, $dateStart, $dateEnd, $ratePrice, $property);

        // 5. Apply dynamic pricing
        $originalPrice = null;
        if ($dynamicPrice = $this->applyDynamicPricing($ratePrice, $entryId, $dateStart, $dateEnd, $duration)) {
            $originalPrice = $ratePrice;
            $ratePrice = $dynamicPrice;
        }

        // 6. Apply quantity multiplier
        if ($quantity > 1 && !config('resrv-config.ignore_quantity_for_prices')) {
            $ratePrice = $ratePrice->multiply($quantity);
            if ($originalPrice) {
                $originalPrice = $originalPrice->multiply($quantity);
            }
        }

        return [
            'price' => $ratePrice,
            'original_price' => $originalPrice,
            'base_price' => $baseTotal,
            'rate' => $rate,
        ];
    }
}
```

### Modified Repositories

#### `AvailabilityRepository` Changes

**New Methods:**

```php
/**
 * Get availability with rate information
 */
public function availableBetweenWithRates(
    string $date_start,
    string $date_end,
    int $duration,
    int $quantity,
    array $advanced,
    ?int $rateId = null
): Builder;

/**
 * Decrement availability for a specific rate
 */
public function decrementForRate(
    Rate $rate,
    string $date_start,
    string $date_end,
    int $quantity,
    string $statamic_id,
    int $reservationId,
    array $advanced
): void;

/**
 * Increment availability for a specific rate
 */
public function incrementForRate(
    Rate $rate,
    string $date_start,
    string $date_end,
    int $quantity,
    string $statamic_id,
    int $reservationId,
    array $advanced
): void;
```

**Modified Methods:**

- `decrement()` - Add rate support, delegate to `decrementForRate()` when rate provided
- `increment()` - Add rate support, delegate to `incrementForRate()` when rate provided

---

## Phase 4: Traits

### New Traits

#### `HandlesRates`

```
src/Traits/HandlesRates.php
```

Used by Livewire components that need rate handling.

```php
trait HandlesRates
{
    public function getEntryRates(string $entryId): Collection
    {
        return Rate::forEntry($entryId)
            ->published()
            ->ordered()
            ->get();
    }

    public function getDefaultRate(string $entryId): ?Rate
    {
        return Rate::forEntry($entryId)
            ->wherePivot('is_default', true)
            ->first();
    }

    public function calculateRatePrices(
        string $entryId,
        string $dateStart,
        string $dateEnd,
        int $quantity,
        ?string $property = null
    ): Collection {
        $rates = $this->getEntryRates($entryId);
        $calculator = app(RatePricingCalculator::class);

        return $rates->map(fn ($rate) => [
            'rate' => $rate,
            'pricing' => $calculator->calculate(
                $entryId, $rate, $dateStart, $dateEnd, $quantity, $property
            ),
        ]);
    }
}
```

### Modified Traits

#### `HandlesPricing` Changes

**Modified Methods:**

```php
protected function getPrices($prices, $id, ?Rate $rate = null): array
{
    // Existing logic...

    // If rate provided and is not base rate, apply rate calculation
    if ($rate && !$rate->isBaseRate()) {
        $reservationPrice = $rate->calculatePrice($reservationPrice, $id);
    }

    // Rest of existing logic...
}
```

---

## Phase 5: Events & Listeners

### New Events

#### `RateCreated`
#### `RateUpdated`
#### `RateDeleted`
#### `RateAssignedToEntry`
#### `RateRemovedFromEntry`

### Modified Listeners

#### `DecreaseAvailability`

Update to handle rate-specific availability modes:

```php
public function handle(ReservationCreated $event): void
{
    $reservation = $event->reservation;
    $rate = $reservation->rate;

    if ($rate && $rate->availability_type !== 'shared') {
        // Handle independent or limited availability
        $this->handleRateAvailability($reservation, $rate);
    } else {
        // Existing shared pool logic
        $this->handleSharedAvailability($reservation);
    }
}
```

#### `IncreaseAvailability`

Same pattern as above for restoration on expiration/cancellation.

---

## Phase 6: Control Panel UI

### New CP Components

#### Rate Management Page

```
resources/js/cp/rates/
├── RateIndex.vue         # List all rates
├── RateCreate.vue        # Create new rate
├── RateEdit.vue          # Edit existing rate
└── components/
    ├── RateLinkSettings.vue    # Parent rate, link type, amount
    ├── RateAvailabilitySettings.vue  # Shared/Independent/Limited
    └── RatePreview.vue         # Show calculated price preview
```

#### Entry Rate Assignment

Add to existing entry availability management:

```
resources/js/cp/availability/
└── components/
    └── EntryRateAssignment.vue  # Assign rates to entry, set default
```

#### Rate Availability Overrides

```
resources/js/cp/availability/
└── components/
    └── RateAvailabilityOverrides.vue  # Set date-specific rate overrides
```

### New CP Controllers

#### `RateCpController`

```
src/Http/Controllers/RateCpController.php
```

**Endpoints:**
- `GET /cp/resrv/rates` - List rates
- `POST /cp/resrv/rates` - Create rate
- `GET /cp/resrv/rates/{rate}` - Get rate
- `PATCH /cp/resrv/rates/{rate}` - Update rate
- `DELETE /cp/resrv/rates/{rate}` - Delete rate
- `POST /cp/resrv/rates/reorder` - Reorder rates

#### `EntryRateCpController`

```
src/Http/Controllers/EntryRateCpController.php
```

**Endpoints:**
- `GET /cp/resrv/entry/{entry}/rates` - Get entry's rates
- `POST /cp/resrv/entry/{entry}/rates` - Assign rates to entry
- `DELETE /cp/resrv/entry/{entry}/rates/{rate}` - Remove rate from entry
- `POST /cp/resrv/entry/{entry}/rates/{rate}/default` - Set default rate

#### `RateAvailabilityCpController`

```
src/Http/Controllers/RateAvailabilityCpController.php
```

**Endpoints:**
- `GET /cp/resrv/entry/{entry}/rate/{rate}/availability` - Get rate overrides
- `POST /cp/resrv/entry/{entry}/rate/{rate}/availability` - Set rate overrides
- `DELETE /cp/resrv/entry/{entry}/rate/{rate}/availability/{date}` - Remove override

### CP Navigation

Add to existing Resrv navigation:

```php
Nav::extend(function ($nav) {
    $nav->tools('Resrv')
        ->children([
            // Existing items...
            $nav->item('Rates')
                ->route('statamic.cp.resrv.rates.index')
                ->icon('tags'),
        ]);
});
```

---

## Phase 7: Livewire Components

### Modified Components

#### `AvailabilityResults`

**Changes:**
- Add `selectedRate` property
- Modify `getAvailability()` to fetch rates
- Add rate selection UI
- Pass rate to checkout

```php
// New properties
#[Locked]
public ?int $selectedRateId = null;

#[Computed]
public function availableRates(): Collection
{
    if (!$this->availability->has('data')) {
        return collect();
    }
    return $this->getEntryRates($this->entryId);
}

// Modified checkout
public function checkout(): void
{
    $this->data->rate_id = $this->selectedRateId;
    // ... rest of existing logic
}

public function selectRate(int $rateId): void
{
    $this->selectedRateId = $rateId;
    $this->dispatch('rate-selected', $rateId);
}
```

#### `Checkout`

**Changes:**
- Display selected rate information
- Include rate in reservation validation
- Pass rate to pricing calculations

#### `AvailabilitySearch`

**Changes:**
- Optionally allow rate pre-filtering
- Pass rate to availability queries

### New Livewire Components

#### `RateQuantitySelector`

```
src/Livewire/RateQuantitySelector.php
```

Component for selecting quantities for multiple rates (e.g., 2 adults + 2 children):

```php
class RateQuantitySelector extends Component
{
    use HandlesRates;

    #[Locked]
    public string $entryId;

    // Rate quantities: [rate_id => quantity]
    public array $rateQuantities = [];

    public AvailabilityData $data;

    #[Computed]
    public function rates(): Collection
    {
        return $this->getEntryRates($this->entryId);
    }

    #[Computed]
    public function rateConstraints(): Collection
    {
        return EntryRate::where('entry_id', $this->entryId)
            ->with('rate')
            ->get()
            ->keyBy('rate_id');
    }

    #[Computed]
    public function totalPrice(): PriceClass
    {
        $calculator = app(RatePricingCalculator::class);
        $total = Price::create(0);

        foreach ($this->rateQuantities as $rateId => $quantity) {
            if ($quantity <= 0) continue;

            $rate = Rate::find($rateId);
            $pricing = $calculator->calculate(
                $this->entryId,
                $rate,
                $this->data->date_start,
                $this->data->date_end,
                $quantity,
                $this->data->advanced
            );
            $total = $total->add($pricing['price']);
        }

        return $total;
    }

    #[Computed]
    public function totalQuantity(): int
    {
        return array_sum($this->rateQuantities);
    }

    public function mount(): void
    {
        // Initialize quantities with defaults
        foreach ($this->rates as $rate) {
            $constraint = $this->rateConstraints->get($rate->id);
            $this->rateQuantities[$rate->id] = $constraint?->min_quantity ?? 0;
        }
    }

    public function incrementRate(int $rateId): void
    {
        $constraint = $this->rateConstraints->get($rateId);
        $max = $constraint?->max_quantity;

        if ($max === null || $this->rateQuantities[$rateId] < $max) {
            $this->rateQuantities[$rateId]++;
            $this->dispatchQuantitiesUpdated();
        }
    }

    public function decrementRate(int $rateId): void
    {
        $constraint = $this->rateConstraints->get($rateId);
        $min = $constraint?->min_quantity ?? 0;

        if ($this->rateQuantities[$rateId] > $min) {
            $this->rateQuantities[$rateId]--;
            $this->dispatchQuantitiesUpdated();
        }
    }

    public function setRateQuantity(int $rateId, int $quantity): void
    {
        $constraint = $this->rateConstraints->get($rateId);
        $min = $constraint?->min_quantity ?? 0;
        $max = $constraint?->max_quantity;

        $this->rateQuantities[$rateId] = max($min, $max !== null ? min($max, $quantity) : $quantity);
        $this->dispatchQuantitiesUpdated();
    }

    protected function dispatchQuantitiesUpdated(): void
    {
        $this->dispatch('rate-quantities-updated', [
            'quantities' => $this->rateQuantities,
            'total_quantity' => $this->totalQuantity,
            'total_price' => $this->totalPrice->format(),
        ]);
    }

    public function validateConstraints(): bool
    {
        $validator = app(RateConstraintValidator::class);

        try {
            return $validator->validate($this->rateQuantities, $this->entryId);
        } catch (RateConstraintException $e) {
            $this->addError('rates', $e->getMessage());
            return false;
        }
    }

    public function render()
    {
        return view('statamic-resrv::livewire.rate-quantity-selector');
    }
}
```

**Blade View (`rate-quantity-selector.blade.php`):**

```blade
<div class="resrv-rate-quantity-selector">
    <h3>{{ __('Select Guests') }}</h3>

    @foreach($this->rates as $rate)
        @php
            $constraint = $this->rateConstraints->get($rate->id);
            $quantity = $rateQuantities[$rate->id] ?? 0;
            $min = $constraint?->min_quantity ?? 0;
            $max = $constraint?->max_quantity;
        @endphp

        <div class="rate-row" wire:key="rate-{{ $rate->id }}">
            <div class="rate-info">
                <span class="rate-title">{{ $rate->title }}</span>
                <span class="rate-price">{{ $rate->calculatePrice($basePrice, $entryId)->format() }}/night</span>
                @if($rate->description)
                    <span class="rate-description">{{ $rate->description }}</span>
                @endif
            </div>

            <div class="quantity-controls">
                <button
                    type="button"
                    wire:click="decrementRate({{ $rate->id }})"
                    @disabled($quantity <= $min)
                    class="qty-btn decrement"
                >−</button>

                <span class="quantity">{{ $quantity }}</span>

                <button
                    type="button"
                    wire:click="incrementRate({{ $rate->id }})"
                    @disabled($max !== null && $quantity >= $max)
                    class="qty-btn increment"
                >+</button>
            </div>
        </div>
    @endforeach

    @error('rates')
        <div class="error">{{ $message }}</div>
    @enderror

    <div class="totals">
        <div class="total-guests">
            {{ $this->totalQuantity }} {{ __('guests selected') }}
        </div>
        <div class="total-price">
            {{ __('Total') }}: {{ $this->totalPrice->format() }}
        </div>
    </div>
</div>
```

### New Form Objects

#### `AvailabilityData` Changes

```php
// Replace single quantity/rate with multi-rate support
public array $rate_quantities = [];  // [rate_id => quantity]

// Computed properties
public function getTotalQuantity(): int
{
    return array_sum($this->rate_quantities);
}

public function validate(): void
{
    // Existing date validation...

    // Validate rate quantities
    if (empty($this->rate_quantities)) {
        throw new AvailabilityException('At least one rate must be selected.');
    }

    // Validate total quantity > 0
    if ($this->getTotalQuantity() <= 0) {
        throw new AvailabilityException('Total quantity must be greater than 0.');
    }

    // Validate each rate exists and is assigned to entry
    foreach ($this->rate_quantities as $rateId => $quantity) {
        if ($quantity <= 0) continue;

        $rate = Rate::find($rateId);
        if (!$rate || !$rate->entries()->where('entry_id', $this->entry_id)->exists()) {
            throw new AvailabilityException("Invalid rate selected: {$rateId}");
        }
    }

    // Validate constraints
    $validator = app(RateConstraintValidator::class);
    $validator->validate($this->rate_quantities, $this->entry_id);
}
```

#### `RateQuantitiesData` - New Form Object

```php
namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;

class RateQuantitiesData extends Form
{
    public array $quantities = [];  // [rate_id => quantity]

    public function fill(array $data): void
    {
        $this->quantities = $data;
    }

    public function toArray(): array
    {
        return array_filter($this->quantities, fn ($qty) => $qty > 0);
    }

    public function totalQuantity(): int
    {
        return array_sum($this->quantities);
    }

    public function isEmpty(): bool
    {
        return $this->totalQuantity() === 0;
    }
}
```

---

## Phase 8: API Changes

### Availability API Response Changes

**Before:**
```json
{
  "data": {
    "price": "100.00",
    "original_price": null,
    "payment": "100.00",
    "property": "standard"
  }
}
```

**After:**
```json
{
  "data": {
    "price": "100.00",
    "original_price": null,
    "payment": "100.00",
    "property": "standard",
    "rate": {
      "id": 1,
      "slug": "adult",
      "title": "Adult Rate"
    },
    "rates": [
      {
        "id": 1,
        "slug": "adult",
        "title": "Adult Rate",
        "price": "100.00",
        "original_price": null,
        "is_default": true
      },
      {
        "id": 2,
        "slug": "child",
        "title": "Child Rate",
        "price": "50.00",
        "original_price": null,
        "is_default": false
      }
    ]
  }
}
```

### Reservation API Changes

**Before:**
```json
{
  "id": 123,
  "status": "confirmed",
  "quantity": 2,
  "price": "200.00",
  "item_id": "entry-uuid"
}
```

**After:**
```json
{
  "id": 123,
  "status": "confirmed",
  "total_quantity": 4,
  "price": "300.00",
  "item_id": "entry-uuid",
  "rates": [
    {
      "rate_id": 1,
      "rate_slug": "adult",
      "rate_title": "Adult Rate",
      "quantity": 2,
      "unit_price": "100.00",
      "subtotal": "200.00"
    },
    {
      "rate_id": 2,
      "rate_slug": "child",
      "rate_title": "Child Rate",
      "quantity": 2,
      "unit_price": "50.00",
      "subtotal": "100.00"
    }
  ]
}
```

### Checkout Request Changes

**Before:**
```json
{
  "date_start": "2024-06-01",
  "date_end": "2024-06-05",
  "quantity": 2,
  "advanced": "standard-room"
}
```

**After:**
```json
{
  "date_start": "2024-06-01",
  "date_end": "2024-06-05",
  "rate_quantities": {
    "1": 2,
    "2": 2
  },
  "advanced": "standard-room"
}
```

---

## Phase 9: Blade Views

### New Views

```
resources/views/livewire/
├── rate-selector.blade.php
└── components/
    └── rate-card.blade.php
```

### Modified Views

#### `availability-results.blade.php`

Add rate selection UI:

```blade
@if($this->availableRates->count() > 1)
    <div class="resrv-rate-selector">
        @foreach($this->rates as $rateData)
            <button
                wire:click="selectRate({{ $rateData['rate']->id }})"
                class="{{ $selectedRateId === $rateData['rate']->id ? 'selected' : '' }}"
            >
                <span class="rate-title">{{ $rateData['rate']->title }}</span>
                <span class="rate-price">{{ $rateData['pricing']['price']->format() }}</span>
                @if($rateData['pricing']['original_price'])
                    <span class="rate-original">{{ $rateData['pricing']['original_price']->format() }}</span>
                @endif
            </button>
        @endforeach
    </div>
@endif
```

---

## Phase 10: Configuration

### Config Changes

```php
// config/config.php

/**
 * Rate settings
 */
'enable_rates' => true,
'show_rate_selector_in_results' => true,  // vs. at checkout
'default_rate_slug' => 'standard',        // Fallback when no rates assigned
```

---

## Phase 11: Migration Tool

### `MigrateToRatesCommand`

```
src/Console/Commands/MigrateToRatesCommand.php
```

This command helps users migrate from the property-only system:

```php
class MigrateToRatesCommand extends Command
{
    protected $signature = 'resrv:migrate-to-rates
                            {--dry-run : Show what would be migrated without making changes}
                            {--create-default-rate : Create a default "Standard" rate for all entries}';

    public function handle(): int
    {
        $this->info('Resrv Rate Migration Tool');
        $this->info('========================');

        // Step 1: Analyze current setup
        $this->analyzeCurrentSetup();

        // Step 2: Create default rate if requested
        if ($this->option('create-default-rate')) {
            $this->createDefaultRate();
        }

        // Step 3: Migrate existing reservations
        $this->migrateReservations();

        // Step 4: Summary
        $this->showSummary();

        return Command::SUCCESS;
    }

    protected function createDefaultRate(): void
    {
        $rate = Rate::create([
            'slug' => 'standard',
            'title' => 'Standard Rate',
            'description' => 'Default rate (migrated)',
            'parent_rate_id' => null,
            'availability_type' => 'shared',
            'order' => 0,
            'published' => true,
        ]);

        // Assign to all entries with availability
        $entries = Entry::whereNotNull('item_id')->get();
        foreach ($entries as $entry) {
            $entry->rates()->attach($rate->id, ['is_default' => true]);
        }

        $this->info("Created default rate and assigned to {$entries->count()} entries.");
    }

    protected function migrateReservations(): void
    {
        // Find reservations without rate breakdown
        $reservations = Reservation::whereDoesntHave('rates')->get();

        if ($reservations->isEmpty()) {
            $this->info('No reservations to migrate.');
            return;
        }

        $defaultRate = Rate::where('slug', 'standard')->first();

        if (!$defaultRate) {
            $this->warn('No default rate found. Run with --create-default-rate first.');
            return;
        }

        $count = 0;
        foreach ($reservations as $reservation) {
            if (!$this->option('dry-run')) {
                // Migrate quantity to rate breakdown
                $reservation->rates()->attach($defaultRate->id, [
                    'quantity' => $reservation->quantity ?? 1,
                    'unit_price' => $reservation->price->format() / ($reservation->quantity ?? 1),
                    'subtotal' => $reservation->price->format(),
                ]);
            }
            $count++;
        }

        $action = $this->option('dry-run') ? 'Would migrate' : 'Migrated';
        $this->info("{$action} {$count} reservations to multi-rate format.");
    }

    protected function showMigrationSummary(): void
    {
        $this->newLine();
        $this->info('Migration Summary:');
        $this->table(
            ['Item', 'Count'],
            [
                ['Rates created', Rate::count()],
                ['Entries with rates', EntryRate::distinct('entry_id')->count()],
                ['Reservations migrated', Reservation::has('rates')->count()],
            ]
        );
    }
}
```

---

## Phase 12: Testing

### New Test Files

```
tests/
├── Rates/
│   ├── RateModelTest.php
│   ├── RateServiceTest.php
│   ├── RatePricingCalculatorTest.php
│   ├── RateAvailabilityTest.php
│   └── RateCpTest.php
├── Livewire/
│   └── RateSelectorTest.php
└── Migration/
    └── MigrateToRatesCommandTest.php
```

### Key Test Scenarios

1. **Rate Pricing Calculations**
   - Base rate returns calendar/fixed price
   - Linked rate with percentage decrease
   - Linked rate with fixed increase
   - Entry-specific link amount override
   - Date-specific price override

2. **Rate Availability**
   - Shared availability decrements base pool
   - Independent availability has own pool
   - Limited availability respects limit
   - Disabled dates for specific rates

3. **Multi-Rate Reservations**
   - Booking 2 adults + 2 children calculates correct total
   - Rate constraints enforced (min/max/requires)
   - Availability consumption respects `consumes_availability` flag
   - Mixed availability types (shared + independent) work together

4. **Availability Consumption Scenarios**
   - Hotel (occupancy): 2 adults + 2 children = 1 room consumed
   - Excursion (seats): 2 adults + 1 child = 3 seats consumed
   - Limited rates: Respects per-rate limits while consuming shared pool
   - Independent rates: Uses separate pool, doesn't affect base

5. **Rate Constraint Validation**
   - Fails when min_quantity not met
   - Fails when max_quantity exceeded
   - Fails when required rate missing (child without adult)
   - Passes with valid combination

6. **Integration Tests**
   - Full booking flow with multi-rate selection
   - Rate quantity changes update pricing correctly
   - Expiration restores correct availability for all rate types
   - Checkout displays correct rate breakdown

---

## Phase 13: Deprecation of Connected Availabilities

### Deprecation Strategy

The existing "Connected Availabilities" feature is complex and has overlapping concerns with the new rate system. Proposed approach:

1. **v4.0**: Mark as deprecated with console warning
2. **v4.x**: Provide migration path
3. **v5.0**: Remove feature entirely

### Migration Path

For users of Connected Availabilities:

1. **"All availabilities of same entry"** → Use shared rate availability
2. **"Same slug across entries"** → Use rate with shared availability + entry grouping
3. **Manual connections** → Consider if rates solve the actual use case

---

## Implementation Order

### Sprint 1: Core Foundation
1. Database migrations
2. Rate model
3. RateAvailability model
4. Basic RateService

### Sprint 2: Pricing Integration
1. RatePricingCalculator
2. Modify HandlesPricing trait
3. Integrate with existing Fixed/Dynamic pricing

### Sprint 3: Availability Integration
1. Modify AvailabilityRepository
2. Handle availability modes (shared/independent/limited)
3. Update DecreaseAvailability/IncreaseAvailability listeners

### Sprint 4: Control Panel
1. Rate CRUD pages
2. Entry rate assignment
3. Rate availability overrides UI

### Sprint 5: Frontend
1. Modify AvailabilityResults
2. RateSelector component
3. Update Checkout flow
4. Blade views

### Sprint 6: Migration & Polish
1. Migration command
2. Documentation
3. Test coverage
4. Performance optimization

---

## Breaking Changes

1. **API Response Structure**: Availability responses now include `rate` and `rates` keys
2. **Reservation Model**: `quantity` field deprecated, replaced by `rates()` relationship with quantities per rate
3. **Checkout Flow**: Rate quantity selection required (replaces single quantity input)
4. **Request Format**: `quantity` parameter replaced by `rate_quantities` array
5. **Config**: New `enable_rates` option (defaults to true)
6. **Availability Calculation**: Now considers rate constraints and per-rate availability modes

### Upgrade Guide (for UPGRADE.md)

```markdown
## Upgrading to v4.0

### Database Migrations

Run migrations:
```bash
php artisan migrate
```

### Create Default Rate

If you have existing data:
```bash
php artisan resrv:migrate-to-rates --create-default-rate
```

### Frontend Changes

If you've customized Livewire views, update them to include rate selection.

### API Consumers

Update your API integrations to handle the new `rate` and `rates` fields.
```

---

## Future Enhancements (Out of Scope for v4.0)

1. **Rate-specific Dynamic Pricing**: Apply discounts only to certain rates
2. **Rate validity periods**: "Early Bird" available only 30+ days out
3. **Occupancy-based pricing**: Price per person calculations
4. **Rate categories**: Public, Corporate, Member rates with visibility rules
5. **Rate-specific extras**: Different extras available per rate
6. **Package Pricing via Dynamic Pricing**: Special discounts when specific rate combinations are booked

### Package Pricing Design (Future)

Package pricing can be implemented as a Dynamic Pricing extension:

```php
// New condition type for Dynamic Pricing
'condition_type' => 'rate_combination',
'condition_value' => [
    'rates' => [
        ['rate_id' => 1, 'min_quantity' => 2],  // 2+ adults
        ['rate_id' => 2, 'min_quantity' => 2],  // 2+ children
    ],
    'match' => 'all',  // 'all' or 'any'
],
'amount_type' => 'fixed',
'amount_operation' => 'decrease',
'amount' => 50,  // €50 family discount
```

**Example Packages:**
- "Family Package": 2 adults + 2 children = €50 off
- "Group Discount": 5+ adults = 10% off
- "Couple Special": Exactly 2 adults = free breakfast (via extras)
