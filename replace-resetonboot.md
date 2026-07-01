# Replace `resetOnBoot` with self-healing rate reconciliation

**Status:** Ready to implement (revised after review round 3)
**Branch suggestion:** `fix/stale-rate-session-reconcile`
**Owner decisions (locked):**
1. **Re-pick is fine** — a foreign/stale rate is dropped to `null`; the user re-selects. No per-collection "memory".
2. **Auto-select single rate** — when the current context has exactly one valid rate, select it outright instead of leaving the search rate-less.
3. **Hard-remove `resetOnBoot`** — delete the property and its `mount()` block now; ship an upgrade note (it is a public tag attribute, so this is a breaking change).

### Corrections folded in across review rounds
- **R1:** collection validity scoped to entries (not whole collection); Search reconciles in `mount()`+`search()`; tests split one-rate vs multi-rate; `overrideRates` shape enforced; lifecycle wording fixed; `LfAvailabilityFilter` verification reassigned.
- **R2:** validity uses full configured scope (pagination-safe); reconcile placed right after `fill()` (before hooks/validation); Results→`'any'` vs List→`null` distinction; `clearDates()` reconciles; orphan rates excluded; release-note file named.
- **R3 (this revision):**
  1. **Listing scope:** `listingRateIds()` now derives validity from the **actual visible entries** — the same query `resolvedEntries()` runs (collection ∩ `entries` ∩ current-site ∩ published-status), unpaginated, mapped to **origin ids** — instead of raw `$this->entries`. Closes cross-collection, private/scheduled, and multisite-localization leaks. The shared query is factored into `scopedEntriesQuery()`.
  2. **Edge table fixed:** a single valid rate is auto-selected and **stays that id** on both Results and List; only a *multi-rate* target (foreign rate dropped → `null`) becomes `'any'` in Results (via `loadAvailability`) while staying `null` in List.
  3. **Hand-off test fixed:** redirecting to a different entry with multiple rates yields `'any'` in Results / `null` in List, not `null` everywhere.

---

## 1. Problem & root cause

`AvailabilityData` (`src/Livewire/Forms/AvailabilityData.php:13-19`) mixes two kinds of state in one Form object persisted under a single global session key `#[Session('resrv-search')]`, shared by six components:

- **Global** — `dates`, `quantity`, `customer` → legitimately carry across entries/collections.
- **Context-scoped** — `rate` (`AvailabilityData.php:17`) → valid for exactly one collection, and possibly only for specific entries within it. Rates are resolved by `Rate::scopeForEntry` (`src/Models/Rate.php:135-153`): `collection = X AND (apply_to_all = true OR pivot resrv_entries.item_id = entryId)`.

**Lifecycle.** Livewire's `#[Session]` reads the key into the property in the attribute's `mount()` hook and writes it back on `dehydrate()` (`vendor/livewire/livewire/src/Features/SupportSession/BaseSession.php:16-28`); during a component's life the value rides the component snapshot, it is **not** re-read each request. The poisoning is **cross-mount**: a `rate_id` written while booking entry A is dehydrated into the shared key; when a *different* component (or a fresh mount) later reads that key in its own `mount()`, it restores entry A's rate and applies it as a hard `WHERE rate_id` filter on entry/collection B's search — matching zero rows and returning empty results **with no error**.

`resetOnBoot` (`src/Livewire/AvailabilitySearch.php:38-39`, `:59-68`) is the **fourth iteration** of a wipe-on-load workaround (`resetAdvancedOnBoot` → `resetOnBoot`; `git log -S resetOnBoot`): **opt-in**, **blunt** (force-resets `quantity=1` and re-searches every first mount), and **localized** to `AvailabilitySearch`.

### Blast radius

