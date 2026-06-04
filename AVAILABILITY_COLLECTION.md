# Collection-wide availability (`availability-collection`)

The `availability-collection` Livewire component shows the availability of **many entries
at once** — a curated set of entries, or every published entry in a collection — for the
dates the visitor searched. It is live (no page reload) and needs **no extra package**.

It pairs with `availability-search`: the search component dispatches an
`availability-search-updated` event, and `availability-collection` listens for it, runs a
single batched availability query for the whole set, and renders one row per entry with a
price and a booking action.

## When to use which component

| You want… | Use |
|---|---|
| Availability of **one** entry on its detail page | `availability-results` |
| A list of **upcoming available dates** for one entry | `availability-list` |
| A multi-rate / multi-date **cart** for one entry | `availability-multi-results` |
| **Many entries** of a collection, live, no extra package | **`availability-collection`** |
| Many entries, with availability as one **filter facet** alongside taxonomies/ranges | `lf-availability-filter` (requires `marcorieser/statamic-livewire-filters`) |
| Many entries, server-side, full page reload acceptable | the `resrv_search` collection-tag query scope |

`availability-collection` is the no-package, live answer to "show me the entries available
for these dates". For richer faceted filtering, reach for `lf-availability-filter`.

## Template setup

### All published entries in a collection

```antlers
<livewire:availability-search />
<livewire:availability-collection collection="rooms" />
```

### A curated subset (explicit entry IDs)

```antlers
<livewire:availability-collection :entries="['id-1', 'id-2', 'id-3']" />
```

### Collection + IDs (intersection)

Passing both narrows to the given IDs **within** that collection:

```antlers
<livewire:availability-collection collection="rooms" :entries="$featured_ids" />
```

### With rates, pagination and price sorting

```antlers
<livewire:availability-search :rates="true" :any-rate="true" />
<livewire:availability-collection
    collection="rooms"
    :rates="true"
    :show-rates="true"
    sort="price"
    rate-sorting="price"
    :paginate="12"
/>
```

`sort` orders the **entries**; `rate-sorting` orders the **rates within** each entry. They
are independent — see the properties table below.

## Component properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `collection` | string | `null` | Collection handle. Required unless `entries` is given. |
| `entries` | array | `[]` | Explicit entry IDs. Combined with `collection`, the two intersect. |
| `rates` | bool | `false` | Resolve per-entry rate labels. |
| `showRates` | bool | `false` | `true` lists every available rate (with its price) per entry; `false` shows a single cheapest "from" price. |
| `showUnavailable` | bool | `false` | `false` hides sold-out entries; `true` renders them with a "No availability" note. |
| `paginate` | int | `null` | Page size. Uses Livewire pagination; availability is still fetched in one query per page. |
| `sort` | string | `'order'` | Orders the **entries**: `'order'` (collection order), `'title'`, or `'price'` (cheapest first). |
| `rateSorting` | string | `'order'` | Orders the **rates within** each entry: `'order'` (each rate's configured `Rate.order`, matching `availability-results`) or `'price'` (cheapest first). Distinct from `sort`. |
| `overrideRates` | array | `[]` | Override the `rate_id => label` map instead of resolving from the rates. |
| `view` | string | `availability-collection` | Use a fully custom Blade view. |

## What happens when a visitor selects an entry

`select($entryId, $rateId)`:

1. The chosen date/quantity/rate are written to the shared `resrv-search` session.
2. **If the entry has a detail page** (`$entry->url()` is set), the visitor is redirected
   there. The detail page's `availability-results` (or `availability-multi-results`)
   rehydrates from the session and continues the booking.
3. **If the entry has no detail page**, a reservation is created immediately and the visitor
   is sent straight to the checkout entry.

## Customizing the view

Publish the views to override:

```bash
php artisan vendor:publish --tag=resrv-checkout-views
```

…then edit `resources/views/vendor/statamic-resrv/livewire/availability-collection.blade.php`.
Or point the component at your own view: `<livewire:availability-collection ... view="my-view" />`.

The view receives:

- `$this->rows` — a Collection, one row per entry to render:
  - `id` — the entry id (pass to `select()`),
  - `entry` — the Statamic `Entry` (title, url, fields, thumbnail…),
  - `available` — bool,
  - `from` — the cheapest available rate row: `price`, `original_price`, `payment`, `rate_id`, `rateLabel` (formatted price strings),
  - `rates` — a Collection of every available rate row (same shape), for `showRates`.
- `$this->rateLabels` — `rate_id => label` map (when `rates`/`showRates`).
- `$this->resolvedEntries` — the underlying `EntryCollection` or paginator (`->links()` when `paginate` is set).
- `$data` — the current search (`$data->dates`, `quantity`, `rate`, `$data->hasDates()`).

The per-entry `from`/`rates` shape matches the `live_availability` value used by the
Livewire-Filters integration, so templates can be shared.

## Notes & caveats

- **Cutoff rules** are not applied to the at-a-glance list; they are re-checked on `select()`
  for the chosen entry (where a per-entry cutoff is meaningful).
- **Large collections**: every search prunes stale holds and scans reservations. Use
  `paginate` to bound the work — entries are paginated first, so the batched availability
  query only runs for the current page.
- **`sort="price"`** sorts within the resolved set. With `paginate`, that means within the
  current page (entries are paged by collection order/title before availability is known).
- **`rateSorting` defaults to `order`** so a collection listing shows each entry's rates in
  the same sequence as that entry's `availability-results` detail page. Pass
  `rate-sorting="price"` for the previous cheapest-first rate ordering. (This is a behaviour
  change: the listing previously always sorted rates by price.)
- **`sort="price"` with `rate-sorting="order"`** orders entries by each entry's *order-first*
  ("from") price rather than its cheapest price, because the "from" price is the first rate
  under the chosen rate ordering — the same coupling `availability-results` already has.
- **Multisite**: availability is stored against origin entry IDs. The component resolves
  localized entries to their origin automatically; pass either localized or origin IDs in
  `entries`.
