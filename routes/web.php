<?php

use App\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Availability
        Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
        Route::post('/resrv/api/availability/{statamic_id}', 'AvailabilityController@show')->name('availability.show');

        // Checkout
        Route::post('/resrv/api/reservation/{statamic_id}', 'ReservationController@confirm')->name('reservation.confirm');
    });

    
