<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

// Laravel 13's web group runs PreventRequestForgery, Laravel 12's runs a VerifyCsrfToken subclass, so exclude both.
// PreventRequestForgery doesn't exist on Laravel 12, where the router skips unknown classes.
Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        // Webhook
        Route::get('/resrv/api/webhook', 'WebhookController@index')->name('webhook.index')->withoutMiddleware([VerifyCsrfToken::class, PreventRequestForgery::class]);
        Route::post('/resrv/api/webhook', 'WebhookController@store')->name('webhook.store')->withoutMiddleware([VerifyCsrfToken::class, PreventRequestForgery::class]);

        // Per-gateway webhook routes (for multiple payment gateways)
        Route::get('/resrv/api/webhook/{gateway}', 'WebhookController@index')->name('webhook.gateway.index')->withoutMiddleware([VerifyCsrfToken::class, PreventRequestForgery::class]);
        Route::post('/resrv/api/webhook/{gateway}', 'WebhookController@store')->name('webhook.gateway.store')->withoutMiddleware([VerifyCsrfToken::class, PreventRequestForgery::class]);
    });
