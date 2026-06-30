# Browser Testing Plan — Statamic Resrv frontend

End-to-end browser testing for the frontend Livewire components that have a **real JavaScript /
browser surface** (Alpine date calendar, reactive steppers/dropdowns, the dictionary-phone
combobox, the multi-step checkout, offline payment → confirmed reservation).

> **Scope note (read first).** There are already **328 headless Livewire tests** (14 files in
> `tests/Livewire/`) covering validation, pricing, mount errors and state transitions. Browser
> tests are **not** a second copy of those — they exist only to cover what a headless test
> *cannot*: Alpine/JS behavior, real `/livewire/update` round-trips through Statamic's routing,
> asset load order, and the full funnel rendering in a real DOM. Everything else stays headless.

---

## How to use this file (for Claude Code, one task at a time)

1. Open the **Task Board** below and find the **first** unchecked `- [ ]` task.
2. Do **only that task**. Read its detailed `## Tnn` section for Goal / Steps / Files / Acceptance.
3. Run the task's **Acceptance** check. It must pass before the task counts as done.
4. Mark it done: change its `- [ ]` to `- [x]` in the Task Board, and bump the **Progress** counter.
5. Stop, or continue to the next unchecked task. Never skip ahead past a blocked task without noting why under the task.

> Tasks are ordered by dependency. Phases 1–3 build the harness (do them in order). Phase 4
> tasks (the focused browser scenarios) are mostly independent of each other once Phase 3 is
> green. Phase 5 is CI/docs, do last. Tasks marked **(optional)** can be skipped without
> blocking anything.

**Progress: 13 / 23 complete**

---

## Decision (read once before starting)

**Chosen harness:** `orchestra/testbench-dusk` + Testbench **Workbench** app, booting Statamic 6 +
this addon + Livewire, served to a real **ChromeDriver** browser. **Tests are authored in plain
PHPUnit** (`extends Tests\Browser\BrowserTestCase`), exactly like the existing 328-test suite.

**Why not Pest?** The repo locks **PHPUnit 13.1.10** (`composer.lock`) and the whole existing
suite is PHPUnit (`tests/**`, `phpunit.xml`, `composer test`). The current Pest 4 (4.7.x)
constrains **`phpunit/phpunit:^12.5.30`**, i.e. it *excludes* PHPUnit 13 — installing it would
**downgrade PHPUnit** for the entire project and invalidate the "additive, existing suite
unchanged" guarantee. `Orchestra\Testbench\Dusk\TestCase` is itself a PHPUnit `TestCase`, so Pest
buys nothing here. **No Pest.** (A thin Pest/Playwright *cross-browser* smoke layer remains a
strictly-optional future experiment — T23 — and only if it can avoid touching the PHPUnit pin.)

**The single biggest simplifier:** use the **`OfflinePaymentGateway`** (`src/Http/Payment/OfflinePaymentGateway.php`),
not Stripe. Its UI (`checkout-payment-offline.blade.php`) confirms a reservation with a plain
`$wire.confirmPayment()` — **no Stripe.js, no card iframe** — so a browser can drive
search → checkout → **confirmed** end-to-end. Real-Stripe-in-a-browser is out of scope.

---

## ⚠️ Gotchas reference (the known failure modes — consult when a task misbehaves)

1. **Asset load order.** Livewire ships & auto-starts Alpine. `resrv-frontend.js` registers the
   calendar via `document.addEventListener('alpine:init', …)` (it imports `calendarPlugin` from
   **`@reachweb/alpine-calendar`** and sets `window.dayjs`), so it MUST be parsed **before**
   `{{ livewire:scripts }}`. Do **not** add a second standalone Alpine (CDN) — two Alpine
   instances and the calendar silently dies.
2. **Published asset path & tag.** The addon publishes `resources/frontend` via Statamic's
   `$publishables` (`src/Providers/ResrvProvider.php:178`). Per `AddonServiceProvider::bootPublishables()`,
   the destination is **`public/vendor/statamic-resrv/frontend/…`** (packageName segment) and the
   publish **tag is the addon slug `statamic-resrv`** — NOT `resrv-frontend`. So the JS lives at
   `/vendor/statamic-resrv/frontend/js/resrv-frontend.js` and CSS at
   `/vendor/statamic-resrv/frontend/css/resrv-frontend.css`.
3. **Bundle is IIFE-scoped; only `window.dayjs` is global.** Commit `4c93a2f` wrapped the bundle in
   an IIFE because its 103 top-level decls were leaking onto `window` — a minified date-parser
   landed on `window.L` and collided with Leaflet ("L is not a function" after a `wire:navigate`).
   T12 guards this: `window.dayjs` is a function, the leak globals (e.g. `window.L`) are gone.
4. **Session driver must be `file`/`database`, never `array`.** The cart (`resrv-search`,
   `resrv-reservation`, `resrv-multi-selections`) lives in the session across requests; `array`
   resets it between page loads → checkout 404s.
