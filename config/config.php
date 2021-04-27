<?php

return [

    /**
     *
     * GENERAL INFORMATION
     *
     * Put your shop information here. Those information will be usedby templates for the shop and emails.
     */
    'name'     => 'Reserv', // Whats the name of your Shop?
    'address1' => 'Somestreet 8',
    'zip_city' => '00000 City',
    'country'  => 'Greece', // Set your countries iso code
    'phone'    => '+30 0000 000000',
    'mail'     => 'resrv@resrv.app',

    /**
     * Reservation settings.
     * minimum_reservation_period_in_days: the minimum days for a reservation
     * calculate_days_using_time: if true every reservation will add a day for drop off time after pick up
     * 
     */

    'minimum_reservation_period_in_days' => 1,
    'calculate_days_using_time' => true,    

    /**
     * CURRENCY.
     *
     * Define your currency
     */
    'currency_name'      => 'Euro',
    'currency_isoCode'   => 'EUR', // Make sure to use ISO_4217 https://en.wikipedia.org/wiki/ISO_4217
    'currency_symbol'    => '€',
    'currency_delimiter' => ',',

    /**
     * PAYMENT.
     *
     * If you want, you can swap our payment gateway with your own integration.
     * 
     */
    'payment_gateway' => Reach\StatamicResrv\Http\Controllers\PaymentGateways\Stripe::class,
];