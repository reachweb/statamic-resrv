<?php

use Illuminate\Support\Facades\Route;
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

Route::get('/__t/checkout', fn () => view('dusk.checkout'));