| Component / file | Reads stale rate via | Today | Validity source for the fix |
|---|---|---|---|
| `AvailabilitySearch` `:20` | `#[Session]` + `search()` dispatch | `resetOnBoot` (opt-in) | `entryRates` (entry-scoped) when `entry` set |
| `AvailabilityList` `:28` | `#[Session]`, fill `:77` | none | `entryRates` (`forEntry`) |
| `AvailabilityResults` `:38` | `#[Session]`, fill `:98`, `loadAvailability` `:110/:117-146` | none | `entryRates` (`forEntry`) |
| `AvailabilityMultiResults` `:40` | `#[Session]`, fill `:133` | forces `'any'` (immune; hygiene) | `entryRates` (`forEntry`) |
| `AvailabilityCollection` `:64` | `#[Session]`, fill `:99`, `searchPayload` `:310-317`, `rows()` `:169` | none | **actual visible entries** (collection ∩ entries ∩ site ∩ status, unpaginated → origin ids) |
| `LfAvailabilityFilter` `:19` | event fill `:63` → `resrv_search` scope → `ResrvSearch::apply` `src/Scopes/ResrvSearch.php:27` | none | collection rates with `apply_to_all OR whereHas('entries')` (cross-collection + orphans only — see §3 note) |
| `AvailabilityControl` `:11` | `#[Session]`, re-dispatches `:14-18` | none (passive relay) | **untouched** — consumers heal on read |

Entry-scoped components (List/Results/MultiResults) already resolve rates with the precise `Rate::forEntry` (via `computeEntryRates` → `resolveEntryRates`, `HandlesStatamicQueries.php:21-50`, which maps localized→origin for multisite), so they are correct by construction. Only the **collection-level** surfaces need a carefully-scoped validity set.

---

## 2. Solution: self-healing reconciliation at the point of use

Validate the persisted rate against the component's **own** context at the point data enters, drop it to `null` when not valid, and auto-select when exactly one valid rate exists. `null` is the universally safe sentinel — every read path already treats it as "no filter / all rates":

- `toResrvArray()` → `rate_id => null` (`AvailabilityData.php:62`).
- Query helpers gate on `rate && rate !== 'any'` (`HandlesAvailabilityQueries.php:382`, `:395-397`).
- `AvailabilityResults::loadAvailability()` coerces null → `'any'` (`:135-136`).
- `AvailabilitySearch::search()` re-derives `'any'` from `anyRate` (`:104-105`).

So no consumer needs special-casing, and `dates`/`quantity`/`customer` are never touched. **Correctness lives at the point of use:** each component reconciles its own copy before it queries, so even a context-less writer cannot poison a query — the next consumer heals on read.

Auto-select matches existing behavior: `AvailabilityResults::checkout()` (`:158-168`) already books the single rate implicitly when only one exists. This moves that resolution earlier and makes it visible.

---

## 3. Implementation steps

### Step 1 — Add `reconcileRate()` to the form

**File:** `src/Livewire/Forms/AvailabilityData.php` (next to `toResrvArray`, ~`:56`)

```php
/**
 * Reconcile the (context-scoped) rate against the rates valid for the component's
 * current context. Heals a stale/foreign rate carried in from the shared 'resrv-search'
 * session key, and auto-selects when exactly one rate exists. null/'any' are the only
 * cross-context-safe values; every read path treats null as "no rate filter / all rates".
 *
 * @param  array<int|string, string>  $validRateIds  [rate_id => title] for the current context.
 *                                                    MUST be id-keyed (see overrideRates contract, §6).
 */
public function reconcileRate(array $validRateIds, bool $ratesEnabled): void
{
    if (! $ratesEnabled) {
        $this->rate = null;

        return;
    }

    // Drop a numeric rate that isn't valid here (foreign collection, entry-restricted, unpublished, deleted).
    if (is_numeric($this->rate) && ! isset($validRateIds[$this->rate])) {
        $this->rate = null;
    }

    // With exactly one valid rate, select it outright instead of leaving the search rate-less.
    if (($this->rate === null || $this->rate === 'any') && count($validRateIds) === 1) {
        $this->rate = (string) array_key_first($validRateIds);
    }
}
```

`isset()` handles int/numeric-string key mismatch (`Rate` `$keyType = 'string'`, `Rate.php:34`). Auto-select's safety depends on an **id-keyed** map; the only external source is `overrideRates`, whose contract is enforced in §6.

