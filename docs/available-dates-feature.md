# Available Dates Feature

This feature allows you to display all available dates from a starting date, ignoring the end date. It's ideal for scenarios like cruise ships, tours, or events where departures happen on specific dates and you want to show users all upcoming availability.

## Overview

Instead of searching for availability between two dates, this feature:
- Takes a start date and shows **all dates with availability** from that point forward
- Groups results by property (cabin type, room type, etc.) or by date
- Shows pricing and remaining capacity for each available date

## Components

This feature is provided by a dedicated Livewire component: `availability-list`. This keeps the logic separate from the standard `availability-results` component, reducing overhead when you don't need the available dates functionality.

## Basic Usage

```blade
<livewire:availability-list 
    :entry="$entry->id()" 
/>
```

## Parameters

### `entry` (string, required)

The entry ID to show available dates for.

```blade
<livewire:availability-list 
    :entry="$entry->id()" 
/>
```

### `groupByDate` (bool, default: `false`)

Controls how results are structured when using advanced properties (multiple room/cabin types).

**When `false` (default)** - Results are grouped by property:
```php
[
    'interior-cabin' => [
        '2026-05-07' => ['price' => '1200.00', 'available' => 4],
        '2026-06-16' => ['price' => '1100.00', 'available' => 6],
    ],
    'balcony-cabin' => [
        '2026-05-07' => ['price' => '2400.00', 'available' => 2],
        '2026-06-16' => ['price' => '2200.00', 'available' => 3],
    ],
]
```

**When `true`** - Results are grouped by date (recommended for cruise/tour scenarios):
```php
[
    '2026-05-07' => [
        'interior-cabin' => ['price' => '1200.00', 'available' => 4],
        'balcony-cabin' => ['price' => '2400.00', 'available' => 2],
    ],
    '2026-06-16' => [
        'interior-cabin' => ['price' => '1100.00', 'available' => 6],
        'balcony-cabin' => ['price' => '2200.00', 'available' => 3],
    ],
]
```

```blade
<livewire:availability-list 
    :entry="$entry->id()" 
    :groupByDate="true"
    :advanced="true"
/>
```

### `advanced` (bool|string, default: `false`)

Controls advanced property (multi-room/cabin) behavior:

- `false` - No advanced properties, single availability type
- `true` - Show all properties, user can select any (`advanced: 'any'`)
- `'property-slug'` - Filter to a specific property only

```blade
{{-- Show all cabin types --}}
<livewire:availability-list 
    :entry="$entry->id()" 
    :advanced="true"
/>

{{-- Show only balcony cabins --}}
<livewire:availability-list 
    :entry="$entry->id()" 
    :advanced="'balcony-cabin'"
/>
```

### `overrideProperties` (array, default: `[]`)

Override the property labels returned by the `advancedProperties` computed property. Pass an array of property slug to label mappings.

```blade
<livewire:availability-list
    :entry="$entry->id()"
    :advanced="true"
    :overrideProperties="['balcony-cabin' => 'Balcony Cabin', 'interior-cabin' => 'Interior Cabin']"
/>
```

### `view` (string, default: based on `groupByDate`)

The blade view to use for rendering. Automatically selected based on `groupByDate`:
- `groupByDate: false` → `availability-list` (property-first)
- `groupByDate: true` → `availability-list-by-date` (date-first)

You can override with your own view:

```blade
<livewire:availability-list 
    :entry="$entry->id()" 
    :view="'my-custom-dates-view'"
/>
```

## Blade Views

Two blade views are provided:

### `availability-list` (Property-first)

Default view when `groupByDate` is false. Shows properties as sections with their available dates listed underneath.

Best for: Comparing pricing across dates for a specific room type.

### `availability-list-by-date` (Date-first)

Used when `groupByDate` is true. Shows dates as sections with available properties for each date.

Best for: Cruise ships, tours, events - where users pick a departure date first, then choose their cabin/room type.

## Search Integration

The component listens to the `availability-search-updated` event dispatched by the search form. When a search is performed:

1. The search form dispatches `availability-search-updated` with the search data
2. The `availability-list` component receives the event and queries available dates
3. Results are stored in `$availableDates` collection
4. After processing, the component dispatches `availability-results-updated`

## Date Selection Flow

When a user selects a date from the available dates list, the component provides a `selectDate` method that dispatches an `availability-date-selected` event:

```blade
<div wire:click="selectDate('{{ $date }}', '{{ $property }}')">
    {{ $date }}: {{ $info['price'] }}
</div>
```

The `AvailabilitySearch` component listens to this event and automatically updates the search dates:

1. **`availability-list`** calls `selectDate($date, $property)`
2. **`availability-list`** dispatches `availability-date-selected` event with `['date' => $date, 'property' => $property]`
3. **`availability-search`** receives the event and:
   - Sets `date_start` to the selected date
   - Sets `date_end` based on `minimum_reservation_period_in_days` config (defaults to 1 day)
   - Sets the `advanced` property if provided
   - Triggers a new search, dispatching `availability-search-updated`
4. **`availability-results`** receives the updated search and shows availability for that specific date

This creates a seamless flow where users can:
1. Browse available dates in the list
2. Click a date to select it
3. See the search form and results update automatically

