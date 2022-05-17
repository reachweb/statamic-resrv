<?php

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Checkout - payment routes
        Route::post('/resrv/checkout/completed', 'ReservationController@checkoutCompleted')->name('reservation.checkoutCompleted');
        Route::post('/resrv/checkout/failed', 'ReservationController@checkoutFailed')->name('reservation.checkoutFailed');
    });
