# Cart-Based Multi-Rate & Multi-Date Booking Flow

The `availability-multi-results` Livewire component enables a cart-based booking experience where users can select quantities per rate, accumulate selections across different date ranges, and checkout as a single reservation.

## Use Cases

- **Multi-rate, same dates**: 2 Adults + 2 Children for a boat tour on July 15
- **Multi-date**: 1 Standard ticket for July 15 + 1 Standard ticket for July 22
- **Combined**: 2 Adults on July 15 + 1 Child on July 22

## How It Works

1. User searches for dates using `availability-search`
2. All available rates are shown with +/- quantity pickers
3. User selects quantities and clicks "Add to booking" — selections go into a cart
4. User can change dates, search again, and add more selections
5. The cart displays all accumulated selections with a running total
6. "Book Now" validates availability for each selection, then creates a single parent reservation with one child reservation per selection

### Backend

Checkout creates:
- One **parent** `Reservation` (type = `parent`) with the total price and overall date span
- One **child** `ChildReservation` per cart selection, each with its own dates, rate, quantity, and price

The existing event-driven lifecycle handles everything from there:
- `DecreaseAvailability::decreaseMultiple()` decrements availability per child
- `IncreaseAvailability::incrementMultiple()` restores availability on expiry/cancellation
- The `Checkout` component works with parent reservations as-is

## Template Setup

### Basic

```antlers
{{ collection:excursions }}
    <livewire:availability-search :entry="id" :rates="true" :any-rate="true" />
    <livewire:availability-multi-results :entry="id" />
{{ /collection:excursions }}
```

The `availability-search` component dispatches an `availability-search-updated` event. The `availability-multi-results` component listens for it and queries all rates for the selected dates.

### With Extras and Options

```antlers
<livewire:availability-search :entry="id" :rates="true" :any-rate="true" />
<livewire:availability-multi-results :entry="id" :show-extras="true" :show-options="true" />
```

### Alongside the Standard Flow

You can offer both flows on different pages. The standard `availability-results` component handles single-rate bookings. The `availability-multi-results` component handles multi-rate/multi-date bookings. They share the same search component and checkout flow.

## Component Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `entry` | string | required | The Statamic entry ID |
| `view` | string | `availability-multi-results` | Override the Blade view |
| `showExtras` | bool/string | `false` | Show extras component (pass `true` or a filter slug) |
| `showOptions` | bool/string | `false` | Show options component (pass `true` or a filter slug) |
| `overrideRates` | array | `[]` | Override the rates shown (keyed by rate ID) |

## Customizing the View

Publish the view to override:

```bash
php artisan vendor:publish --tag=statamic-resrv-views
```

The view is at `resources/views/vendor/statamic-resrv/livewire/availability-multi-results.blade.php`.

Alternatively, set the `view` property to use a completely custom Blade view:

```antlers
<livewire:availability-multi-results :entry="id" view="my-custom-view" />
```

The view receives these properties:
- `$availability` — Collection keyed by rate ID, each with `data`, `message`, and `request`
- `$data` — The current search data (dates, quantity, rate)
- `$rateQuantities` — Array of rate_id => quantity for the current picker state
- `$selections` — Array of cart items, each with `date_start`, `date_end`, `rate_id`, `quantity`, `price`, `rate_label`
- `$this->entryRates` — Array of rate_id => title
- `$this->totalPrice` — Computed total price string
- `$this->totalQuantity` — Computed total quantity int

## Translation Keys

The component uses these translation keys (in `statamic-resrv::frontend`):

| Key | Default |
|-----|---------|
| `addToBooking` | Add to booking |
| `yourBooking` | Your booking |
| `clear` | Clear |
| `pleaseSelectRateToBook` | Please select a rate to book |
| `pleaseSelectDates` | Please select dates to search for availability |
| `noAvailability` | No availability |
| `bookNow` | Book now |
| `total` | Total |
| `searchError` | Search error |

## Migration

The feature adds a `price` column to `resrv_child_reservations`. Run migrations after updating:

```bash
php artisan migrate
```

Existing child reservations will have `price = null`. New child reservations created through the multi-results component will have the price populated.