5. **Shared DB across processes.** Dusk serves the app in a **separate process**, so the browser
   can't see `:memory:` SQLite written by the test process. Use a **file** SQLite DB shared by
   both. For the fixture lifecycle prefer **`DatabaseTruncation`** — NOT `DatabaseTransactions`
   (transactions don't cross the HTTP request) and NOT `RefreshDatabase`. testbench-dusk manages
   its own rollback, so `DatabaseMigrations` can fight it; see Gotcha #8 and T10.
6. **Dusk serves on port 8001 by default.** `Orchestra\Testbench\Dusk\TestCase::$baseServePort`
   is **8001**. If `APP_URL` says `:8000`, the addon's absolute `->url()` checkout redirects walk
   off the test server. Keep them aligned: set `$baseServePort = 8000` **or** point `APP_URL` at
   `http://127.0.0.1:8001`. Pick one and use it everywhere (T4, T10).
7. **Configure the *served* app, not just the test process.** The browser hits a separate PHP
   process. Per-test config that the served app must see (e.g. registering a second payment
   gateway for the picker test) has to go through testbench-dusk's **`beforeServingApplication()`**
   hook (or `WorkbenchServiceProvider`), not a plain `Config::set()` in the test body.
8. **Livewire `/livewire/update` under Statamic's catch-all.** Statamic registers a catch-all web
   route that can shadow Livewire's update endpoint → AJAX 404 → the whole interactive flow
   breaks. Replicate `tests/TestCase::registerLivewireUpdateRoute()` (`tests/TestCase.php:123`,
   registered inside `$app->booted()`).
9. **`checkout_entry` / `checkout_completed_entry` config must be real entry IDs** before the
   payment step, or `CheckoutPayment::mount()` throws resolving the completed URL.
10. **Statamic 6 needs `Inertia\ServiceProvider`** in the providers list, plus the
    `MarcoRieser\Livewire\ServiceProvider` bridge for `<livewire:…>` tags inside Antlers.
11. **ChromeDriver version drift.** The standalone driver must match installed Chrome's major
    version; CI Chrome bumps break a pinned driver. Use the Dusk chrome-driver updater with
    `--detect`.

---

## Task Board (tracker — flip `[ ]` → `[x]` as you finish)

**Phase 1 — Tooling & harness**
- [x] T1 — Add `orchestra/testbench-dusk` dev dependency (no Pest)
- [x] T2 — Install / pin ChromeDriver
- [x] T3 — Scaffold the Testbench Workbench app
- [x] T4 — Configure `testbench.yaml` (providers, env, session, gateway, migrations list, build pipeline, port)

**Phase 2 — Host app: render one bookable page**
- [x] T5 — `WorkbenchServiceProvider` (Statamic config, offline gateway, Livewire-update route)
- [x] T6 — Frontend layout + publish/serve the IIFE assets (correct tag + paths + order)
- [x] T7 — Content + DB seeding (collection, blueprint, bookable entry, checkout entries, rate/availability) via `workbench:build`
- [x] T8 — Routes/templates mounting the Livewire components
- [X] T9 — Manual `testbench serve` smoke check (page renders, calendar works, no leak)

**Phase 3 — Browser test harness**
- [x] T10 — `Tests\Browser\BrowserTestCase` (Dusk base + Statamic boot + shared file DB + fixture-lifecycle PoC)
- [x] T11 — Separate `Browser` PHPUnit suite + composer script (kept out of default + pgsql runs)
- [x] T12 — First green browser test: global-leak regression guard + calendar renders

**Phase 4 — Focused browser scenarios (6–8 high-value flows; independent — pick any unchecked)**
- [x] T13 — AvailabilitySearch: Alpine calendar (open/range/clear), quantity stepper, rates dropdown, live-dispatch, **session persistence on reload**
- [ ] T14 — Standard checkout **happy path** E2E (search → results → select rate → extras+option → form → offline confirm → CONFIRMED + availability decremented)
- [ ] T15 — Multi-rate **cart** happy path (`AvailabilityMultiResults`: add/remove selections, line + grand totals, checkout → multi-line reservation)
- [ ] T16 — CheckoutForm rich JS fields (`dictionary_phone` combobox keyboard nav + filter, `toggle` Alpine switch, inline required-field validation)
- [ ] T17 — Gateway picker (two gateways via `beforeServingApplication`) **+ one representative error path** (e.g. "please select a rate")
- [ ] T18 — Coupon / dynamic-pricing reactivity on checkout totals (apply → totals change, remove → revert)
- [ ] T19 — **(optional)** AvailabilityCollection live list (collection render, `showUnavailable` mounted true vs false, paginate, select → redirect)
- [ ] T20 — Cross-collection rate reconciliation: select a rate on one collection, navigate to another, the carried `resrv-search` rate is **healed** (foreign rate dropped; single valid rate auto-selected) — guards the `resetOnBoot` → `reconcileRate()` change

**Phase 5 — CI & maintenance**
- [ ] T21 — CI workflow (Chrome + ChromeDriver, run Browser suite, upload failure screenshots)
- [ ] T22 — Developer docs (README "Browser tests" section + CLAUDE.md note)
- [ ] T23 — **(optional)** Evaluate Pest v4 Playwright as a cross-browser smoke layer (must not disturb the PHPUnit pin)

> **Intentionally NOT browser-tested** (already covered by the 328 headless Livewire tests; adding
> Chrome copies would be slow and brittle): `AvailabilityResults`/`AvailabilityCollection` state
> transitions & validation, `Extras`/`Options` enable/disable + price math, `Checkout` step
> orchestration, all non-JS field types, cutoff/quantity/date validation rules. `AvailabilityControl`
> renders an empty `<div>` (`src/Livewire/AvailabilityControl.php:21`) — it has **no browser
> surface at all**, and it is a *separate* component from AvailabilitySearch (which emits its own
> `availability-search-updated`, lines 108/135), so it stays **headless-only** and is covered by no browser task.
> `LfAvailabilityFilter` is registered only when `reachweb/statamic-livewire-filters` is installed
> (`src/Providers/ResrvLivewireProvider.php:60`) and that package is **not a dependency** — covering
> it is deferred to an optional suite that installs it (see note under T19).

---

# Phase 1 — Tooling & harness

## T1 — Add browser-testing dev dependency
**Goal:** Pull in the Dusk-for-packages harness without disturbing the existing PHPUnit suite or its PHPUnit 13 pin.
**Steps:**
1. `composer require --dev "orchestra/testbench-dusk:^10.0 || ^11.0"` (matches `orchestra/testbench:^10.0 || ^11.0` already in `composer.json`, which targets Laravel 12/13). testbench-dusk supports PHPUnit 13, so the pin is preserved.
2. Do **NOT** add Pest — it would force `phpunit/phpunit` down to `^12.5` and break the lock (see Decision). Dusk tests are written as PHPUnit `TestCase` subclasses.
3. Confirm the existing suite is untouched: `composer test` (or `vendor/bin/phpunit`) — must be green and still on PHPUnit 13.
**Files:** `composer.json` (require-dev), `composer.lock`.
**Acceptance:** `ls vendor/orchestra/testbench-dusk` exists; `grep '"phpunit/phpunit"' composer.lock` still shows **13.x**; `composer test` passes unchanged.

## T2 — Install / pin ChromeDriver
**Goal:** A ChromeDriver binary matching the local Chrome so Dusk can drive a real browser.
**Steps:**
1. testbench-dusk exposes Dusk's command set. Update the driver to match installed Chrome:
   `php vendor/bin/testbench dusk:chrome-driver --detect` *(or the `vendor/bin/dusk-updater detect --auto-update` equivalent — use whichever the installed version exposes; check `vendor/bin/`)*.
2. Ensure the binary is executable: `chmod -R 0755 vendor/laravel/dusk/bin/` (recurring CI fix; harmless locally).
**Files:** none in-repo (binary lives under `vendor/`).
**Acceptance:** the chrome-driver updater prints a version matching `google-chrome --version` (major) and exits 0.
**Notes:** Gotcha #11. On CI this becomes a step in T21.

## T3 — Scaffold the Testbench Workbench app
**Goal:** Create the companion `workbench/` app that boots Statamic + the addon and can be served to a browser.
**Steps:**
1. `php vendor/bin/testbench workbench:install` (non-interactive flags if prompted). This generates `testbench.yaml` and a `workbench/` skeleton (`app/`, `routes/`, `resources/views/`, `database/`).
2. Prune anything you don't need, but keep: `workbench/app/Providers/`, `workbench/routes/web.php`, `workbench/resources/views/`, `workbench/database/seeders/`.
3. Add the test SQLite file (`workbench/database/*.sqlite`) and Dusk artifacts (`tests/Browser/screenshots`, `tests/Browser/console`) to `.gitignore`; keep source files tracked.
**Files:** `testbench.yaml` (new), `workbench/**` (new), `.gitignore`.
**Acceptance:** `php vendor/bin/testbench serve --port=8001` boots without fatal errors and serves a placeholder page.
**Notes:** The generator is the source of truth for `testbench.yaml` shape — diff against `vendor/orchestra/workbench/src/Console/stubs/testbench.yaml` if a key is rejected later.

## T4 — Configure `testbench.yaml`
**Goal:** Register the providers Statamic/Livewire/the addon need, set browser-safe env, and use a **valid** migrations/build configuration that actually creates and seeds the shared SQLite DB.
**Steps:**
1. Keep the generated `laravel:` key — for the Dusk variant it is **`laravel: '@testbench-dusk'`** (do not delete it; it tells Workbench which base app to boot).
2. Provider list (order matters; mirrors `tests/TestCase::getPackageProviders` + parent `AddonTestCase`):
   ```yaml
   laravel: '@testbench-dusk'
   providers:
     - Statamic\Providers\StatamicServiceProvider
     - Inertia\ServiceProvider                       # Gotcha #10 — required by Statamic 6
     - Livewire\LivewireServiceProvider
     - MarcoRieser\Livewire\ServiceProvider          # statamic-livewire bridge for <livewire:…> in Antlers
     - Reach\StatamicResrv\StatamicResrvServiceProvider
     - Workbench\App\Providers\WorkbenchServiceProvider
   ```
3. Env — **file session + a FILE SQLite DB** (Gotchas #4, #5) and a port that matches Dusk (Gotcha #6 — `APP_URL` here must agree with `BrowserTestCase::$baseServePort`; using **8001** to match Dusk's default is simplest):
   ```yaml
   env:
     - APP_URL=http://127.0.0.1:8001   # the app's self-URL; must match the served port (Gotcha #6). NOTE: this does NOT set the serve bind port — use `serve --port=8001` for that.
     - DB_CONNECTION=sqlite
     # No DB_DATABASE on purpose: rely on the skeleton default database_path('database.sqlite').
     # That is the SAME file `create-sqlite-db` creates AND the same path the test process resolves,
     # so both processes share it (Gotcha #5). A relative `workbench/database/...` path would resolve
     # against the Dusk skeleton (base_path), NOT match create-sqlite-db, and be missing on a fresh clone.
     - SESSION_DRIVER=file
     - CACHE_STORE=file
     - QUEUE_CONNECTION=sync
     - MAIL_MAILER=log
     - STATAMIC_LICENSE_KEY=
   ```
4. **Migrations must be a LIST, not `true`** (the canonical stub uses a list). Add seeders and the full build pipeline so the SQLite file is created, wiped and migrated before serving (the stub's `build:` includes `create-sqlite-db`, `db-wipe`, `migrate-fresh` — the plan's earlier `[asset-publish]`-only build would never create or migrate the DB):
   ```yaml
   migrations:
     - workbench/database/migrations
   seeders:
     - Workbench\Database\Seeders\DatabaseSeeder
   workbench:
     start: '/'
     install: true
     discovers:
       web: true
       views: true
     assets:
       - statamic-resrv   # REQUIRED: `asset-publish` only publishes tags listed here (+ laravel-assets).
                          # Omit it and CI's fresh build ships NO frontend bundle. Value = the addon slug.
     build:
       - asset-publish
       - create-sqlite-db
       - db-wipe
       - migrate-fresh   # runs the addon migrations too, then seeders
   ```
5. No manual `touch` needed: the `create-sqlite-db` build step creates the SQLite file at the skeleton's `database_path('database.sqlite')` on every build, so a fresh clone / CI run always has it.
**Files:** `testbench.yaml`.
**Acceptance:** `php vendor/bin/testbench workbench:build` runs the pipeline with no "provider not found" / "migrations must be array" errors and leaves a migrated SQLite file at the skeleton's `database_path('database.sqlite')`; `php vendor/bin/testbench serve --port=8001` boots and a quick log confirms `config('session.driver') === 'file'` and `config('database.default') === 'sqlite'` pointing at that file.
**Notes:** The addon's own tables migrate automatically via `ResrvProvider::bootAddon()`'s `loadMigrationsFrom`; `migrate-fresh` picks them up. The `migrations:` list only covers any *extra* workbench-only migrations.

---

# Phase 2 — Host app: render one bookable page

## T5 — `WorkbenchServiceProvider`
**Goal:** Configure Statamic (sites, stache, edition), force the **offline** gateway, and beat the catch-all for `/livewire/update`.
**Steps:**
1. Create `workbench/app/Providers/WorkbenchServiceProvider.php`:
   - `config([...])` for `statamic.stache.stores.*` → `Orchestra\Testbench\workbench_path('content/...')` (NOT `base_path('workbench/...')` — `base_path()` resolves to the Dusk Laravel *skeleton*, not this package's `workbench/` dir), users store → file, `statamic.editions.pro => true`.
   - Force offline-only gateway so the card step never appears:
     ```php
     'resrv-config.payment_gateways' => [
         'offline' => ['class' => \Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway::class, 'label' => 'Pay on arrival'],
     ],
     ```
   - `Statamic\Facades\Site::setSites(['en' => ['name'=>'English','url'=>'/','locale'=>'en_US','lang'=>'en']]);`
2. Replicate the Livewire-update route fix (Gotcha #8) — copy the body of `tests/TestCase::registerLivewireUpdateRoute()` into `boot()` (register inside `$this->app->booted(...)` so it lands before Statamic's catch-all).
3. Prevent the Statamic licensing Outpost call in the served app if it slows boot (optional; mirror `TestCase::preventOutpostRequests()`).
**Files:** `workbench/app/Providers/WorkbenchServiceProvider.php` (new).
**Acceptance:** `php vendor/bin/testbench serve --port=8001`; hitting any route does not 500 on Statamic boot; a log of `config('resrv-config.payment_gateways')` shows only `offline`.
**Notes:** Reference: `tests/TestCase.php:74-134`, `src/Providers/ResrvProvider.php` (PaymentInterface binding).

## T6 — Frontend layout + publish/serve the IIFE assets
**Goal:** A served layout that loads the addon's compiled `resrv-frontend.js` (IIFE) + CSS **before** Livewire (Gotchas #1, #2).
**Steps:**
1. Publish the compiled assets into the served app's `public/`. The publish **tag is the addon slug**:
   `php vendor/bin/testbench vendor:publish --tag=statamic-resrv --force` (or rely on `workbench.build: [asset-publish]` from T4). They land at **`public/vendor/statamic-resrv/frontend/{js,css}/…`**.
2. Create `workbench/resources/views/layout.antlers.html`:
   ```html
   <head>
     <meta name="csrf-token" content="{{ csrf_token }}">
     <link rel="stylesheet" href="/vendor/statamic-resrv/frontend/css/resrv-frontend.css">
     <link rel="stylesheet" href="/vendor/statamic-resrv/frontend/css/resrv-tailwind.css">
     {{ livewire:styles }}
   </head>
   <body>
     {{ template_content }}
     <!-- registers the @reachweb/alpine-calendar plugin via alpine:init + sets window.dayjs -->
     <script src="/vendor/statamic-resrv/frontend/js/resrv-frontend.js"></script>
     {{ livewire:scripts }}   {{-- Livewire loads & starts Alpine AFTER the plugin above --}}
   </body>
   ```
   (If you render via a plain Blade route instead of Antlers, use `@livewireStyles` / `@livewireScripts` with the same ordering.)
3. Do **not** add a standalone Alpine CDN script (Gotcha #1). Do **not** add Stripe.js (offline gateway).
**Files:** `workbench/resources/views/layout.antlers.html` (new); published assets under `public/vendor/statamic-resrv/frontend/`.
**Acceptance:** the layout file exists and `curl -s http://127.0.0.1:8001/vendor/statamic-resrv/frontend/js/resrv-frontend.js` returns **200** and the body starts with `(function(){` (IIFE — Gotcha #3).
**Notes:** The IIFE wrapper + the single intentional global (`window.dayjs`) come from commit `4c93a2f`; T12 asserts the leak is gone in-browser.
> **Foundational fix made here (prerequisite, not in the original plan):** the addon is the repo's *root* composer package, so Statamic never discovers it in the served app and `AddonServiceProvider::boot()` bailed at `if (! $this->getAddon())` — silently skipping the **entire** boot chain (events, tags, scopes, fieldtypes, publishables, `bootAddon`). With nothing registered, `vendor:publish --tag=statamic-resrv` reported *"No publishable resources"* and the `resrv_availability` fieldtype never registered. `WorkbenchServiceProvider::registerAddonManifest()` now injects the addon into `Statamic\Addons\Manifest` (mirroring `AddonTestCase`), so `getAddon()` resolves by namespace and the served app boots the addon like a real install. **This also unblocks T7 (fieldtype/blueprint) and T8 (tags), and is what makes the build's `asset-publish` step ship the bundle for T21/CI.** Verified: addon discovered, `FieldtypeRepository::find('resrv_availability')` registered, publish tag present, offline gateway preserved, bundle served 200 as an IIFE.

## T7 — Content + DB seeding
**Goal:** Seed one bookable entry (a `Rate` + a wide availability window), an extra, an option, and the checkout entries — reusing the existing test-helper logic.
**Steps:**
1. Create `workbench/database/seeders/DatabaseSeeder.php`. Port the logic from the test helpers (don't reinvent):
   - Collection `pages` (route `/{slug}`) + blueprint with the `resrv_availability` fieldtype — see `tests/TestCase::ensureCollectionWithResrvField()`.
   - A bookable entry; a `Rate` (collection `pages`, slug `default`); `Availability` rows for **tomorrow through at least +14 days** (`available=1`, `price=50`). Seed a wide window — not the helper's default 4 days, and **not** starting `today()` — to avoid midnight/timezone flakes and to give range/extra-days tests room. Base it on `tests/CreatesEntries::makeStatamicItemWithAvailability()`.
   - An `Extra` attached to the entry, and an `Option` (+`OptionValue`) — see `tests/Livewire/CheckoutTest::setUp()`.
   - A `checkout` entry and a `checkout-completed` entry; then `Config::set('resrv-config.checkout_entry', …)` and `…checkout_completed_entry` (Gotcha #9). For the served app, set these in `WorkbenchServiceProvider::boot()` reading the seeded entry IDs (e.g. by slug) so both processes agree.
2. Put the reusable bits in a `Tests\Browser\Concerns\SeedsBookableContent` trait so the Dusk tests (T10+) and the seeder share one implementation.
**Files:** `workbench/database/seeders/DatabaseSeeder.php` (new); `tests/Browser/Concerns/SeedsBookableContent.php` (new); `workbench/content/collections/**` if using file-based entries.
**Acceptance:** after `php vendor/bin/testbench workbench:build` (which runs `migrate-fresh` **and** the seeders — note: `package:discover` alone does **not** seed) + serve, the seeded entry resolves; a `database-query`/log shows ≥1 row in `resrv_availability` and `config('resrv-config.checkout_entry')` points to a real entry ID.
**Notes:** Helpers to mine: `tests/CreatesEntries.php` (`makeStatamicItemWithAvailability`, `createEntries`), `tests/TestCase.php` (`ensureCollectionWithResrvField`), `tests/Livewire/CheckoutTest.php` (`setUp`).
> **Seed shape Phase-4 builds on** (`SeedsBookableContent`): collection `pages` (route `/{slug}`) + resrv blueprint; entry slugs `bookable` / `checkout` / `checkout-completed`; one rate (collection `pages`, slug `default`, `apply_to_all`); availability **available=1, price=50** over **+1…+20 days** (starts tomorrow, never today()); one Extra attached + one not-required Option with a fixed value. `available=1` is intentional (per plan) — tests needing higher quantity/multi-line carts add a per-test variant. Every step is find-or-create (truncate-then-reseed safe for T10). Two config owners by design: the trait `Config::set`s `checkout_entry`/`checkout_completed_entry` for the seeding/test process; `WorkbenchServiceProvider::resolveCheckoutEntries()` re-resolves them **by slug** for the served app (separate process, never runs the trait). Generated content (`workbench/content/**`, random entry UUIDs) is gitignored — regenerated by `workbench:build`.

## T8 — Routes/templates mounting the Livewire components
**Goal:** Real URLs the browser can visit that render the availability + checkout UIs.
**Steps:**
1. Preferred (production-faithful): a `pages` entry whose Antlers body mounts the tags:
   ```antlers
   <livewire:availability-search :entry="id" :rates="true" :enable-quantity="true" :show-availability-on-calendar="true" />
   <livewire:availability-results :entry="id" :show-extras="true" :show-options="true" />
   ```
   and the `checkout` entry body: `<livewire:checkout />`. (Tag-in-Antlers usage matches `CART-BASED-FLOW.md`.)
2. For the multi-rate cart scenario (T15), an entry mounting `<livewire:availability-multi-results :entry="id" :show-extras="true" :show-options="true" />`.
3. Simpler fallback for component-level tests: add explicit routes in `workbench/routes/web.php`:
   ```php
   Route::get('/__t/search', fn () => view('dusk.search'));   // mounts <livewire:availability-search>
   Route::get('/__t/checkout', fn () => view('dusk.checkout')); // mounts <livewire:checkout>
   ```
   with thin Blade views extending the T6 layout. Register the views dir via `loadViewsFrom`/`view.paths`.
4. **Add stable test selectors.** Browser tests must not depend on translated text or Tailwind classes. Prefer the stable hooks already in the markup (`wire:model="data.dates"`, `wire:click="checkout"`, etc.) via attribute selectors; where a control has no stable hook, add a `dusk="…"` attribute to the relevant package blade. Cover at least: the date input, rate selector, quantity +/- buttons, add-to-cart/checkout actions, gateway buttons, coupon field, and the confirm-payment button. Keep a short selector map in the T7 test helper so all Phase-4 tests share one source.
**Files:** `workbench/routes/web.php`, `workbench/resources/views/dusk/*.blade.php` and/or `workbench/content/collections/pages/*.md`; plus `dusk=`/`data-*` attributes in the relevant `resources/views/livewire/**` blades.
**Acceptance:** `curl -s http://127.0.0.1:8001/<bookable-slug>` (or `/__t/search`) returns HTML containing a Livewire component root (`wire:id`) and the calendar container.
**Notes:** Keep both a Statamic-entry path (faithful) and a direct-route path (fast/isolated) — Phase-4 tasks use whichever fits.
> **Two load-bearing discoveries in T8 (both differ from the plan's draft snippets):**
> 1. **Templates are Blade, not Antlers.** The plan showed `<livewire:… :rates="true" />` in `.antlers.html`, but (a) Blade's `<livewire:…>` is *literal text* inside Antlers — the MarcoRieser bridge only mounts via the Antlers tag `{{ livewire:component }}`; and (b) that Antlers tag evaluates `:rates="true"` by **looking up a variable named `true` → null**, which then trips `TypeError: Cannot assign null to … $rates of type bool`. Blade's `<livewire:…>` evaluates booleans + maps kebab→camel correctly. AND Statamic only auto-wraps a layout around **Antlers** templates (`View::shouldUseLayout` → `isUsingAntlersTemplate`), so a Blade template must `@extends` its own layout. ⇒ the harness is **all-Blade**: `layout.antlers.html` (T6) became `layout.blade.php` (`@livewireStyles`/`@livewireScripts`, bundle before scripts), and each template `@extends('layout')`. Entries carry a `template` field (`bookable`/`checkout`/`checkout-completed`/`multi`); a `multi` entry was added for T15.
> 2. **Manifest must append, not replace.** T6's `registerAddonManifest()` *overwrote* the manifest with resrv only, which dropped `marcorieser/statamic-livewire` — itself an `AddonServiceProvider` whose **gated `bootTags()` registers the `livewire` Antlers tag** (so `{{ livewire:scripts }}` and the whole bridge). Now it `build()`s the discovered manifest first, then appends resrv. Without this, `{{ livewire:scripts }}` and every bridge tag render as nothing.
>
> Verified: `/bookable` (search+results), `/checkout`, `/multi`, `/checkout-completed`, `/__t/search`, `/__t/checkout` all 200 with full layout, `wire:id`, calendar container, and **resrv-frontend.js before the Livewire runtime** (Gotcha #1). Selectors: `SeedsBookableContent::browserSelectors()` maps the plan's controls (existing hooks where present; `dusk=` added to the hook-less quantity stepper, coupon input, gateway button, offline confirm). Headless Livewire suite still green (346 tests).

## T9 — Manual `testbench serve` smoke check
**Goal:** Eyes-on confirmation the host app actually works before automating — the riskiest integration points (Gotchas #1–#10) all surface here.
**Steps:**
1. `php vendor/bin/testbench serve --port=8001` (the `--port` flag sets the bind port — `APP_URL` alone does **not**), open the bookable page in Chrome at `http://127.0.0.1:8001`.
2. Verify in DevTools console: `typeof window.dayjs === 'function'`, `typeof window.L === 'undefined'` (leak gone), **no console errors**.
3. Click the date field → the **`@reachweb/alpine-calendar`** picker opens and renders days; pick a range → availability/price updates (Livewire round-trip succeeds, i.e. `/livewire/update` is 200 not 404 — Gotcha #8).
4. Navigate to checkout → reservation summary renders → offline "Confirm reservation" button present (Gotcha #9 satisfied).
**Files:** none (verification only). Record findings as a short note under this task.
**Acceptance:** All four checks pass by hand. If the calendar doesn't open → revisit Gotcha #1; if AJAX 404s → Gotcha #8; if the cart resets → Gotcha #4.
**Notes:** This task gates Phase 3 — don't automate against a host app that doesn't work manually.

---

# Phase 3 — Browser test harness

## T10 — `Tests\Browser\BrowserTestCase`
**Goal:** A Dusk base class (PHPUnit) that boots the same Statamic+addon+Livewire stack, seeds content, and shares the file DB with the served app.
**Steps:**
1. Create `tests/Browser/BrowserTestCase.php` extending `Orchestra\Testbench\Dusk\TestCase`:
   - `use WithWorkbench;` (loads `testbench.yaml` + `workbench/`).
   - `getPackageProviders()` returns the same list as `testbench.yaml` (Statamic, Inertia, Livewire ×2, addon, workbench).
   - `defineEnvironment($app)` — replicate the Statamic/env bits from `tests/TestCase.php` that `AddonTestCase` would otherwise give you (edition pro, users store, file session, the **file** SQLite path, outpost prevention). **Note:** the Dusk base is NOT `Statamic\Testing\AddonTestCase`, so port those bits explicitly.
   - Set **`protected static $baseServePort = 8000;`** *only if* you chose `APP_URL=:8000` in T4. If you kept Dusk's default `:8001` (recommended), leave it and ensure `APP_URL` matches (Gotcha #6).
2. **Fixture lifecycle — PoC first (Gotcha #5).** Before writing real tests, prove out the DB strategy with a one-off:
   - Primary choice: `use Illuminate\Foundation\Testing\DatabaseTruncation;` (truncates between tests; survives the cross-process HTTP boundary). Seed via `SeedsBookableContent` in `setUp()` after truncation.
   - Avoid `DatabaseTransactions`/`RefreshDatabase` (transactions don't cross the request) and be cautious with `DatabaseMigrations` — testbench-dusk manages its own rollback and the two can conflict. If truncation misbehaves with the package migrations, fall back to migrate-once-then-truncate and record why in this task.
3. Per-served-app config goes through **`beforeServingApplication(function ($app, $config) { … })`** (Gotcha #7) — used by T17 to register a second gateway in the served process.
4. Add Dusk Chrome options for CI headless (`--headless=new`, `--disable-gpu`, `--no-sandbox`, window size) via the standard `driver()`/`prepare()` override.
**Files:** `tests/Browser/BrowserTestCase.php` (new).
**Acceptance:** the boot test `public function test_boots(): void { $this->browse(fn ($b) => $b->visit('/')->assertPresent('body')); }` passes AND two **DB-lifecycle** tests prove the shared-file strategy (Gotcha #5) before any Phase-4 work begins:
- **(a) cross-process visibility** — a row written by the *browser* (e.g. an offline reservation created through the UI) is then readable by the *test process* querying the same file DB.
- **(b) clean-but-seeded isolation** — a second test starts with no leftover rows from the first, yet still has the seed fixtures (truncation re-seeds in `setUp()`, or migrate-once+truncate retains them).
These validate the lifecycle itself, not just that a page renders — if either fails, fix the lifecycle (Step 2) before continuing.
**Notes:** This is the trickiest file — it merges Dusk's base with Statamic's boot AND settles the DB lifecycle. Budget time here; everything downstream depends on it.
> **T10 outcome + load-bearing findings (read before T11/T12):**
> - **Base stays thin.** `BrowserTestCase` does NOT re-port `defineEnvironment()`/`getPackageProviders()`: `WithWorkbench` loads `testbench.yaml` (providers + env) and the `WorkbenchServiceProvider` supplies the Statamic config, sites, offline gateway, manifest and `/livewire/update` route — so the Dusk base only adds the headless Chrome args (`--no-sandbox`, `--disable-gpu`, `--window-size`) and the `setUp()` reseed. `beforeServingApplication()` is intentionally deferred to T17 (its first consumer).
> - **DB lifecycle settled = `DatabaseTruncation` (NOT `DatabaseMigrations`).** PoC `tests/Browser/HarnessTest.php` proves all three acceptance gates green & repeatable: (1) boots + renders, (2) **cross-process visibility** — a row the *served* process writes is read back by the *test* process over the shared file SQLite at `database_path('database.sqlite')`, (3) **clean-but-seeded isolation** via truncation + `setUp()` reseed (ordered with `#[Depends]`). The shared file (`vendor/orchestra/testbench-dusk/laravel/database/database.sqlite`) is created/migrated/seeded by `php vendor/bin/testbench workbench:build`.
> - **Cross-process write is a deterministic support route, not the funnel.** Added `GET /__t/write-reservation` (workbench, `/__t/` harness namespace) so the served process writes a `Reservation` on demand. The organic search→confirm funnel that creates a reservation is **T14**; coupling the *gate* to the Alpine surface (T13) would make everything downstream flaky.
> - **Two translation gotchas for T11/T12.** (1) The Dusk **test process must not inherit `phpunit.xml`'s `DB_DATABASE=:memory:` / `SESSION_DRIVER=array`** — they would break the shared file DB (Gotcha #5) and the session cart (Gotcha #4). T11's `phpunit.dusk.xml` must set `SESSION_DRIVER=file`, `CACHE_STORE=file` and **omit `DB_DATABASE`** (fall through to the file path). (2) Dusk's resolver **prefixes selectors with `body `**, so `assertPresent('body')` becomes the impossible `body body` — assert a real descendant instead (`[wire\:id]`, `[name=datepicker]`); and the harness content set seeds **no home entry**, so `visit('/')` is a bodyless 404 — smoke checks visit **`/bookable`**.

## T11 — Separate `Browser` PHPUnit suite + composer script
**Goal:** Run browser tests via PHPUnit, isolated from the fast unit/feature suite so `composer test` never launches Chrome.
**Steps:**
1. Add a dedicated config `phpunit.dusk.xml` (or a `<testsuite name="Browser">` invoked by name) pointing at `tests/Browser/`. Keep `tests/Browser/` **out** of both `phpunit.xml` and `phpunit.pgsql.xml` `<testsuites>` (project memory: suites are enumerated explicitly; a dir absent from those files is never run by the default/pgsql commands — which is exactly what we want here).
2. Add composer scripts:
   ```json
   "test:browser": "vendor/bin/phpunit -c phpunit.dusk.xml",
   "test:browser:headed": "DUSK_HEADLESS_DISABLED=1 vendor/bin/phpunit -c phpunit.dusk.xml"
   ```
3. Confirm isolation: `composer test` (existing suite) must NOT start Chrome and must NOT touch `tests/Browser/`.
**Files:** `phpunit.dusk.xml` (new), `composer.json` (scripts).
**Acceptance:** `composer test` runs the existing PHPUnit suite with no browser; `composer test:browser` runs the T10 boot test green in headless Chrome.
**Notes:** No `tests/Pest.php` — this project is PHPUnit-only (Decision).
> **T11 outcome:** `phpunit.dusk.xml` (own `Browser` testsuite → `tests/Browser/`, file DB + file session, header comment guarding against a `:memory:`/`array` regression) + composer scripts `test:browser` and `test:browser:headed` (the latter sets `DUSK_HEADLESS_DISABLED=1`, which testbench-dusk honors — `vendor/orchestra/testbench-dusk/src/TestCase.php:338`). Isolation was proven cheaply with `vendor/bin/phpunit --list-tests` (default + pgsql suites enumerate **0** browser tests, so `composer test`/`test-pgsql` never load the suite or launch Chrome) instead of the full ~8.5-min run; `composer test:browser` runs the 3 T10 PoC tests green. Prerequisite stands: run `php vendor/bin/testbench workbench:build` first to create/seed the shared file DB (the build is a CI step in T21).

## T12 — First green browser test: global-leak regression guard + calendar
**Goal:** Prove the harness end-to-end AND lock in the `window.L` global-leak fix as an automated regression.
**Steps:**
1. `tests/Browser/SmokeTest.php` (PHPUnit, extends `BrowserTestCase`):
   - `visit` the bookable page; assert no JS console errors (read `driver->manage()->getLog('browser')` and assert no SEVERE entries).
   - Assert globals via executed JS (`$browser->script('return typeof window.dayjs')` etc.): `window.dayjs` is `'function'`, and the leak globals are gone — `window.L` is `'undefined'` (Gotcha #3). Assert specific known-bad globals **by name**; do **not** snapshot `Object.keys(window).length` (it varies across Chrome versions → flaky).
   - Open the calendar (click the date input), assert the calendar element is visible, pick a date, assert availability/price text appears (Livewire round-trip OK).
**Files:** `tests/Browser/SmokeTest.php` (new).
**Acceptance:** `composer test:browser` runs this test green in headless Chrome.
**Notes:** This is the concrete payoff of commit `4c93a2f` — a browser test that would have caught the Leaflet `window.L` collision.
> **T12 outcome + selectors Phase-4 reuses:** `tests/Browser/SmokeTest.php`, 2 methods, green via `composer test:browser` (full suite now 5 tests / 16 assertions). Findings:
> - **Globals:** in-browser `typeof window.dayjs === 'function'`, `typeof window.L === 'undefined'` — the leak guard. Asserted by name (never snapshot `Object.keys(window)` — Chrome-version-flaky).
> - **Console:** the served harness ships no favicon, so a `favicon.ico` 404 is the **only** SEVERE entry — filtered by name (`str_contains(..., 'favicon.ico')`), so a real JS error / missing bundle still fails the assertion.
> - **Calendar DOM (`@reachweb/alpine-calendar`):** popup = `.rc-popup-overlay`; days are `<div class="rc-day …">` (NOT buttons, no `data-date`); available/clickable day = `.rc-day--available`; price label = `.rc-day__label` (currency symbol + price). With `show-availability-on-calendar=true` the **single seeded rate auto-selects** (`reconcileRate`), so opening the calendar fires `$wire.availabilityCalendar()` and paints 20 labels. `$calendar` defaults to **`single`** → one click picks a date.
> - **Round-trip signal:** after a date pick, `AvailabilityResults` renders the Book Now action `[wire\:click="checkout()"]` (gated on availability `message.status === true`) and writes the formatted date into `input[name=datepicker]`. Phase-4 (T13/T14) reuses these selectors.

---

# Phase 4 — Focused browser scenarios

> Each task drives a **real browser** through a flow that genuinely needs JS/DOM — not a re-run of
> the headless suite. The component option matrix lives in `src/Livewire/*.php` (public properties
> = mount options, public methods = actions, `#[On]` = events). Use the seeded data from T7; add
> per-test variants as needed. Acceptance for every Phase-4 task: the new test passes via
> `composer test:browser` and asserts **rendered/reactive** behavior, including (where noted) an
> **error/edge** path. Keep the matrix small — 6–8 scenarios total.

## T13 — AvailabilitySearch (the Alpine surface)
**Cover:** `@reachweb/alpine-calendar` open + single vs range pick + `clearDates()`; `enableQuantity` stepper (+/- min/max) reactivity; `rates` dropdown incl. `anyRate`; `live` auto-dispatch on date pick (results update without a submit click); **session persistence** (set dates, reload page, dates still present — Gotcha #4). Assert one validation error renders in the UI (e.g. minimum-duration) — the rule logic itself is already unit-tested, here we only confirm it *renders*.
**Files:** `tests/Browser/AvailabilitySearchTest.php`. **Source:** `src/Livewire/AvailabilitySearch.php`, `Forms/AvailabilityData.php`. (Note: this does **not** exercise `AvailabilityControl` — a separate, empty-`<div>` component that stays headless-only.)
> **T13 outcome + load-bearing harness fix (READ before any other Phase-4 task):**
> - **🔑 File session leaks across Dusk tests — fixed centrally in `BrowserTestCase::setUp()`.** The cart (`resrv-search`) is FILE-backed (Gotcha #4); unlike the truncated DB, those session files (and the browser cookie) **survive between tests**, so a date/rate one test leaves pollutes the next (a stale far-future date even moves the calendar view off the seeded window). `clearFrontendSessions()` now wipes `config('session.files')` — which resolves to the **same** dir the served app writes (`vendor/orchestra/testbench-dusk/laravel/storage/framework/sessions`) — before every test. **Every Phase-4 test now starts with a clean cart automatically; don't re-solve this.** Within a single test the session still persists across reloads (that's how the persistence test passes).
> - **`$wire` is NOT a document global.** To poke a component from `$browser->script()`, use `window.Livewire.all().find(c => c.el.querySelector('…'))` then `window.Livewire.find(c.id).set(...)` (avoids CSS colon-escaping). Used to inject an invalid date pair for the validation-render check.
> - **Seed `available=1` is a hard constraint.** Any flow that must resolve availability keeps `quantity = 1`; pushing quantity to 2 yields "no availability" (no Book Now). The quantity stepper is client-clamped (decrement disabled at 1, increment at `maximum_quantity`) so it can't drive a server error — its test is pure Alpine reactivity (read `.value`/`.disabled` DOM props, NOT text/attribute).
> - **Range mode needs its own mount** (`$calendar` is `#[Locked]`): added `/__t/search-range` (search-only, no results component) — assert the range string in `input[name=datepicker]` (`"DD Mon YYYY – DD Mon YYYY"`, en-dash). Two day clicks must fire in ONE `script()` before Alpine re-renders. Rates dropdown auto-selects the single seeded rate (`#availability-search-rate` value = numeric id); multi-rate/anyRate deferred to T15/T20.

## T14 — Standard checkout happy path (E2E)
**Cover:** the whole funnel in one readable test — visit bookable page → pick date range + quantity (+ rate) → see availability → proceed → add an extra + select an option → fill the customer form (all required fields) → offline "Confirm reservation" → land on the completed page.
**Files:** `tests/Browser/StandardCheckoutFlowTest.php`. **Source:** `AvailabilitySearch`, `AvailabilityResults`, `Checkout`, `CheckoutForm`, `CheckoutPayment`, `OfflinePaymentGateway`.
**Acceptance addition:** completed-page URL reached (with `?payment_pending={id}`), reservation row is **CONFIRMED** (query the shared file DB in the test process), and availability is **decremented** (listener side effect) — assert all three.
**Notes:** This is the headline test the user asked for. It is the living documentation of the flow.

## T15 — Multi-rate cart happy path (`AvailabilityMultiResults`)
**Cover:** the v6 cart-based multi-rate / multi-date component (this **replaces** the old "advanced/per-property availability", which was removed in v6 — see `RELEASE-v6.0.0.md`). Build a cart of **three** selections via `updateRateQuantity` + `addSelections()` (each cart line shows the correct `lineTotal`); `removeSelection()` on one updates the grand `totalPrice`/`totalQuantity` and leaves **two** lines; `checkout()` → a single reservation with **multiple** lines, redirect to checkout. (Starting with three keeps the post-removal cart multi-line, which a 2→1 removal would not.) Include the empty-cart error path ("Please select at least one rate").
**Files:** `tests/Browser/MultiRateCartTest.php`. **Source:** `src/Livewire/AvailabilityMultiResults.php`.
**Notes:** Seed at least two rates on the entry so the cart has more than one line to combine.

## T16 — CheckoutForm rich JS fields
**Cover:** only the field types with a real JS surface — `dictionary_phone` (the Alpine `phonebox` combobox: country-code dropdown, arrow/enter **keyboard nav**, type-to-filter) and `toggle` (Alpine switch). Assert inline required-field validation renders for at least one field. Plain `text`/`textarea`/`select`/`radio`/`checkboxes`/`integer`/`dictionary` are left to the headless suite unless one is needed to submit the form.
**Files:** `tests/Browser/CheckoutFormFieldsTest.php`. **Source:** `src/Livewire/CheckoutForm.php`, `resources/views/livewire/components/fields/dictionary_phone.blade.php` (+ siblings).
**Notes:** `dictionary_phone` is the richest JS surface — explicitly test keyboard navigation and filtering, not just a value set.

## T17 — Gateway picker + one error path
**Cover:** with **two** gateways the picker (`checkout-gateway-picker.blade.php`) renders and selects; the payment table (`checkout-payment-table.blade.php`) reflects the choice. Register the second gateway in the **served** app via `beforeServingApplication()` (Gotcha #7) — a small offline-style stub alongside `offline` (a real second gateway, not a `Config::set()` in the test process, which the browser wouldn't see). Also assert **one representative error path** renders in the browser (e.g. attempting checkout without choosing a rate → "please select a rate").
**Files:** `tests/Browser/GatewayPickerTest.php`. **Source:** `src/Livewire/Checkout.php`, `resources/views/livewire/components/checkout-gateway-picker.blade.php`.
**Notes:** With a single gateway the picker is auto-skipped — that single-gateway auto-select is already covered by T14, so this task is specifically the *multi*-gateway UI.

## T18 — Coupon / dynamic-pricing reactivity
**Cover:** apply a coupon (a `DynamicPricing` with a code) at checkout via `checkout-coupon.blade.php` → totals in the payment table change live (`coupon-applied`); remove it → totals revert (`coupon-removed`). One percentage and one flat adjustment is enough to prove the UI reflects both; the pricing math itself is unit-tested.
**Files:** `tests/Browser/CouponPricingTest.php`. **Source:** `HandlesPricing`, `DynamicPricing`, `resources/views/livewire/components/checkout-coupon.blade.php`.

## T19 — (optional) AvailabilityCollection live list
**Cover:** the no-extra-package collection list — mount by `collection`; compare which rows render with `showUnavailable` **mounted `true` vs `false`** (it's a `#[Locked]` mount option, not a UI toggle — mount two component instances to compare, don't click a switch); `paginate` page nav; `select($entryId,$rateId)` → redirect. Borderline vs the headless suite; include it only if the live list/pagination JS proves worth a browser assertion.
**Files:** `tests/Browser/AvailabilityCollectionTest.php`. **Source:** `src/Livewire/AvailabilityCollection.php`.
**Notes:** **`LfAvailabilityFilter` is deliberately excluded** from core coverage: it is registered only when `reachweb/statamic-livewire-filters` is installed (`src/Providers/ResrvLivewireProvider.php:60`) and that package is not a dependency. If you want it covered, make a *separate optional suite* that `composer require --dev`s `reachweb/statamic-livewire-filters` first, then drives `filters/lf-availability.blade.php`.

## T20 — Cross-collection rate reconciliation (shared session, real navigations)
**Why this exists:** the branch `fix/stale-rate-session-reconcile` removed the opt-in `resetOnBoot` wipe and replaced it with `AvailabilityData::reconcileRate($validRateIds, $ratesEnabled)`, which every availability component now calls in `availabilitySearchChanged()`/`mount()`. Because `#[Session('resrv-search')]` is shared across **all** availability components **and across page loads** (Gotcha #4), a rate chosen on one collection is carried to the next. `reconcileRate()` heals it: it **drops a numeric rate not valid in the current context** (foreign collection, entry-restricted, unpublished/deleted) and **auto-selects when exactly one valid rate exists**. This is a session-spanning, multi-page behavior — the one thing the headless `Livewire::test` suite can only approximate, so it earns a browser test.
**Cover (drive real page navigations; assert the rendered rate `<select>`, not just server state):**
1. **Foreign rate dropped.** Visit entry **A1** (collection A, ≥2 rates), pick dates, select rate `a-flex` (persists to `resrv-search`). Navigate to entry **B1** (collection B, its own ≥2 rates — `a-flex` is foreign). Assert B1's rate `<select>` does **not** carry/offer `a-flex`, its results render B's rates, and availability does **not** error or come back empty from filtering by a non-existent rate.
2. **Single valid rate auto-selects.** Navigate to entry **B2** (collection B, exactly **one** rate `b-solo`). Assert the search shows `b-solo` already selected and results price by it **without** the user choosing — the `count($validRateIds) === 1` branch of `reconcileRate()`.
3. **Collection listing scoping.** Mount `availability-collection` for collection B while a collection-A rate is still in session. Assert the listing renders B's entries (not empty) and offers only B's rates — the foreign rate doesn't survive as a WHERE filter that empties the list (`AvailabilityCollection::listingRateIds()`).
4. **Negative guard — context-less bar must NOT over-reset.** A standalone `<livewire:availability-search :rates="true" />` with **no `entry`** is context-less: `reconcileRateForContext()` deliberately leaves its value for the receiving Collection/Results to heal. Assert that manually setting a rate on this global bar and triggering a Livewire round-trip **keeps** that rate (the fix didn't reintroduce a wipe).
**Files:** `tests/Browser/CrossCollectionRateReconcileTest.php`. **Source:** `src/Livewire/Forms/AvailabilityData.php::reconcileRate()`, `AvailabilitySearch::reconcileRateForContext()`, `AvailabilityCollection::listingRateIds()` + `scopedEntriesQuery()`, and the `reconcileRate()` calls in each consumer's `availabilitySearchChanged()`.
**Seed (extend T7 / `SeedsBookableContent`):** a **second** collection `B` (own route + resrv blueprint) with entry **B1** (two rates) and entry **B2** (one rate), alongside collection A's existing entry. Use different rate slugs per collection so their ids are mutually foreign.
**Acceptance:** passes via `composer test:browser`; each step asserts the **rendered** rate options after navigation (foreign rate absent, single rate auto-selected), not just DB/server state. The negative guard (step 4) must also pass.
**Notes:** Logic-level coverage already exists headless on this branch (`tests/Livewire/AvailabilityCollectionTest` — `test_drops_a_rate_that_belongs_to_another_collection`, `…restricted_to_an_entry_not_in_the_listing`, `…cross_collection_entry_id_in_the_config`; `AvailabilitySearchTest` — the context-less-bar case). This task's unique value is the **shared session carried across actual browser navigations** with reconciliation visible in the DOM.

---

# Phase 5 — CI & maintenance

## T21 — CI workflow
**Goal:** Run the Browser suite in CI with a matched Chrome/ChromeDriver, isolated from the fast suite.
**Steps:**
1. Add a GitHub Actions job (separate from the unit/feature job): checkout, PHP 8.4, `composer install`, install Chrome (`browser-actions/setup-chrome` or the runner's preinstalled Chrome), run the chrome-driver updater with `--detect`, `chmod -R 0755 vendor/laravel/dusk/bin/`.
2. `php vendor/bin/testbench workbench:build` (creates + migrates + seeds the file SQLite), then `composer test:browser` (headless). On failure, upload `tests/Browser/screenshots` + console logs as artifacts (Dusk auto-screenshots on failure).
3. Keep the existing unit/feature job (`composer test`, `composer test-pgsql`) untouched and Chrome-free.
**Files:** `.github/workflows/*.yml`.
**Acceptance:** CI runs both jobs; the browser job is green and uploads screenshots on induced failure.
**Notes:** Gotcha #11 — pin/detect the driver; consider a Selenium service container if runner-Chrome drift becomes painful.

## T22 — Developer docs
**Goal:** Make the harness discoverable and runnable by the next developer.
**Steps:**
1. Add a "Browser tests" section to the README/CONTRIBUTING: prerequisites (Chrome), `composer test:browser`, headed mode, how to add a scenario, the Gotchas summary, and the **what-is-and-isn't browser-tested** philosophy (headless suite owns validation/pricing/state; browser owns JS/funnel).
2. Add a short note to `CLAUDE.md` Commands section: `composer test:browser` (testbench-dusk, **PHPUnit** — separate from the default suite; needs Chrome).
**Files:** `README.md` (or docs), `CLAUDE.md`.
**Acceptance:** a fresh clone can follow the docs to a green `composer test:browser`.

## T23 — (optional) Evaluate Pest v4 Playwright as a cross-browser smoke layer
**Goal:** Decide whether to add a *thin* Playwright layer for `assertNoSmoke()` + cross-browser (Firefox/WebKit) smoke, **on top of** (not replacing) the Dusk harness — only if it can be done without disturbing the PHPUnit 13 pin.
**Steps:**
1. **Hard constraint:** Pest 4 currently pins `phpunit/phpunit:^12.5.x`, which conflicts with this repo's PHPUnit 13. So this can only ever live in an **isolated** setup (e.g. a separate `composer` project / container that doesn't share the main lock), or it waits until Pest supports PHPUnit 13. Confirm the current constraint before spending time.
2. If isolatable: spike `pestphp/pest-plugin-browser` + `npx playwright install`, point its server at a `php vendor/bin/testbench serve` instance (the package-serving path is undocumented — timebox it), and port just the global-leak smoke + a cross-browser calendar render check.
3. Record a go/no-go note with evidence. If it can't be isolated from the main PHPUnit pin, **stop** — Dusk remains the spine.
**Files:** spike branch only; do not merge unless it is provably isolated from `composer.lock`'s PHPUnit version.
**Acceptance:** a written go/no-go note in this task with evidence (works → minimal example; doesn't → the blocker, incl. the PHPUnit-version conflict if that's what stops it).

---

## Appendix — Component → coverage matrix

| Frontend Livewire component / surface | Source | Browser task | Notes |
|---|---|---|---|
| AvailabilitySearch (+ Alpine calendar, JS globals) | `src/Livewire/AvailabilitySearch.php` | **T13**, T12 | richest Alpine surface |
| AvailabilityControl | `src/Livewire/AvailabilityControl.php` | — | renders empty `<div>`; headless-only, no browser task |
| AvailabilityResults | `src/Livewire/AvailabilityResults.php` | T14 (via funnel) | state/validation already headless |
| AvailabilityMultiResults (cart) | `src/Livewire/AvailabilityMultiResults.php` | **T15** | v6 replacement for advanced/connected availability |
| AvailabilityCollection | `src/Livewire/AvailabilityCollection.php` | T19 (optional), **T20** | mostly headless-covered; T20 = cross-collection rate scoping |
| Cross-collection rate reconciliation (shared `resrv-search`) | `AvailabilityData::reconcileRate()` + each consumer's `availabilitySearchChanged()` | **T20** | guards the `resetOnBoot` → `reconcileRate` fix; logic headless-covered, browser adds real cross-page navigation |
| AvailabilityList (+ list-by-date) | `src/Livewire/AvailabilityList.php` | — | headless-covered; no special JS |
| LfAvailabilityFilter | `src/Livewire/LfAvailabilityFilter.php` | — | optional dep not installed; deferred (see T19) |
| Extras | `src/Livewire/Extras.php` | T14 (via funnel) | enable/disable + price math headless |
| Options | `src/Livewire/Options.php` | T14 (via funnel) | required-selection + snapshot headless |
| Checkout (+ steps, gateway picker, coupon) | `src/Livewire/Checkout.php` | **T17**, **T18** | orchestration headless; browser = picker + coupon reactivity |
| CheckoutForm (rich JS fields only) | `src/Livewire/CheckoutForm.php`, `views/.../fields/*` | **T16** | `dictionary_phone`, `toggle`; plain types headless |
| CheckoutPayment (offline) | `src/Livewire/CheckoutPayment.php` | T14 (confirm step) | → CONFIRMED assertion |
| Full funnel (search → confirmed) | all of the above | **T14** | headline E2E |
| Global-leak regression (`window.L`) | compiled IIFE bundle | **T12** | guards commit `4c93a2f` |

## Appendix — Key existing files to reuse (do not reinvent)
- `tests/TestCase.php` — Statamic boot, `registerLivewireUpdateRoute()` (Gotcha #8), `ensureCollectionWithResrvField()`, outpost prevention, `getPackageProviders()`.
- `tests/CreatesEntries.php` — `makeStatamicItemWithAvailability()`, `createEntries()` (seeding).
- `tests/Livewire/CheckoutTest.php` — `setUp()`: checkout-entry config + extras/options attachment.
- `src/Http/Payment/OfflinePaymentGateway.php` + `resources/views/livewire/checkout-payment-offline.blade.php` — Stripe-free confirm path.
- `src/Providers/ResrvLivewireProvider.php` — component registration names (note the conditional `lf-availability-filter`).
- `resources/js/resrv-frontend.js` / `vite-frontend.config.js` — IIFE bundle, `@reachweb/alpine-calendar` via `alpine:init`, `window.dayjs` (the leak-fix context T12 guards).
- `vendor/orchestra/workbench/src/Console/stubs/testbench.yaml` — canonical shape for T4 (`migrations:` list, full `build:` pipeline, `laravel:` key).
