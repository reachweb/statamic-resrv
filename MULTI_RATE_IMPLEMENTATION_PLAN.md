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

### Table Modifications

#### `resrv_reservations` - Add Rate Reference

```php
Schema::table('resrv_reservations', function (Blueprint $table) {
    $table->foreignId('rate_id')
          ->nullable()
          ->after('property')
          ->constrained('resrv_rates')
          ->nullOnDelete();
});
```

#### `resrv_child_reservations` - Add Rate Reference

```php
Schema::table('resrv_child_reservations', function (Blueprint $table) {
    $table->foreignId('rate_id')
          ->nullable()
          ->after('property')
          ->constrained('resrv_rates')
          ->nullOnDelete();
});
```

### Migration Order

1. `create_resrv_rates_table.php`
2. `create_resrv_entry_rate_table.php`
3. `create_resrv_rate_availabilities_table.php`
4. `add_rate_id_to_resrv_reservations_table.php`
5. `add_rate_id_to_resrv_child_reservations_table.php`

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
- `rate()` - BelongsTo `Rate`

**New Methods:**
- `getRateLabel(): string`

**Modified Methods:**
- `getPrices()` - Include rate in calculation

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

#### `RateSelector`

```
src/Livewire/RateSelector.php
```

Standalone rate selection component:

```php
class RateSelector extends Component
{
    use HandlesRates;

    #[Locked]
    public string $entryId;

    public ?int $selectedRateId = null;

    public AvailabilityData $data;

    #[Computed]
    public function rates(): Collection
    {
        return $this->calculateRatePrices(
            $this->entryId,
            $this->data->date_start,
            $this->data->date_end,
            $this->data->quantity,
            $this->data->advanced
        );
    }

    public function selectRate(int $rateId): void
    {
        $this->selectedRateId = $rateId;
        $this->dispatch('rate-selected', $rateId);
    }

    public function render()
    {
        return view('statamic-resrv::livewire.rate-selector');
    }
}
```

### New Form Objects

#### `AvailabilityData` Changes

```php
// Add rate support
public ?int $rate_id = null;

public function validate(): void
{
    // Existing validation...

    // Validate rate if provided
    if ($this->rate_id) {
        $rate = Rate::find($this->rate_id);
        if (!$rate || !$rate->entries()->where('entry_id', $this->entry_id)->exists()) {
            throw new AvailabilityException('Invalid rate selected.');
        }
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

Add rate information to reservation endpoints.

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
        // Find reservations without rate_id
        $reservations = Reservation::whereNull('rate_id')->get();

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
                $reservation->update(['rate_id' => $defaultRate->id]);
            }
            $count++;
        }

        $action = $this->option('dry-run') ? 'Would migrate' : 'Migrated';
        $this->info("{$action} {$count} reservations to default rate.");
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

3. **Integration Tests**
   - Full booking flow with rate selection
   - Rate change during checkout
   - Expiration restores correct availability

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
2. **Reservation Model**: New `rate_id` relationship
3. **Checkout Flow**: Rate selection required when multiple rates available
4. **Config**: New `enable_rates` option (defaults to true)

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
4. **Rate packages**: Bundled rates with included extras
5. **Rate categories**: Public, Corporate, Member rates with visibility rules
6. **Rate-specific extras**: Different extras available per rate
