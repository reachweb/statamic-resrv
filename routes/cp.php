<?php

Route::namespace('\Reach\StatamicResrv\Http\Controllers')
    ->name('resrv.')
    ->group(function () {
        Route::get('/resrv/availability/{statamic_id}/{property?}', 'AvailabilityCpController@index')->name('availability.index');
        Route::post('/resrv/availability', 'AvailabilityCpController@update')->name('availability.update');
        Route::delete('/resrv/availability', 'AvailabilityCpController@delete')->name('availability.delete');

        Route::get('/resrv/extras', 'ExtraCpController@indexCp')->name('extras.index');
        Route::get('/resrv/extra', 'ExtraCpController@index')->name('extra.index');
        Route::get('/resrv/extra/{statamic_id}', 'ExtraCpController@entryIndex')->name('extra.entryindex');
        Route::post('/resrv/extra', 'ExtraCpController@create')->name('extra.create');
        Route::post('/resrv/extra/add/{statamic_id}', 'ExtraCpController@associate')->name('extra.add');
        Route::post('/resrv/extra/remove/{statamic_id}', 'ExtraCpController@disassociate')->name('extra.remove');
        Route::get('/resrv/extra/entries/{extra}', 'ExtraCpController@entries')->name('extra.entries');
        Route::post('/resrv/extra/massadd/{extra}', 'ExtraCpController@massAssociate')->name('extra.massadd');
        Route::patch('/resrv/extra', 'ExtraCpController@update')->name('extra.update');
        Route::patch('/resrv/extra/order/{extra}', 'ExtraCpController@order')->name('extra.order');
        Route::patch('/resrv/extra/move/{extra}', 'ExtraCpController@move')->name('extra.move');
        Route::delete('/resrv/extra', 'ExtraCpController@delete')->name('extra.delete');
        Route::post('/resrv/extra/conditions/{extra_id}', 'ExtraCpController@conditions')->name('extra.conditions');

        Route::get('/resrv/extra-category', 'ExtraCpCategoryController@index')->name('extraCategory.index');
        Route::get('/resrv/extra-category/{statamic_id}', 'ExtraCpCategoryController@entryindex')->name('extraCategory.entryindex');
        Route::post('/resrv/extra-category', 'ExtraCpCategoryController@store')->name('extraCategory.create');
        Route::patch('/resrv/extra-category/order', 'ExtraCpCategoryController@order')->name('extraCategory.order');
        Route::patch('/resrv/extra-category/{category}', 'ExtraCpCategoryController@update')->name('extraCategory.update');
        Route::delete('/resrv/extra-category/{category}', 'ExtraCpCategoryController@delete')->name('extraCategory.delete');

        Route::get('/resrv/option/{statamic_id}', 'OptionCpController@entryIndex')->name('option.entryindex');
        Route::post('/resrv/option', 'OptionCpController@create')->name('option.create');
        Route::patch('/resrv/option', 'OptionCpController@update')->name('option.update');
        Route::delete('/resrv/option', 'OptionCpController@delete')->name('option.delete');
        Route::patch('/resrv/option/order', 'OptionCpController@order')->name('option.order');
        Route::post('/resrv/option/{id}', 'OptionCpController@createValue')->name('option.value.create');
        Route::patch('/resrv/option/{id}', 'OptionCpController@updateValue')->name('option.value.update');
        Route::patch('/resrv/option/value/order', 'OptionCpController@orderValue')->name('option.value.order');
        Route::delete('/resrv/option/value', 'OptionCpController@deleteValue')->name('option.value.delete');

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

        Route::get('/resrv/reports', 'ReportsCpController@indexCp')->name('reports.index');
        Route::get('/resrv/reports/index', 'ReportsCpController@index')->name('report.index');

        Route::get('/resrv/dataimport', 'DataImportCpController@index')->name('dataimport.index');
        Route::post('/resrv/dataimport', 'DataImportCpController@confirm')->name('dataimport.confirm');
        Route::get('/resrv/dataimport/store', 'DataImportCpController@store')->name('dataimport.store');

        Route::get('/resrv/utility/entries', 'UtilityCpController@entries')->name('utilities.entries');

        Route::get('/resrv/affiliates', 'AffiliateCpController@indexCp')->name('affiliates.index');
        Route::get('/resrv/affiliate', 'AffiliateCpController@index')->name('affiliate.index');
        Route::post('/resrv/affiliate', 'AffiliateCpController@create')->name('affiliate.create');
        Route::patch('/resrv/affiliate/{affiliate}', 'AffiliateCpController@update')->name('affiliate.update');
        Route::delete('/resrv/affiliate/{affiliate}', 'AffiliateCpController@delete')->name('affiliate.delete');
    });
