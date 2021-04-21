<?php

use App\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        Route::get('/resrv/availability/{statamic_id}', 'AvailabilityCpController@index')->name('availability.index');
        Route::post('/resrv/availability', 'AvailabilityCpController@update')->name('availability.update');
    });
