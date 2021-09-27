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
        Route::patch('/resrv/extra/order', 'ExtraCpController@order')->name('extra.order');
        Route::delete('/resrv/extra', 'ExtraCpController@delete')->name('extra.delete');

        Route::get('/resrv/option/{statamic_id}', 'OptionCpController@entryIndex')->name('option.entryindex');
        Route::post('/resrv/option', 'OptionCpController@create')->name('option.create');
        Route::patch('/resrv/option', 'OptionCpController@update')->name('option.update');
        Route::delete('/resrv/option', 'OptionCpController@delete')->name('option.delete');
        Route::patch('/resrv/option/order', 'OptionCpController@order')->name('option.order');
        Route::post('/resrv/option/{id}', 'OptionCpController@createValue')->name('option.value.create');
        Route::patch('/resrv/option/{id}', 'OptionCpController@updateValue')->name('option.value.update');
        Route::patch('/resrv/option/value/order', 'OptionCpController@orderValue')->name('option.value.order');
        Route::delete('/resrv/option/value', 'OptionCpController@deleteValue')->name('option.value.delete');

        Route::get('/resrv/locations', 'LocationCpController@indexCp')->name('locations.index');
        Route::get('/resrv/location', 'LocationCpController@index')->name('location.index');
        Route::post('/resrv/location', 'LocationCpController@create')->name('location.create');
        Route::patch('/resrv/location', 'LocationCpController@update')->name('location.update');
        Route::patch('/resrv/location/order', 'LocationCpController@order')->name('location.order');
        Route::delete('/resrv/location', 'LocationCpController@delete')->name('location.delete');

        Route::get('/resrv/reservation', 'ReservationCpController@index')->name('reservation.index');
        Route::get('/resrv/reservations', 'ReservationCpController@indexCp')->name('reservations.index');
        Route::get('/resrv/reservations/calendar/list', 'ReservationCpController@calendar')->name('reservations.calendar.list');
        Route::get('/resrv/reservations/calendar', 'ReservationCpController@calendarCp')->name('reservations.calendar');
        Route::get('/resrv/reservation/{id}', 'ReservationCpController@show')->name('reservation.show');
        Route::patch('/resrv/reservation/refund', 'ReservationCpController@refund')->name('reservation.refund');

        Route::get('/resrv/fixedpricing/{statamic_id}', 'FixedPricingCpController@index')->name('fixedpricing.index');
        Route::post('/resrv/fixedpricing', 'FixedPricingCpController@update')->name('fixedpricing.update');        
        Route::delete('/resrv/fixedpricing', 'FixedPricingCpController@delete')->name('fixedpricing.delete');

        Route::get('/resrv/dynamicpricing', 'DynamicPricingCpController@indexCp')->name('dynamicpricings.index');
        Route::get('/resrv/dynamicpricing/index', 'DynamicPricingCpController@index')->name('dynamicpricing.index');
        Route::post('/resrv/dynamicpricing', 'DynamicPricingCpController@create')->name('dynamicpricing.create');
        Route::patch('/resrv/dynamicpricing/order', 'DynamicPricingCpController@order')->name('dynamicpricing.order');
        Route::patch('/resrv/dynamicpricing/{id}', 'DynamicPricingCpController@update')->name('dynamicpricing.update');        
        Route::delete('/resrv/dynamicpricing', 'DynamicPricingCpController@delete')->name('dynamicpricing.delete');

        Route::get('/resrv/utility/entries', 'ResrvUtilityController@entries')->name('utilities.entries');

    });
