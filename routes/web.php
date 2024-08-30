<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        if (config('resrv-config.enable_legacy_endpoints', false)) {
            // Availability
            Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
            Route::post('/resrv/api/availability/{statamic_id}', 'AvailabilityController@show')->name('availability.show');

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
            Route::get('/resrv/api/session/get-search', 'ResrvUtilityController@getSavedSearch')->name('utility.getSavedSearch');
            Route::post('/resrv/api/session/coupon', 'ResrvUtilityController@addCoupon')->name('utility.addCoupon');
            Route::delete('/resrv/api/session/coupon', 'ResrvUtilityController@removeCoupon')->name('utility.removeCoupon');
            Route::get('/resrv/api/session/coupon', 'ResrvUtilityController@getCoupon')->name('utility.getCoupon');
            Route::get('/resrv/api/token', 'ResrvUtilityController@token')->name('utility.token');
        }

        // Payments
        Route::post('/resrv/checkout/completed', 'ReservationController@checkoutCompleted')->name('reservation.checkoutCompleted')->withoutMiddleware([VerifyCsrfToken::class]);
        Route::post('/resrv/checkout/failed', 'ReservationController@checkoutFailed')->name('reservation.checkoutFailed')->withoutMiddleware([VerifyCsrfToken::class]);

        // Webhook
        Route::get('/resrv/api/webhook', 'WebhookController@index')->name('webhook.index')->withoutMiddleware([VerifyCsrfToken::class]);
        Route::post('/resrv/api/webhook', 'WebhookController@store')->name('webhook.store')->withoutMiddleware([VerifyCsrfToken::class]);
    });
