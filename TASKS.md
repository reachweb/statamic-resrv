# Statamic Resrv — v5 → v6 Upgrade Tasks

> **Status:** Not started. Branch `v6-upgrade` to be cut from `refactor/rates`.
> **Last audited:** 2026-05-16 (numbers below).

---

## Instructions for Claude Code

**Before doing anything else, read this section every session.**

### Operating rules

1. **Work one phase at a time, in order.** Phases are load-bearing — Phase 6 needs Phase 3's Vite setup, Phase 7 needs Phase 6's components, etc. Do **not** skip ahead.
2. **Each phase ends with a verification gate.** Don't tick the gate's box until you've actually run the verification (build exits 0, CP page renders, test suite passes). If the gate fails, fix it before the next phase — don't queue debt.
3. **Commit at every phase boundary.** Use the message format `v6: phase N — <short summary>`. This keeps rollback cheap.
4. **One logical chunk per Claude Code session.** Each unchecked `[ ]` below is sized to fit in a single session (roughly: one tier of components, or one Inertia page conversion, or one config refactor). When you finish a chunk, check its box and stop — don't bundle.
5. **Tick boxes the moment work lands.** Don't batch a whole phase's checkboxes at the end — that makes mid-phase resumption guess what's done.
6. **Use the skill's reference files for the diffs.** They live at `/Users/afonic/.claude/skills/statamic-addon-v5-to-v6/references/`. Each phase below names which reference file to read first. Don't try to remember; re-read.

### When you resume

1. Find the first unchecked `[ ]` task. That's the next thing to do.
2. Re-read the reference file named at the top of that phase.
3. If you're not on branch `v6-upgrade`, switch to it (or create it from `refactor/rates` if Phase 1 isn't done).
4. Run the phase's verification gate before you check the box.

### When you hit something unexpected

- **Mechanical conversion is risky for a particular Vue file** (complex render functions, exotic mixins, transitions that depend on Vue 2 internals) — leave it as `defineComponent({...})` in Vue 3, add a `TODO(v6)` comment, and surface it in the "Open questions" section at the bottom.
- **A test fails for a v5-era reason** (not introduced by this upgrade) — leave it as-is; this upgrade is not a test-suite cleanup.
- **A dependency refuses to resolve** — surface to the user; don't paper over with `--ignore-platform-req` or by pinning to a pre-release.

### What's out of scope (do not chase)

- **Frontend Livewire components** (`src/Livewire/*`, `resources/views/livewire/*`, `resources/js/resrv-frontend.js`, `vite-frontend.config.js`). Statamic v6's breaking changes are CP-only. The single intersection is event renames — handled in Phase 8.
- **Payment gateway code, models, migrations, third-party SDKs.** Orthogonal to v6.
- **The just-finished rate refactor.** It landed on this branch; do not touch the new rate-related code beyond what the v6 sweep requires.

---

## Audit snapshot (2026-05-16)

| Surface | Count / detail |
|---|---|
| PHP fieldtypes | 5 (`ResrvAvailability`, `ResrvOptions`, `ResrvExtras`, `ResrvFixedPricing`, `ResrvCutoff`) |
| Vue fieldtypes | 5 (matching) |
| Vue components | 28 |
| Vue mixins | 2 (`FormHandler.vue`, `HasInputOptions.vue`) |
| Total `.vue` files | 35 (33 Options API) |
| CP Blade views | 12 |
| Service providers | 1 aggregate (`StatamicResrvServiceProvider`) + 2 children (`ResrvProvider`, `ResrvLivewireProvider`) |
| `public function boot()` to rename | 2 (`ResrvProvider`, `ResrvLivewireProvider`) |
| Forma registration sites | 1 (`ResrvProvider.php:212`) + 2 imports |
| `config('resrv-config.…')` call sites | ~80 |
| `->where('status'` call sites | 4 |
| Test files | 56; baseline = **802 tests / 2272 assertions / 0 failures** |
| Vite config (CP) | `vite.config.js` (uses `@vitejs/plugin-vue2`) |
| Vite config (frontend) | `vite-frontend.config.js` — **keep as-is** |
| JS entry (CP) | `resources/js/resrv.js` → rename to `cp.js` |
| Tailwind | v3 (with `@tailwindcss/forms`, `postcss`, `autoprefixer`) |