### Step 2 — Factor the entry query; build validity from the actual visible entries

The collection validity set must mirror the entries the listing actually renders. Factor the entry query out of `resolvedEntries()` so both it and the validity helper use the identical scope.

**`src/Livewire/AvailabilityCollection.php`** — extract `scopedEntriesQuery()` and have `resolvedEntries()` call it:

```php
/**
 * The entry query shared by the rendered listing and the rate-validity resolver:
 * collection ∩ configured entries ∩ current site ∩ published-status. No ordering/pagination.
 * Return type is whatever Entry::query() yields — confirm against your Statamic build
 * (e.g. \Statamic\Query\Builder).
 */
protected function scopedEntriesQuery(): \Statamic\Query\Builder
{
    $query = Entry::query();

    if ($this->collection) {
        $query->where('collection', $this->collection);
    }

    if (! empty($this->entries)) {
        $query->where(function ($query) {
            $query->whereIn('id', $this->entries)
                ->orWhereIn('origin', $this->entries);
        });
    }

    if (Site::hasMultiple()) {
        $query->where('site', Site::current()->handle());
    }

    $query->whereStatus('published');

    return $query;
}

#[Computed]
public function resolvedEntries(): EntryCollection|LengthAwarePaginator
{
    $query = $this->scopedEntriesQuery();

    if ($this->sort === 'title') {
        $query->orderBy('title');
    } elseif ($this->sort === 'order' && $this->collection) {
        if ($collection = \Statamic\Facades\Collection::findByHandle($this->collection)) {
            $query->orderBy($collection->sortField(), $collection->sortDirection());
        }
    }

    return $this->paginate ? $query->paginate($this->paginate) : $query->get();
}
```

Add the validity helper that resolves rates from those visible entries, mapped to the **origin ids** the rate pivot uses:

```php
/**
 * [rate_id => title] for rates valid for at least one entry the listing can actually show.
 * Uses the full unpaginated scoped set (collection ∩ entries ∩ site ∩ status) → origin ids,
 * so out-of-collection / private / wrong-site entries' rates never leak in, and pagination
 * cannot narrow it. Excludes orphan rates (apply_to_all = false AND no entries).
 *
 * @return array<int|string, string>
 */
protected function listingRateIds(): array
{
    if (! $this->rates && ! $this->showRates) {
        return [];
    }

    if (! empty($this->overrideRates)) {
        return $this->overrideRates; // contract: [rate_id => label] (§6)
    }

    $items = $this->scopedEntriesQuery()->get();

    if ($items->isEmpty()) {
        return [];
    }

    $originIds = $items->map(fn ($entry) => $entry->hasOrigin() ? $entry->origin()->id() : $entry->id())
        ->unique()->values()->all();
    $collections = $items->map(fn ($entry) => $entry->collection()?->handle())
        ->filter()->unique()->values()->all();

    return Rate::whereIn('collection', $collections)
        ->where(function ($query) use ($originIds) {
            $query->where('apply_to_all', true)
                ->orWhereHas('entries', fn ($q) => $q->whereIn('resrv_entries.item_id', $originIds));
        })
        ->published()
        ->pluck('title', 'id')
        ->toArray();
}
```

This single path correctly handles every scope case the reviewer raised: a `collection` + cross-collection `entries` id (the cross-collection entry never survives `scopedEntriesQuery`, so its collection/rates are excluded), a rate assigned only to a private/scheduled entry (excluded by `whereStatus('published')`), and a localized id on a non-default site (the visible localization is mapped to its origin id, matching the pivot — the same origin mapping `resolveEntryRates` does, but batched into one query). It supersedes the R2 curated/whole-collection branch split.

**Cost:** `scopedEntriesQuery()->get()` materializes the full (unpaginated) visible set once per search to read ids/collections — a second entry query alongside `rows()`'s paginated one. The valid-rate set depends only on `#[Locked]` inputs + current site + entry statuses, so it can be memoized — see O3.

