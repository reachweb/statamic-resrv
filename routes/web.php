<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Payments
        Route::post('/resrv/checkout/completed', 'ReservationController@checkoutCompleted')->name('reservation.checkoutCompleted')->withoutMiddleware([VerifyCsrfToken::class]);
        Route::post('/resrv/checkout/failed', 'ReservationController@checkoutFailed')->name('reservation.checkoutFailed')->withoutMiddleware([VerifyCsrfToken::class]);

        // Webhook
        Route::get('/resrv/api/webhook', 'WebhookController@index')->name('webhook.index')->withoutMiddleware([VerifyCsrfToken::class]);
        Route::post('/resrv/api/webhook', 'WebhookController@store')->name('webhook.store')->withoutMiddleware([VerifyCsrfToken::class]);
    });
