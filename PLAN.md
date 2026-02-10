# Rate System: Major Rewrite Plan

## Context

The current property system (`resrv_availabilities.property` column) is used for physical variants (yacht bases, time slots, cabin types, routes) but lacks pricing linking between variants. Connected Availabilities tries to sync availability across properties/entries but is complex and unreliable (5 sync types, 3 update strategies, event-driven cascading).

**Goal:** Replace both systems with a unified **Rate** model that supports:
- Independent or relative pricing (e.g., -20%, +EUR 20 from a base rate)
- Independent or shared availability pools
- Date range + rule-based restrictions
- Configurable multi-rate bookings (hotel: one rate, excursion: adult+child)

**Not in scope (Phase 2):** Cross-entry availability sharing (the `entries` type in connected availabilities). The Rate system handles all within-entry cases. Cross-entry pooling can be reimplemented in a simpler form later.

---

## Database Schema

### New table: `resrv_rates`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint unsigned PK | |
| statamic_id | varchar(255) | Entry ID, indexed |
| title | varchar(255) | Display name |
| slug | varchar(255) | URL-safe identifier |
| description | text, nullable | |
| **Pricing** | | |
| pricing_type | varchar(20), default 'independent' | `independent` or `relative` |
| base_rate_id | bigint unsigned, nullable | FK to self (for relative pricing) |
| modifier_type | varchar(20), nullable | `percent` or `fixed` |
| modifier_operation | varchar(20), nullable | `increase` or `decrease` |
| modifier_amount | decimal(10,2), nullable | |
| **Availability** | | |
| availability_type | varchar(20), default 'independent' | `independent` or `shared` |
| max_available | int, nullable | Cap on shared pool for this rate |
| **Restrictions** | | |
| date_start | date, nullable | Rate offered from |
| date_end | date, nullable | Rate offered until |
| min_days_before | int, nullable | Booking lead time |
| min_stay | int, nullable | Override global minimum |
| max_stay | int, nullable | Override global maximum |
| **Policy** | | |
| refundable | boolean, default true | Rate-level cancellation policy |
| **Meta** | | |
| order | int, default 0 | |
| published | boolean, default true | |
| deleted_at | timestamp, nullable | Soft deletes |
| created_at, updated_at | timestamps | |

**Indexes:** `(statamic_id)`, `UNIQUE (statamic_id, slug)`, `(base_rate_id)`

### Modified: `resrv_availabilities`
- Add `rate_id` (bigint unsigned), migrate from `property`, drop `property`
- Update unique index: `(statamic_id, date, rate_id)`

### Modified: `resrv_reservations`
- Add `rate_id` (bigint unsigned), migrate from `property`, drop `property`

### Modified: `resrv_child_reservations`
- Add `rate_id` (bigint unsigned), migrate from `property`, drop `property`

### Modified: `resrv_fixed_pricing`
- Add `rate_id` (bigint unsigned, nullable)
- Update unique: `(statamic_id, days, rate_id)`

### Dynamic pricing: No schema change
- `resrv_dynamic_pricing_assignments` already supports polymorphic types
- Add `Rate` as a new `dynamic_pricing_assignment_type`

---

## How It Works

### Relative rate pricing
1. Get base rate's daily prices from `resrv_availabilities`
2. Apply modifier: `base_price * (1 - 0.20)` for -20%, or `base_price + 20.00` for +EUR 20
3. Apply fixed pricing overrides for this rate (if any)
4. Apply dynamic pricing rules

### Shared availability booking
1. Resolve the rate's base rate (via `base_rate_id`)
2. Check base rate's `resrv_availabilities.available` count
3. If rate has `max_available`, check existing bookings for this rate don't exceed cap
4. **Decrement the base rate's** availability (not the shared rate's) within a DB transaction
5. Store reservation with the shared rate's `rate_id`

This replaces the event-driven connected availability system with a transactional approach.

### Multi-rate bookings (configurable)
- Fieldtype setting: `enable_multi_rate_booking` (default false)
- When **false**: UI shows radio buttons, one rate per reservation
- When **true**: UI shows quantity per rate. Creates a parent `Reservation` (type=parent) with `ChildReservation` records per rate (reuses existing system)

### Default rate
Every entry gets a "Default" rate on creation. Entries with a single rate behave identically to the current standard mode - rate selection UI is hidden.

---

## Cross-Entry Availability Sharing (Deferred to Phase 2)

### What's Being Removed
The current connected availabilities system supports 5 sync types:
- `all` - sync all properties within the same entry (replaced by shared rates)
- `same_slug` - sync same property slug across entries (cross-entry)
- `specific_slugs` - sync specific slugs across entries (cross-entry)
- `select` - manual property-to-property mapping within entry (replaced by shared rates)
- `entries` - link groups of entries together (cross-entry)

The Rate system fully replaces `all` and `select` (within-entry sharing). The three cross-entry types (`same_slug`, `specific_slugs`, `entries`) have no equivalent in Phase 1.

### Who Is Affected
Users who have entries connected across different Statamic items. The primary use case: excursion companies where multiple excursion types (entries) share a vehicle (bus/boat) with limited seats. When someone books Excursion A, Excursion B's availability should decrease.

### Workaround Until Phase 2
Users with cross-entry needs can:
1. Manually manage availability across entries in the admin CP
2. Use a custom listener on `ReservationCreated`/`ReservationCancelled` events to sync availability between entries (the events still exist, only the connected availability listener is removed)
3. Consolidate related excursions into a single entry with multiple rates (recommended when possible)

### Phase 2 Design Direction: Availability Pools
The planned replacement is an "Availability Pool" concept:
- A pool has a name and a total capacity (e.g., "Morning Bus - 40 seats")
- Multiple entries can join a pool, each consuming N seats per booking
- Pool availability is tracked in a dedicated table, decremented transactionally
- Much simpler than the current 5-type system: one concept, one table, one behavior

### Migration Guidance for Affected Users
The `resrv:upgrade-to-rates` command will:
1. Detect entries with `connected_availabilities` config of type `same_slug`, `specific_slugs`, or `entries`
2. Output a warning listing these entries and their connected groups
3. Suggest the workaround options above
4. NOT delete the blueprint config (it becomes inert but preserved for reference)

---

## Additional Feature: Rate-Level Cancellation Policy

The `refundable` boolean on `Rate` enables the non-refundable rate use case. The checkout and refund logic should check `$reservation->rate->refundable` instead of (or in addition to) the global `free_cancellation_period` config. This lets users offer a cheaper non-refundable rate alongside the standard refundable one.

---

## Future Considerations (Beyond Phase 2)

1. **Rate-specific extras** - only show certain extras for certain rates
2. **Rate groups/categories** - group rates visually (e.g., "Room Types" vs "Special Offers")
3. **Rate-specific checkout forms** - different data collected per rate
4. **Rate inheritance chains** - rate B relative to rate A relative to base (currently one level only)

---

## Verification

1. Run `composer test` - all tests pass after updates
2. Run `composer test-pgsql` - PostgreSQL compatibility
3. Create test entry via admin CP, add rates (independent + relative + shared)
4. Verify availability calendar shows per-rate data
5. Test frontend search with rate selection
6. Test checkout flow with single-rate and multi-rate modes
7. Test shared availability decrement/increment with concurrent bookings
8. Run `resrv:upgrade-to-rates --dry-run` on a database with existing property data
9. Run the actual upgrade and verify data integrity
10. `vendor/bin/pint` - code style
