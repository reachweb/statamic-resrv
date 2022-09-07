<?php

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Availability
        Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
        Route::post('/resrv/api/availability/{statamic_id}', 'AvailabilityController@show')->name('availability.show');

        // Advanced availability
        Route::post('/resrv/api/advancedavailability', 'AdvancedAvailabilityController@index')->name('advancedavailability.index');
        Route::post('/resrv/api/advancedavailability/{statamic_id}', 'AdvancedAvailabilityController@show')->name('advancedavailability.show');

        // Options
        Route::post('/resrv/api/option', 'OptionController@index')->name('option.index');

        // Extras
        Route::post('/resrv/api/extra', 'ExtraController@index')->name('extra.index');

        // Checkout - Ajax routes
        Route::post('/resrv/api/reservation/{statamic_id}', 'ReservationController@confirm')->name('reservation.confirm');
        Route::patch('/resrv/api/reservation/{reservation}', 'ReservationController@update')->name('reservation.update');
        Route::get('/resrv/api/reservation/checkout/{entry_id?}', 'ReservationController@checkoutForm')->name('reservation.checkoutForm');
        Route::post('/resrv/api/reservation/checkout/{reservation_id}', 'ReservationController@checkoutFormSubmit')->name('reservation.checkoutFormSubmit');
        Route::post('/resrv/api/reservation/checkout/{reservation_id}/confirm', 'ReservationController@checkoutConfirm')->name('reservation.checkoutConfirm');

        // Checkout - non Ajax routes
        Route::post('/resrv/checkout', 'ReservationController@start')->name('reservation.start');

        // Utility
        Route::post('/resrv/api/session/refresh-search', 'ResrvUtilityController@refreshSearchSession')->name('utility.refreshSearchSession');
        Route::post('/resrv/api/session/coupon', 'ResrvUtilityController@addCoupon')->name('utility.addCoupon');
        Route::get('/resrv/api/token', 'ResrvUtilityController@token')->name('utility.token');
    });