### Step 3 — Wire reconcile into each component, immediately after `fill()`

Place the reconcile **right after `$this->data->fill($data)` and before the validation/hook block**, so neither the `availability-search-updated` hook nor an invalid-search early-return sees a stale rate.

**`AvailabilityList.php`** — after `:77` (before the `try` at `:79`):
```php
$this->data->fill($data);
$this->data->reconcileRate($this->entryRates, $this->rates); // <-- add
```

**`AvailabilityResults.php`** — after `:98` (before the `try` at `:100`):
```php
$this->data->fill($data);
$this->data->reconcileRate($this->entryRates, $this->rates); // <-- add
```

**`AvailabilityMultiResults.php`** — after `:133` (hygiene; already forces `'any'`):
```php
$this->data->fill($data);
$this->data->reconcileRate($this->entryRates, $this->rates); // <-- add
```

**`AvailabilityCollection.php`** — after `:99` (before the `try` at `:101`):
```php
$this->data->fill($data);
$this->data->reconcileRate($this->listingRateIds(), $this->rates || $this->showRates); // <-- add
```
The foreign rate is dropped before `runHooks('availability-search-updated', …)` (`:103`) and before `rows()`/`searchPayload()` query, so the listing returns instead of empty. `mount()` (`:89`) routes through this method too.

