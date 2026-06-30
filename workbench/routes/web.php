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

Route::get('/__t/checkout', fn () => view('dusk.checkout'));

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
