<?php

return [

    /**
     *
     * General information
     *
     * Put your business information here. Those information will be used for the emails.
     */
    'name'     => 'Reserv', 
    'address1' => 'Somestreet 8',
    'zip_city' => '00000 City',
    'country'  => 'Greece', 
    'phone'    => '+30 0000 000000',
    'mail'     => 'resrv@resrv.app',

    /**
     * Reservation settings.
     * minimum_reservation_period_in_days: the minimum days for a reservation
     * calculate_days_using_time: if true every reservation will add a day for drop off time after pick up
     * 
     */

    'minimum_reservation_period_in_days' => 1,
    'maximum_reservation_period_in_days' => 30,
    'calculate_days_using_time' => false, 

    /**
     * Currency
     *
     * Define your currency
     */
    'currency_name'      => 'Euro',
    'currency_isoCode'   => 'EUR', // Make sure to use ISO_4217 https://en.wikipedia.org/wiki/ISO_4217
    'currency_symbol'    => '€',
    'currency_delimiter' => ',',

    /**
     * Reservation settings.
     * payment: full charges the whole amount, fixed charges a fixed deposit and percent charges a percentage
     * fixed_amount: the amout to charge for a reservation
     * percent_amount: the percentage of the reservation to charge as an amount
     * include_extras: if the extras value will be added to the calculation of the percent deposit
     * 
     */

    'payment' => 'full',
    'fixed_amount' => 50,
    'percent_amount' => 20,
    'include_extras' => false, 

    /**
     * Payment methods
     *
     * If you want, you can swap our payment gateway with your own integration.
     * 
     */
    'payment_gateway' => Reach\StatamicResrv\Http\Controllers\PaymentGateways\Stripe::class,
];