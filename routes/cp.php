<?php

use App\Http\Middleware\VerifyCsrfToken;

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        Route::get('/resrv/availability/{statamic_id}', 'AvailabilityCpController@index')->name('availability.index');
        Route::post('/resrv/availability', 'AvailabilityCpController@update')->name('availability.update');

        Route::get('/resrv/extras', 'ExtraCpController@indexCp')->name('extras.index');
        Route::get('/resrv/extra', 'ExtraCpController@index')->name('extra.index');
        Route::get('/resrv/extra/{statamic_id}', 'ExtraCpController@entryIndex')->name('extra.entryindex');
        Route::post('/resrv/extra', 'ExtraCpController@create')->name('extra.create');
        Route::post('/resrv/extra/add/{statamic_id}', 'ExtraCpController@associate')->name('extra.add');
        Route::post('/resrv/extra/remove/{statamic_id}', 'ExtraCpController@disassociate')->name('extra.remove');
        Route::patch('/resrv/extra', 'ExtraCpController@update')->name('extra.update');
        Route::delete('/resrv/extra', 'ExtraCpController@delete')->name('extra.delete');
    });