**`LfAvailabilityFilter.php`** — after `:63`, before dispatching the filter payload. Add `use Reach\StatamicResrv\Models\Rate;`:
```php
$this->data->fill($data);
$validRateIds = $this->rates
    ? Rate::where('collection', $this->collection)
        ->where(fn ($q) => $q->where('apply_to_all', true)->orWhereHas('entries')) // exclude orphans
        ->published()->pluck('title', 'id')->toArray()
    : [];
$this->data->reconcileRate($validRateIds, $this->rates); // <-- add
```
**Note on scope precision:** the external `statamic-livewire-filters` `LivewireCollection` owns the visible-entry set, so this surface can only heal **cross-collection** and **orphan** rates — not the rarer "rate assigned only to a private/scheduled entry in this collection" case that `AvailabilityCollection` now catches. Document this limitation; verify in the filters integration (the package isn't installable here — §5).

**`AvailabilityControl.php`** — **untouched**.

### Step 4 — Replace `resetOnBoot`; reconcile on every contextful Search action

**File:** `src/Livewire/AvailabilitySearch.php`

Delete the property (`:38-39`). Add a context-gated helper called from `mount()`, `search()`, **and** `clearDates()`:

```php
public function mount(): void
{
    $this->reconcileRateForContext();
}

public function search(?bool $withoutValidation = false): void
{
    if (! $withoutValidation) {
        $this->data->validate();
    }

    $this->reconcileRateForContext();          // <-- add (before the anyRate default)

    if (! $this->data->rate && $this->anyRate) {
        $this->data->rate = 'any';
    }

    $this->dispatch('availability-search-updated', $this->data);

    if ($this->redirectTo && ! $this->live) {
        redirect($this->redirectTo);
    }
}

public function clearDates(): void
{
    $this->data->reset();
    $this->resetValidation();
    $this->reconcileRateForContext();          // <-- add: re-heal/auto-select after reset, before dispatch

    $this->dispatch('availability-search-updated', $this->data);

    if (! $this->live) {
        $this->dispatch('availability-results-updated');
        if (! $this->redirectTo) {
            $this->js('window.location.reload()');
        }
    }
}

protected function reconcileRateForContext(): void
{
    // Heal only when we can judge the rate: entry set -> validate/auto-select against this
    // entry's rates; rates disabled -> clear any carried value. A context-less rate search
    // bar (entry === null, rates === true) cannot judge, so it leaves the value for the
    // receiving List/Collection/Results to reconcile — and must NOT drop a rate just set by
    // availabilityDateSelected() (:163-165) from a sibling date grid.
    if ($this->entry || ! $this->rates) {
        $this->data->reconcileRate($this->entryRates, $this->rates);
    }
}
```

**Auto-select visibility boundary:** the rate `<select>` (`availability-search.blade.php`) only renders options when `entryRates` is non-empty — i.e. on an entry-scoped search, where `reconcileRateForContext()` keeps the picker consistent. A collection-level search bar has empty `entryRates` (no picker options), so auto-select takes effect in the consuming components / booking path — the "no rate picker" case the requirement describes.

---

## 4. Edge cases & why each is correct

| Case | Behavior | Correct? |
|---|---|---|
| Foreign numeric rate (collection A → search B) | dropped to `null`; single-rate B → auto-selected | ✅ the bug |
| Same-collection rate restricted to a non-listed entry | not in `listingRateIds()` → dropped | ✅ |
| **Cross-collection id inside a `collection`+`entries` config** | that entry never passes `scopedEntriesQuery` → its rates excluded | ✅ R3 |
| **Rate assigned only to a private/scheduled entry** | entry excluded by `whereStatus('published')` → rate excluded (unless `apply_to_all`) | ✅ R3 (AvailabilityCollection) |
| **Localized entry id on a non-default site** | visible localization mapped to origin id → matches pivot | ✅ R3 |
| Paginated listing, page-local rate | validity uses full unpaginated scope | ✅ |
| Orphan rate (`apply_to_all=false`, no entries) | excluded on both collection surfaces | ✅ |
| `'any'` / `null` across contexts | passes through (except single-rate auto-select) | ✅ |
| **Single-rate target** | auto-selected to the rate id; **stays that id on both Results and List** (`loadAvailability` doesn't rewrite a concrete id) | ✅ R3 |
| **Multi-rate target, foreign rate carried** | dropped → **List `null`, Results `'any'`** (`loadAvailability` `:135-136` normalizes null) | ✅ R3 |
| Rates enabled, zero published rates | dropped to `null`; no auto-select | ✅ |
| `rates = false` carrying a numeric rate | set to `null` | ✅ |
| Context-less Search bar (entry null, rates true) | not reconciled locally; consumers heal; date-grid rate preserved | ✅ |

**Auto-select keys off *configured* rates**, not "available-for-the-dates" rates (cheaper, equivalent for the single-rate case) — see O2.

**Checkout hand-off preserved:** `AvailabilityCollection::select()` (`:264-274`) sets the clicked rate (valid for the target entry's collection) and redirects; on the detail page `entryRates` contains it and reconcile keeps it. The booking is also snapshotted onto the reservation row by `createReservation()`.

---

## 5. Test plan

No current test reproduces cross-collection/cross-entry/cross-page/scope poisoning. Use real `Rate` ids and be explicit about rate counts — `makeStatamicItemWithAvailability` (`tests/CreatesEntries.php:40-53`) creates exactly **one** rate per entry, so a single-rate target **auto-selects**.

### New tests
1. **Core regression — multi-rate target.** Entry A (collection X) with a rate; seed `session('resrv-search')` with `rate = A's id` + valid dates. Entry B (collection Y) with **two** rates. Mount on B:
   - `AvailabilityList`: assert `data->rate === null`.
   - `AvailabilityResults`: assert `data->rate === 'any'` (`loadAvailability` normalizes; `:135-136`).
   - Both: assert the availability result is **non-empty** (primary assertion).
2. **Core regression — single-rate target.** B has exactly **one** rate → assert `data->rate` equals B's rate id (auto-selected, **unchanged** on both List and Results), results non-empty.
3. **Collection repro.** Seed a collection-X rate; mount `AvailabilityCollection` on collection Y → `rows()` not emptied; returns Y's entries.
4. **Curated-listing / entry-restricted rate.** Collection X, rate R `apply_to_all=false` linked only to A1. `AvailabilityCollection` configured `entries = [A2]`, seed `rate = R` → R dropped, A2 returned.
5. **Pagination.** Whole-collection listing with small `paginate`, where a rate valid only for a page-2 entry is seeded while viewing page 1 → not dropped (full-scope validity); paging to page 2 still returns availability. A truly foreign rate is dropped on both pages.
6. **Cross-collection id in `collection`+`entries` (R3).** Configure `collection = X` and `entries = [id-from-Y]`; seed a collection-Y rate → the Y id is filtered out by `scopedEntriesQuery`, so the Y rate is dropped and X's listing renders.
7. **Private/scheduled-only rate (R3).** Rate assigned only to a private (or future-dated) entry; seed it on `AvailabilityCollection` → dropped (entry excluded by `whereStatus`), listing not emptied.
8. **Localized id, non-default site (R3).** Multisite: configure `entries` with a default-site origin id, switch to a non-default site, seed a rate valid for the origin → resolves via the localization's origin id and is preserved (or, if single, auto-selected); a rate from another collection is dropped.
9. **No over-clear.** `apply_to_all` rate; mount on a sibling entry sharing it → preserved.
10. **Global state survives.** `dates`+`quantity`+`customer`+foreign rate → only rate changes. Extend `test_sets_search_from_session` (`AvailabilitySearchTest.php:290`).
11. **Sentinels.** `'any'`/`null` unchanged across a context switch (except single-rate auto-select).
12. **No-rates context.** `rates = false` carrying a numeric rate → `null`.
13. **Zero published rates.** Rates enabled, none published → carried numeric rate dropped, no auto-select.
14. **Hand-off preserved (R3 fix).** `AvailabilityCollection::select($entryA, $rateId)` → mount `AvailabilityResults` for A → rate survives. For a **different** entry: if it has one rate → that rate id auto-selected; if it has multiple rates → `'any'` in Results, `null` in List (not `null` everywhere).
15. **Auto-select visibility on Search.** Single-entry `AvailabilitySearch` (one rate): assert `data->rate` is the rate id after `mount()`, and after a live date change drives `search()`.
16. **`clearDates()`.** Single-entry `AvailabilitySearch` (one rate) with a search set → `clearDates()` → assert immediately that `data->rate` is the single rate id and dates are reset. With ≥2 rates → `data->rate` is `null` after clear.
17. **Control relay.** `AvailabilityControl::save()` re-dispatching a stale rate → a downstream consumer heals it before querying.

### Replace / fix existing tests
- **Delete** `test_reset_on_boot_resets_data_and_searches_on_initial_load` (`AvailabilitySearchTest.php:311`) and `test_reset_on_boot_does_not_reset_data_on_subsequent_requests` (`:330`); re-covered by tests 9/10/15.
- **Fix** `test_can_return_rates_if_set` (`:345-352`): replace `overrideRates => ['something']` with an id-keyed map, e.g. `['10' => 'Something']` (otherwise auto-select would pick rate `"0"`; §6).
- Keep default-null payload assertions green (`AvailabilityListTest.php` ~`:58,:77,:99,:131,:381`); confirm `customer[]` round-trips so Extras/Options are unaffected.

### Not tested in this repo
- **`LfAvailabilityFilter`** — `reach/statamic-livewire-filters` is in neither `require` nor `require-dev` (`composer.json:25-41`), so the component can't be instantiated here. Apply the fix; verify in the filters integration or a manual site smoke. Track in the PR checklist.

---

## 6. `overrideRates` contract

`overrideRates` flows into `entryRates`/`listingRateIds` as-is (`computeEntryRates`, `HandlesStatamicQueries.php:41-43`) and renders as `<option value="{{ $value }}">{{ $label }}</option>`. It **must** be `[rate_id => label]`; a bare list (`['something']`) renders `value="0"` and would make auto-select pick `"0"`. Document the shape (PHPDoc `array<int|string, string>`) on each component's `overrideRates` property and fix the one test that passes a bare list (`AvailabilitySearchTest.php:347`). No runtime validation is added (`#[Locked]`, developer-supplied).

---

## 7. Verification

```bash
vendor/bin/phpunit tests/Livewire --stop-on-defect
vendor/bin/phpunit tests/Rate --stop-on-defect
vendor/bin/phpunit --configuration phpunit.pgsql.xml tests/Livewire --stop-on-defect   # Rate keyType=string: confirm isset() key-normalization on Postgres
vendor/bin/pint
```

Manual smoke: start a booking on entry A (pick a rate), abandon it, open a different collection's search → availability shows instead of "nothing available"; on a single-rate entry the rate is pre-selected; page through a paginated collection listing and confirm later pages still show availability.

---

## 8. Files touched

```
src/Livewire/Forms/AvailabilityData.php          # + reconcileRate()
src/Livewire/AvailabilitySearch.php              # - resetOnBoot; + reconcileRateForContext() in mount()+search()+clearDates()
src/Livewire/AvailabilityList.php                # + reconcile after fill (:77)
src/Livewire/AvailabilityResults.php             # + reconcile after fill (:98)
src/Livewire/AvailabilityMultiResults.php        # + reconcile after fill (:133) [hygiene]
src/Livewire/AvailabilityCollection.php          # + scopedEntriesQuery() (extracted); resolvedEntries() uses it; + listingRateIds(); reconcile after fill (:99)
src/Livewire/LfAvailabilityFilter.php            # + Rate import; reconcile after fill (:63) [verify in filters repo]
tests/Livewire/AvailabilitySearchTest.php        # delete resetOnBoot tests; fix overrideRates test; + visibility + clearDates tests
tests/Livewire/AvailabilityListTest.php          # + reconcile/auto-select tests
tests/Livewire/AvailabilityResultsTest.php       # + single/multi-rate reconcile tests
tests/Livewire/AvailabilityCollectionTest.php    # + collection + curated + pagination + scope (cross-collection/private/multisite) + hand-off tests
RELEASE-*.md / UPGRADE-*.md                       # breaking-change upgrade note (see §9)
```
`AvailabilityControl.php` is intentionally **not** touched.

---

## 9. Rollout / breaking change

`resetOnBoot` is a public `#[Locked]` prop sites may pass via the tag (`:resetOnBoot="true"`); after removal Livewire throws "property does not exist" for those callers.

- This repo has **no `CHANGELOG.md`** — it documents releases in `RELEASE-*.md` and upgrades in `UPGRADE-*.md` (e.g. `RELEASE-v6.0.0.md`, `UPGRADE_TO_RATES.md`). Add the note to the next release's `RELEASE-vX.Y.Z.md` (and/or a short `UPGRADE-*.md`), not a new `CHANGELOG.md`.
- Note text: "Removed the `resetOnBoot` attribute from the availability-search component. Stale rate selections are now healed automatically across all availability components — remove any `:resetOnBoot` attribute from your templates."
- Grep target sites/templates for `resetOnBoot` before release.

---

## 10. Open items for the maintainer

- **O1 — no `hydrate()` defense needed.** Given the corrected lifecycle (read at `mount`, persisted via snapshot, written at `dehydrate`), every fresh mount reconciles and live snapshots already carry the reconciled value.
- **O2 — auto-select scope.** Keys off *configured* rates, not *available-for-the-dates* rates. Change only if product wants the stricter (more expensive) check.
- **O3 — `listingRateIds()` cost.** `scopedEntriesQuery()->get()` materializes the full visible set each search (a second query alongside `rows()`'s paginated one). Since the valid-rate set depends only on `#[Locked]` inputs + current site + entry statuses, promote `listingRateIds()` to `#[Computed(persist: true)]` (accepting that a status change between requests is reflected only on the next fresh mount), or fetch ids/handles without hydrating full entries, if a large collection shows up in profiling.
- **O4 — `search()` signature.** With `resetOnBoot` gone, the `withoutValidation` branch of `search()` may be unused. Audit and drop in a follow-up.
- **O5 — future scoped fields.** If the advanced-availability `property` returns as a form field, add a sibling `reconcileProperty($validProperties)` called at the identical insertion points — no new lifecycle plumbing. This structurally ends the `anyAdvanced → resetAdvancedOnBoot → resetOnBoot` recurrence.