## Example: Cruise Ship Booking

A cruise ship with 6 cabin types and departures on specific dates:

```blade
<livewire:availability-list 
    :entry="$cruise->id()" 
    :groupByDate="true"
    :advanced="true"
/>
```

**Search form sends:**
```php
[
    'dates' => [
        'date_start' => '2026-04-01',  // Show departures from April 2026
        'date_end' => '2026-12-31',    // Ignored in this mode
    ],
    'quantity' => 2,      // Need 2 cabins
    'advanced' => 'any',  // Show all cabin types
]
```

**Result:**
```php
[
    '2026-05-07' => [
        'interior' => ['price' => '1200.00', 'available' => 4],
        'ocean-view' => ['price' => '1800.00', 'available' => 3],
        'balcony' => ['price' => '2400.00', 'available' => 2],
        'suite' => ['price' => '4800.00', 'available' => 1],
    ],
    '2026-05-23' => [
        'interior' => ['price' => '1200.00', 'available' => 2],
        // ocean-view sold out for this departure
        'balcony' => ['price' => '2400.00', 'available' => 1],
    ],
    '2026-06-16' => [
        'interior' => ['price' => '1100.00', 'available' => 6],
        'ocean-view' => ['price' => '1700.00', 'available' => 4],
        'balcony' => ['price' => '2200.00', 'available' => 3],
        'suite' => ['price' => '4500.00', 'available' => 2],
    ],
]
```

## Checkout Flow

### Standard Mode (property-first)

Users browse by property, then select a date. In your view, you can add click handlers to initiate checkout:

```blade
@foreach ($availableDates as $property => $dates)
    <h3>{{ $this->advancedProperties[$property] ?? $property }}</h3>
    @foreach ($dates as $date => $info)
        <div wire:click="selectDate('{{ $date }}', '{{ $property }}')">
            {{ $date }}: {{ $info['price'] }} 
            ({{ $info['available'] }} left)
        </div>
    @endforeach
@endforeach
```

### Date-first Mode (`groupByDate: true`)

Users see all departure dates with available cabins/rooms for each date:

```blade
@foreach ($availableDates as $date => $properties)
    <h3>{{ \Carbon\Carbon::parse($date)->format('D d M Y') }}</h3>
    @foreach ($properties as $property => $info)
        <div wire:click="selectDate('{{ $date }}', '{{ $property }}')">
            {{ $this->advancedProperties[$property] ?? $property }}: 
            {{ $info['price'] }} ({{ $info['available'] }} left)
        </div>
    @endforeach
@endforeach
```

## Accessing Data in Views

```blade
{{-- Check if we have available dates --}}
@if ($availableDates->isNotEmpty())
    
    {{-- Property-first structure (groupByDate: false) --}}
    @foreach ($availableDates as $property => $dates)
        <h3>{{ $this->advancedProperties[$property] ?? $property }}</h3>
        @foreach ($dates as $date => $info)
            <div>
                {{ $date }}: {{ $info['price'] }} 
                ({{ $info['available'] }} left)
            </div>
        @endforeach
    @endforeach

@endif
```

```blade
{{-- Date-first structure (groupByDate: true) --}}
@if ($availableDates->isNotEmpty())
    @foreach ($availableDates as $date => $properties)
        <h3>{{ \Carbon\Carbon::parse($date)->format('D d M Y') }}</h3>
        @foreach ($properties as $property => $info)
            <div>
                {{ $this->advancedProperties[$property] ?? $property }}: 
                {{ $info['price'] }} ({{ $info['available'] }} left)
            </div>
        @endforeach
    @endforeach
@endif
```

## Computed Properties

### `advancedProperties`

Returns an array of property slugs mapped to their labels:

```php
[
    'interior-cabin' => 'Interior Cabin',
    'balcony-cabin' => 'Balcony Cabin',
]
```

Access in views:
```blade
{{ $this->advancedProperties[$property] ?? $property }}
```

### `entry`

Returns the Statamic entry object:

```blade
{{ $this->entry->title }}
```

## Hooks

The component supports hooks at these points:

- `init` - After mount, before initial data load

```php
// In a service provider or component extension
AvailabilityList::hook('init', function ($component) {
    // Customize component behavior
});
```

## Translation Keys

Add these to your language files:

```php
// resources/lang/en/frontend.php
'availableDates' => 'Available dates',
'availableDatesFrom' => 'Available from',
'noAvailableDates' => 'No available dates',
'pleaseSelectStartDate' => 'Please select a start date',
'available' => 'available',
'optionsAvailable' => 'option available|options available',
'only' => 'Only',
'left' => 'left',
```

## Model Method

The underlying model method can also be used directly:

```php
use Reach\StatamicResrv\Models\Availability;

$results = Availability::getAvailableDatesFromDate(
    id: $entryId,
    dateStart: '2026-04-01',
    quantity: 2,
    advanced: ['any'],      // or ['specific-property']
    groupByDate: true       // or false
);
```

## Performance Notes

- The `availability-list` component is separate from `availability-results`, so including it only adds overhead when actually used
- Results are filtered by quantity at the database level
- Only dates with `available >= quantity` are returned
- When no session data exists, the component renders empty until a search is performed
