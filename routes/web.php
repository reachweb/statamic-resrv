<?php

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Availability
        Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
        Route::post('/resrv/api/availability/multi', 'AvailabilityController@multiIndex')->name('availability.multiIndex');
        Route::post('/resrv/api/availability/{statamic_id}', 'AvailabilityController@show')->name('availability.show');
        Route::post('/resrv/api/availability/multi/{statamic_id}', 'AvailabilityController@multiShow')->name('availability.multiShow');

        // Advanced availability
        Route::post('/resrv/api/advancedavailability', 'AdvancedAvailabilityController@index')->name('advancedavailability.index');
        Route::post('/resrv/api/advancedavailability/multi', 'AdvancedAvailabilityController@multiIndex')->name('advancedavailability.multiIndex');
        Route::post('/resrv/api/advancedavailability/{statamic_id}', 'AdvancedAvailabilityController@show')->name('advancedavailability.show');
        Route::post('/resrv/api/advancedavailability/multi/{statamic_id}', 'AdvancedAvailabilityController@multiShow')->name('advancedavailability.multiShow');

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
