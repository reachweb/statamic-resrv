<?php

use Illuminate\Support\Facades\Route;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry;

/*
 * Direct, Statamic-routing-free entry points for component-level browser tests
 * (the plan's "simpler fallback"). The Statamic-entry pages (/bookable, /checkout,
 * /multi) remain the production-faithful path; these isolate a single component.
 */
Route::get('/__t/search', function () {
    $entry = Entry::query()->where('collection', 'pages')->where('slug', 'bookable')->first();

    return view('dusk.search', ['entryId' => $entry?->id()]);
});

/*
 * Range-calendar variant of /__t/search. `$calendar` is #[Locked], so range mode
 * can only be exercised through a mount that sets it — T13 drives single pick on
 * /bookable and range pick here.
 */
Route::get('/__t/search-range', function () {
    $entry = Entry::query()->where('collection', 'pages')->where('slug', 'bookable')->first();

    return view('dusk.search-range', ['entryId' => $entry?->id()]);
});

/*
 * AvailabilityResults mounted alone for the MULTI entry (two rates). With rate='any'
 * and >1 rate the results render the advanced rate selector, and checkout() guards
 * with "Please select a rate before proceeding." — T17 drives that representative
 * error path here (the bookable entry is single-rate, so it auto-selects and can't
 * reach the guard).
 */
Route::get('/__t/results-multi', function () {
    $entry = Entry::query()->where('collection', 'pages')->where('slug', 'multi')->first();

    return view('dusk.results-multi', ['entryId' => $entry?->id()]);
});

Route::get('/__t/checkout', fn () => view('dusk.checkout'));

/*
 * T20 — cross-collection rate reconciliation. `rate-entry/{slug}` mounts a
 * search + results for any entry by slug (A1 = `multi`, two 'pages' rates; B1 =
 * `room-flex`, two 'rooms' rates; B2 = `room-solo`, one 'rooms' rate) so a rate
 * chosen on one is carried through the shared session to the next. `rate-collection`
 * mounts availability-collection for the 'rooms' collection, and `rate-bar` a
 * context-less search bar (no entry) for the negative-guard step.
 */
Route::get('/__t/rate-entry/{slug}', function (string $slug) {
    $entry = Entry::query()->where('slug', $slug)->first();

    return view('dusk.rate-entry', ['entryId' => $entry?->id()]);
});

Route::get('/__t/rate-collection', fn () => view('dusk.rate-collection'));

Route::get('/__t/rate-bar', fn () => view('dusk.rate-bar'));

/*
 * T19 — AvailabilityCollection live list (the `rooms` collection). `collection` mounts one
 * instance for the listing render + select → detail-page redirect; `collection-compare` mounts
 * two instances (showUnavailable true vs false — a #[Locked] mount option, compared by mounting,
 * not toggling); `collection-paginate` mounts one paginate=1 instance for page navigation.
 */
Route::get('/__t/collection', fn () => view('dusk.collection'));

Route::get('/__t/collection-compare', fn () => view('dusk.collection-compare'));

Route::get('/__t/collection-paginate', fn () => view('dusk.collection-paginate'));

/*
 * Test-support route for the T10 DB-lifecycle PoC. Hitting it makes the *served*
 * (browser) process write a reservation row into the shared file SQLite, so the
 * Dusk test process can prove it reads back the very row the other process wrote
 * across the HTTP boundary (Gotcha #5). The full UI funnel that organically
 * creates a reservation is T14's job; this keeps the harness gate deterministic
 * and decoupled from the Alpine surface. It lives under `/__t/` like the other
 * harness-only routes and only ever loads inside the workbench app, never a real
 * install. The fresh random reference is echoed back so the assertion targets the
 * exact row.
 */
Route::get('/__t/write-reservation', function () {
    $reservation = Reservation::factory()->create([
        'reference' => (new Reservation)->createRandomReference(),
    ]);

    return '<!DOCTYPE html><html><body><span dusk="written-reservation">'
        .e($reservation->reference).'</span></body></html>';
});