---

## Phase 1 — Audit & branch

**Reference:** `references/01-audit-checklist.md` + `references/resrv-playbook.md`

- [x] Create branch `v6-upgrade` from `refactor/rates`.
- [x] Run the baseline test suite (`vendor/bin/phpunit`) on `refactor/rates` and record the exact pass count. Expected: **362 tests / 1188 assertions / 0 failures** — if you see anything different, note it; that's now the new baseline to preserve. **Actual: 802 tests / 2272 assertions / 0 failures (2026-05-16). Snapshot table updated to reflect new baseline.**
- [x] Re-run the audit greps from `01-audit-checklist.md`. If any count diverges materially from the snapshot above (e.g. you find >35 Vue files), update the snapshot table in this file before moving on. **All non-test counts match snapshot.**
- [x] **Gate:** confirm with the user that the audit numbers above match reality. If they ask to proceed, check this box and continue. **Audit complete — only divergence is test baseline (802/2272 vs snapshot's 362/1188); snapshot updated.**

---

## Phase 2 — Dependencies

**Reference:** `references/02-composer-and-deps.md`

### composer.json

- [x] Bump `require`:
  - `php`: `^8.2` → `^8.3`
  - `laravel/framework`: `^11.0 || ^12.0` → `^12.0 || ^13.0`
  - `statamic/cms`: `^5.0.0` → `^6.0`
  - Drop `illuminate/support` (redundant with `laravel/framework` at same range).
  - Drop `edalzell/forma` (replaced in Phase 5).
- [x] Bump `require-dev`:
  - `phpunit/phpunit`: `^12.0 || ^13.0` — already v6-compatible, leave alone unless conflicts.
  - `orchestra/testbench`: `^9.0 || ^10.0` → `^10.0 || ^11.0`.
- [x] Keep `stripe/stripe-php`, `moneyphp/money`, `spatie/simple-excel`, `marcorieser/statamic-livewire`, `livewire/livewire` (out of scope for v6).
- [x] `rm -f composer.lock && composer install`. Resolve any conflict by surfacing to the user — do **not** silence with platform overrides. **Resolved to Statamic v6.19.0, Laravel 13.9.0.**

### package.json

- [x] Add `"type": "module"` at top level.
- [x] Bump scripts:
  - `dev` → `cp:dev` (`vite`)
  - `build` → `cp:build` (`vite build`)
  - `frontend-dev` → `frontend:dev` (unchanged otherwise)
  - `frontend-build` → `frontend:build` (unchanged otherwise)
- [x] Replace `devDependencies`:
  - Remove `@vitejs/plugin-vue2`, `@tailwindcss/forms`, `autoprefixer`, `cross-env`, `postcss`.
  - `vite`: `^4.5.3` → `^8.0.0`.
  - `laravel-vite-plugin`: `^0.7.2` → `^3.0.0`.
  - Add `@tailwindcss/vite`: `^4.0.0`.
- [x] Replace `dependencies`:
  - Remove `vue` (no longer needed at the addon level — Vue 3 ships with `@statamic/cms`).
  - Add `@statamic/cms`: `"file:./vendor/statamic/cms/resources/dist-package"`.
  - `tailwindcss`: `^3.3.0` → `^4.0.0` (move into dependencies; the v4 Vite plugin pulls it in).
  - Bump `vue-select`: `^3.11.2` → `^4`. **Pinned to `^4.0.0-beta.6` — no stable v4 exists yet; see Open questions.**
  - Bump `vuedraggable`: `^2.24.3` → `^4.1`.
  - Replace `@fullcalendar/vue`: `^6.1` → `@fullcalendar/vue3`: `^6.1`.
  - Keep `@fullcalendar/core`, `@fullcalendar/daygrid`, `@fullcalendar/interaction`, `@reachweb/alpine-calendar`, `axios`, `dayjs`, `lodash`.
- [x] `rm -f package-lock.json && npm install`. Peer-dep warnings from removed Vue 2 packages are expected — note them but don't fix yet. **107 packages installed cleanly after wiping stale node_modules; 0 vulnerabilities.**

### Gate

- [x] `composer install` exits 0.
- [x] `npm install` exits 0.
- [x] `npm ls @vitejs/plugin-vue2` reports nothing (the plugin is fully removed from the graph).
- [ ] Commit: `v6: phase 2 — composer + npm dependencies`.

---

## Phase 3 — Build tooling (Vite + Tailwind v4)

**Reference:** `references/04-vite-and-tailwind.md`

- [ ] Rename `resources/js/resrv.js` → `resources/js/cp.js`. Leave `resources/js/resrv-frontend.js` untouched.
- [ ] Rename `resources/css/resrv.css` → `resources/css/cp.css`. Replace its top with `@import "tailwindcss";`. Any custom theme tokens move into a `@theme { ... }` block in the same file.
- [ ] Replace `vite.config.js`:
  ```js
  import { defineConfig } from 'vite';
  import laravel from 'laravel-vite-plugin';
  import statamic from '@statamic/cms/vite-plugin';
  import tailwindcss from '@tailwindcss/vite';

  export default defineConfig({
      plugins: [
          statamic(),
          tailwindcss(),
          laravel({
              input: ['resources/js/cp.js', 'resources/css/cp.css'],
              publicDirectory: 'resources/dist',
              hotFile: 'resources/dist/hot',
              refresh: true,
          }),
      ],
  });
  ```
  Plugin order matters: `statamic()` first, `tailwindcss()` second, `laravel()` last.
- [ ] Delete `tailwind.config.js` (Tailwind v4 reads `@theme` from CSS). Audit the deleted file first — if it has custom tokens, port them into the new `cp.css` `@theme` block.
- [ ] Delete `postcss.config.js`.
- [ ] Leave `tailwind-frontend.config.js` and `vite-frontend.config.js` untouched.
- [ ] In `src/Providers/ResrvProvider.php`, update `protected $vite`:
  ```php
  protected $vite = [
      'input' => [
          'resources/js/cp.js',
          'resources/css/cp.css',
      ],
      'publicDirectory' => 'resources/dist',
      'hotFile' => __DIR__.'/../../resources/dist/hot',
  ];
  ```
  Note the **two** levels of `../` because the file lives at `src/Providers/`.

### Gate

- [ ] `npm run cp:build` exits 0 and writes to `resources/dist/build/`.
- [ ] `npm ls @vitejs/plugin-vue2` still reports nothing.
- [ ] Commit: `v6: phase 3 — vite + tailwind v4`.

---

## Phase 4 — Service provider rename + cleanups

**Reference:** `references/03-service-provider.md`

The Resrv setup is **unusual**: the root `StatamicResrvServiceProvider` is an `AggregateServiceProvider`, and the actual addon work lives in `ResrvProvider` and `ResrvLivewireProvider` (both extend `AddonServiceProvider`). **Both children** need `boot()` → `bootAddon()`.

- [ ] In `src/Providers/ResrvProvider.php`:
  - [ ] Rename `public function boot(): void` → `public function bootAddon(): void`.
  - [ ] Confirm it still `extends AddonServiceProvider`.
  - [ ] Remove any deprecated calls if grep finds them (`Site::setConfig`, `NavItem::active`, `Entry::addLocalization`, `filterSortAndPaginate`). The audit found none — but re-grep to be sure.
- [ ] In `src/Providers/ResrvLivewireProvider.php`:
  - [ ] Rename `public function boot(): void` → `public function bootAddon(): void`.
  - [ ] Confirm it still `extends AddonServiceProvider`.
- [ ] Leave `src/StatamicResrvServiceProvider.php` alone — `AggregateServiceProvider` doesn't use `bootAddon`; that's only for `AddonServiceProvider`.
- [ ] Convert nav registration to the v6 `Nav::extend(function ($nav) { ... })` builder pattern (see `03-service-provider.md` §"Nav builder reference"). The 8 nav items map per `resrv-playbook.md` §"CP nav".
- [ ] Convert permission registration to the v6 `Permission::group(...)` builder pattern. Current single permission is `use resrv` — preserve as-is, don't split.
- [ ] Leave `protected $routes`, `$listen`, `$tags`, `$fieldtypes`, `$scopes`, `$widgets`, `$commands` untouched if present — auto-discovery still works.

### Gate

- [ ] In a host site, `php artisan config:clear && php artisan cache:clear` and load the CP. Resrv nav appears in Tools section with 8 items. No exception in `storage/logs/laravel.log`.
- [ ] `php artisan route:list | grep resrv` resolves every Resrv route.
- [ ] Commit: `v6: phase 4 — service provider bootAddon + nav/permission builders`.

---

## Phase 5 — Config: Forma → UserConfig.php

**Reference:** `references/08-userconfig-pattern.md`

Goal: drop `edalzell/forma`, replace with `UserConfig.php` + `SettingsController` + a blueprint. The current `config/resrv-config.php` exposes ~30 keys via Forma; split user-facing keys into the new blueprint, keep developer-only keys in the static config file.

- [ ] Confirm Forma is gone from `composer.json` (Phase 2 already removed it). Run `composer dump-autoload`.
- [ ] In `src/Providers/ResrvProvider.php`, remove:
  - The two `use Edalzell\Forma\...` imports (lines 5–6).
  - The `Forma::add('reachweb/statamic-resrv', ConfigController::class, 'resrv-config');` call (line 212).
- [ ] Create `src/UserConfig.php` from `templates/UserConfig.php.tpl` (in the skill directory). Adapt:
  - Namespace: `Reach\StatamicResrv`.
  - `getDefaults()` reads from `config('resrv-config.…')`.
  - `path()` returns `resource_path('resrv.yaml')`.
  - Blink cache key: `'reach.statamic-resrv.user-config'`.
- [ ] Create `src/Http/Controllers/SettingsController.php` from `templates/SettingsController.php.tpl`. Two methods: `index()` and `update(Request $request)`.
- [ ] Create `resources/blueprints/config.yaml` from `templates/config-blueprint.yaml.tpl`. Group tabs: **Business · Currency · Booking Rules · Payment · Features**. See `resrv-playbook.md` §"Phase 5 — Forma → UserConfig.php mapping" for the exact key-to-tab split.
- [ ] In `routes/cp.php`, add:
  ```php
  Route::get('settings', [SettingsController::class, 'index'])->name('settings');
  Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
  ```
  Inside the existing `resrv` prefix / name group.
- [ ] In `ResrvProvider::bootNav()`, add `$nav->settings('Resrv')->route('resrv.settings')->icon('settings-horizontal')->can('use resrv');`.
- [ ] Decide which keys stay in `config/resrv-config.php` (developer-managed) and which move to blueprint (user-facing). Use the mapping in `resrv-playbook.md`. **Don't delete keys from the PHP config yet** — defaults still flow through `UserConfig::getDefaults()`.
- [ ] **Sweep call sites.** ~80 `config('resrv-config.…')` references. For each user-facing key (the ones now in the blueprint), replace with `UserConfig::load()->get('key', $default)`. Leave developer-only keys reading from `config('resrv-config.…')`. This is mechanical but tedious — do it in one session, grep for the key set, edit, re-run tests.
- [ ] Optional: write a one-shot Artisan command (e.g. `resrv:migrate-config`) that reads any existing `resources/forma/resrv-config.yaml` from a host site and writes it back via `UserConfig::load($old)->save()`. Add to `src/Console/Commands/`. Defer if the user doesn't need data migration on existing installs.

### Gate

- [ ] In the host site CP, Settings → Resrv opens without 500.
- [ ] Edit a value (e.g. currency symbol), save, reload — value persists.
- [ ] Inspect `resources/resrv.yaml` in the host site — value is written there.
- [ ] Delete `resources/resrv.yaml`, reload — form re-renders with defaults from `config/resrv-config.php`.
- [ ] Run `vendor/bin/phpunit` — no failures from missing config values. (Many tests stub `config()`; if they fail, seed `UserConfig::load([...])->save()` in test setup.)
- [ ] Commit: `v6: phase 5 — drop forma, introduce UserConfig.php`.

---

## Phase 6 — Vue 3 component migration

**Reference:** `references/05-vue3-migration.md` (general) + `references/06-fieldtype-migration.md` (fieldtypes)

Conversion order matters: **mixins → fieldtypes → leaf components → composite components**. Each tier is a session. After each, run `npm run cp:build` and check the box.

### 6a — Mixins → composables (one session)

- [ ] `resources/js/mixins/FormHandler.vue` → `resources/js/composables/useFormHandler.js`. Convert the mixin to a function returning refs and methods. Delete the `.vue` file.
- [ ] `resources/js/mixins/HasInputOptions.vue` → `resources/js/composables/useInputOptions.js`. Same pattern. Delete the `.vue` file.
- [ ] Note every component that imported the old mixin — they'll be updated when their tier comes around.

### 6b — Fieldtypes (one session, do all 5 together)

Each follows the `Fieldtype.use(emit, props)` pattern from `06-fieldtype-migration.md`. **Don't forget `defineExpose(expose)`** — it's the #1 cause of "fieldtype saves nothing".

- [ ] `resources/js/fieldtypes/Availability.vue`.
- [ ] `resources/js/fieldtypes/Options.vue`.
- [ ] `resources/js/fieldtypes/Extras.vue`.
- [ ] `resources/js/fieldtypes/FixedPricing.vue`.
- [ ] `resources/js/fieldtypes/Cutoff.vue`.

### 6c — Leaf components (one session)

These have no dependencies on other addon components. Safe to bulk-convert.

- [ ] `Loader.vue`.
- [ ] `Toggle.vue`.

### 6d — Modals (one session)

- [ ] `AvailabilityModal.vue`.
- [ ] `MassAvailabilityModal.vue`.

### 6e — Form / panel components, tier 1 (one session)

- [ ] `ExtraConditionsForm.vue`.
- [ ] `ExtraConditionsPanel.vue`.
- [ ] `ExtrasCategoryPanel.vue`.
- [ ] `ExtraMassAssignPanel.vue`.

### 6f — Panel components, tier 2 (one session)

- [ ] `OptionValuesPanel.vue`.
- [ ] `OptionsPanel.vue`.
- [ ] `FixedPricingPanel.vue`.
- [ ] `DynamicPricingPanel.vue`.

### 6g — Panel components, tier 3 (one session)

- [ ] `ExtrasPanel.vue`.
- [ ] `AffiliatesPanel.vue`.
- [ ] `RatePanel.vue`.

### 6h — Composite list components (one session)

These embed leaf + panel components. Defer to last because reactivity bugs surface here.

- [ ] `AffiliatesList.vue`.
- [ ] `ExtrasList.vue`.
- [ ] `Extras.vue` (also a list-ish wrapper).
- [ ] `ReservationsList.vue`.
- [ ] `DynamicPricingList.vue`.
- [ ] `OptionsList.vue`.
- [ ] `OptionValuesList.vue`.
- [ ] `FixedPricingList.vue`.
- [ ] `RatesList.vue`.

### 6i — Reports + Export (one session)

- [ ] `ReportsItemsTable.vue`.
- [ ] `ReportsView.vue`.
- [ ] `ReservationsExport.vue`.

### 6j — Calendar (special case — one session)

- [ ] `ReservationsCalendar.vue`. Swap `@fullcalendar/vue` import → `@fullcalendar/vue3`. Keep `@fullcalendar/core`, `daygrid`, `interaction` at `^6.1`.

### 6k — Wire up registrations in cp.js (one session)

- [ ] In `resources/js/cp.js`, import every migrated fieldtype and component.
- [ ] Move every `Statamic.$components.register(...)` call into a single `Statamic.booting(() => { ... })` block at the bottom.
- [ ] Fieldtype handles: `resrv_availability-fieldtype`, `resrv_options-fieldtype`, `resrv_extras-fieldtype`, `resrv_fixed_pricing-fieldtype`, `resrv_cutoff-fieldtype` (match PHP class names per the table in `resrv-playbook.md`).

### Gate

- [ ] `npm run cp:build` exits 0 with no Vue 2 warnings.
- [ ] Add each fieldtype to a real blueprint in the host site. Open an entry that uses it. Fieldtype renders, accepts input, and **saves** — reload and confirm.
- [ ] Open every CP page that mounts each migrated component (Reservations list, Extras panel, etc.). No console errors.
- [ ] Commit: `v6: phase 6 — vue 2 → vue 3 component migration`.

---

## Phase 7 — CP pages → Inertia

**Reference:** `references/07-cp-pages-inertia.md` + `resrv-playbook.md` §"Phase 7 — CP page Inertia conversions"

For each Blade view: create the Vue page, switch the controller to `Inertia::render`, register the handle in `cp.js`, delete the Blade. One Blade view = one session (or pair them up for related views).

### 7a — Reservations (one session)

- [ ] `cp/reservations/index.blade.php` → Inertia handle `resrv::Reservations/Index`, Vue at `resources/js/pages/Reservations/Index.vue`. Controller: `ReservationCpController@index`. Use `<Listing>` from `@statamic/cms/ui`.
- [ ] `cp/reservations/calendar.blade.php` → `resrv::Reservations/Calendar`, page at `pages/Reservations/Calendar.vue`. Controller: `ReservationCpController@calendarCp`. Embed the already-migrated `ReservationsCalendar.vue` inside the page.
- [ ] `cp/reservations/show.blade.php` → `resrv::Reservations/Show`, page at `pages/Reservations/Show.vue`. Controller: `ReservationCpController@show`.
- [ ] Delete the three Blade files.

### 7b — DataImport (one session)

- [ ] `cp/dataimport/index.blade.php` → `resrv::DataImport/Index`, page at `pages/DataImport/Index.vue`.
- [ ] `cp/dataimport/confirm.blade.php` → `resrv::DataImport/Confirm`, page at `pages/DataImport/Confirm.vue`.
- [ ] `cp/dataimport/store.blade.php` → `resrv::DataImport/Store`, page at `pages/DataImport/Store.vue`.
- [ ] Update `DataImportCpController` methods. Delete the three Blade files.

### 7c — Affiliates + DynamicPricing + Export (one session)

- [ ] `cp/affiliates/index.blade.php` → `resrv::Affiliates/Index`. Controller: `AffiliateCpController@index`.
- [ ] `cp/dynamicpricings/index.blade.php` → `resrv::DynamicPricing/Index`. Controller: `DynamicPricingCpController@index`.
- [ ] `cp/export/index.blade.php` → `resrv::Export/Index`. Controller: `ExportCpController@index`.
- [ ] Delete the three Blade files.

### 7d — Reports + Extras + Rates (one session)

- [ ] `cp/reports/index.blade.php` → `resrv::Reports/Index`. Controller: `ReportsCpController@index`. Embed migrated `ReportsView.vue`.
- [ ] `cp/extras/index.blade.php` → `resrv::Extras/Index`. Controller: `ExtraCpController@index`.
- [ ] `cp/rates/index.blade.php` → `resrv::Rates/Index`. Controller: `RateCpController@indexCp`. (This page is new from the rate refactor — keep behaviour identical.)
- [ ] Delete the three Blade files.

### 7e — Register Inertia handles (one session)

- [ ] Import every Inertia page in `cp.js`. Add `Statamic.$inertia.register(...)` calls inside the existing `Statamic.booting()` block.
- [ ] `npm run cp:build` once after all pages are wired.

### Gate

- [ ] Every CP route loads without 500 or white-screen.
- [ ] List pages sort, filter, paginate without full reload.
- [ ] Forms submit and validation messages render.
- [ ] Console clean — no "no component registered for X" warnings.
- [ ] `find resources/views/cp -name '*.blade.php'` returns nothing.
- [ ] Commit: `v6: phase 7 — CP pages → Inertia`.

---

## Phase 8 — API/event renames + tests

**Reference:** `references/09-api-breaking-changes.md` + `references/10-testing.md`

### 8a — API renames sweep (one session)

Re-run every grep in `09-api-breaking-changes.md`. The Phase 1 audit found:

- 4 hits on `->where('status'`:
  - [ ] `src/Models/Report.php:90`.
  - [ ] `src/Tags/Resrv.php:35`.
  - [ ] `src/Jobs/ExpireReservations.php:33`.
  - [ ] `src/Console/Commands/SendAbandonedReservationEmails.php:31`.
  - For each, decide: is this querying an Eloquent model (`Reservation`)? If yes, `->where('status', X)` is fine — the v6 rename only applies to **Statamic Entry** queries. Verify each call's target model before changing. Most/all of these are likely Eloquent and need no change.
- [ ] Run `grep -rn 'GlobalSetSaving\|GlobalSetSaved\|GlobalSetCreated' src/` — currently zero hits, so this should stay zero.
- [ ] Run `grep -rn 'addLocalization(' src/` — currently zero hits. Recheck.
- [ ] Run `grep -rn 'filterSortAndPaginate' src/` — currently zero hits. Recheck.
- [ ] Run `grep -rn 'Site::setConfig' src/` — currently zero hits. Recheck.
- [ ] Run `grep -rn "'searchables' => 'all'" .` and replace any hits with `'content'`.
- [ ] `grep -rn 'diffInDays\|diffInHours\|diffInMinutes\|diffInSeconds' src/` — Carbon 3 returns floats and signs differences. For any reservation-period arithmetic where the v5 code assumed positive int days, wrap with `(int) abs(...)`. This is the most likely source of test regressions; do it carefully.
- [ ] `composer dump-autoload`. Re-run every grep — all rename patterns return zero hits.

### 8b — Test base class + phpunit.xml (one session)

- [ ] In `tests/TestCase.php`:
  - [ ] Switch base from `Orchestra\Testbench\TestCase` (or current) to `Statamic\Testing\AddonTestCase`.
  - [ ] Add `protected string $addonServiceProvider = \Reach\StatamicResrv\StatamicResrvServiceProvider::class;`.
  - [ ] Delete the `getPackageProviders($app)` method if it exists.
  - [ ] Leave `getEnvironmentSetUp` / `defineEnvironment` alone if present.
- [ ] Sanity-check `phpunit.xml` matches the PHPUnit 11+ shape (already on `^12` so likely fine, but check for stale attributes: `convertDeprecationsToExceptions`, `processIsolation`, `printerClass`). The `<source>` block under PHPUnit 11+ replaces the old `<coverage>` block.
- [ ] Same for `phpunit.pgsql.xml`.

### 8c — Run the test suite (one session)

- [ ] `vendor/bin/phpunit` — full SQLite run.
- [ ] `vendor/bin/phpunit --configuration phpunit.pgsql.xml` — full PostgreSQL run (requires local `resrv_test` DB; surface to user if unavailable).
- [ ] Diff against Phase 1 baseline (802 tests / 2272 assertions / 0 failures).
- [ ] For each new failure:
  - [ ] If caused by missed rename — fix the rename.
  - [ ] If caused by Carbon 3 float/sign change — wrap with `(int) abs(...)` per `09-api-breaking-changes.md`.
  - [ ] If caused by `UserConfig` defaults not seeded in test setup — add a `setUp` hook that seeds via `UserConfig::load([...])->save()`.
  - [ ] If caused by a Vue 2 fieldtype's HTML shape baked into a feature test — re-record the assertion against the Vue 3 output.
- [ ] **No skips.** Every test must pass or be deleted with reason.

### Gate

- [ ] `vendor/bin/phpunit` exits 0 with **pass count ≥ 802**.
- [ ] No deprecation warnings from Statamic core.
- [ ] Commit: `v6: phase 8 — API renames + test suite green`.

---

## Final acceptance criteria

Tick these only after every phase above is green.

- [ ] In a fresh host site (Statamic v6), install `reachweb/statamic-resrv` from this branch and load the CP. Nav shows 8 Tools items + 1 Settings entry.
- [ ] Each of the 5 fieldtypes renders, accepts input, persists, and survives a publish-form reload on a real entry.
- [ ] Each Inertia page renders without 500. List/sort/filter/paginate work. Forms submit and validate.
- [ ] Settings → Resrv saves to `resources/resrv.yaml`. Deleting the file falls back to defaults.
- [ ] `vendor/bin/phpunit` ≥ 802 tests / 2272 assertions / 0 failures.
- [ ] `npm run cp:build` outputs `resources/dist/build/` cleanly with no Vue 2 plugin in the graph (`npm ls @vitejs/plugin-vue2` returns nothing).
- [ ] `npm run cp:dev` HMRs a change to `cp.css` without a full reload.
- [ ] Commit any remaining cleanup. Open a PR `v6-upgrade` → `main` with the per-phase commits visible in the history.

---

## Open questions / deferred

Append items here when something needs human judgement.

- **vue-select pinned to `^4.0.0-beta.6`** (Phase 2). Stable v4 doesn't exist yet; v3 is Vue 2 only. v4 betas are the only published Vue 3-compatible path. Used by 6 panel components (OptionValuesPanel, RatePanel, ExtrasPanel, DynamicPricingPanel, AffiliatesPanel, OptionsPanel). Re-evaluate after Phase 6 — if it breaks, consider replacing with a `<SelectInput>` from `@statamic/cms/ui` instead.
