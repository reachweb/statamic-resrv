<?php

use App\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Availability
        Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
        Route::post('/resrv/api/availability/{statamic_id}', 'AvailabilityController@show')->name('availability.show');

        // Options
        Route::post('/resrv/api/option', 'OptionController@index')->name('option.index');

        // Extras
        Route::post('/resrv/api/extra', 'ExtraController@index')->name('extra.index');

        // Checkout
        Route::post('/resrv/api/reservation/{statamic_id}', 'ReservationController@confirm')->name('reservation.confirm');
        Route::get('/resrv/api/reservation/checkout', 'ReservationController@checkoutForm')->name('reservation.checkoutForm');
        Route::post('/resrv/api/reservation/checkout/{reservation_id}', 'ReservationController@checkoutFormSubmit')->name('reservation.checkoutFormSubmit');
        Route::post('/resrv/api/reservation/checkout/{reservation_id}/confirm', 'ReservationController@checkoutConfirm')->name('reservation.checkoutConfirm');
    });

    
