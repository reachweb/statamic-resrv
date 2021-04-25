<?php

use App\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        Route::post('/resrv/api/availability', 'AvailabilityController@index')->name('availability.index');
    });

    
