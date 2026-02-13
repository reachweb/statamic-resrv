<?php

return [

    /**
     * General information.
     *
     * Put your business information here. Those information will be used for the emails.
     */
    'name' => 'Resrv',
    'address1' => 'Somestreet 8',
    'zip_city' => '00000 City',
    'country' => 'Greece',
    'phone' => '+30 0000 000000',
    'mail' => 'resrv@resrv.app',
    'logo' => false,

    /**
     * Reservation settings.
     * enable_time: the reservation will have an explicit pickup and drop-off time
     * minimum_days_before: set this to the number of days allowed between booking date and pickup time (calendar days count not 24 hour difference)
     * minimum_reservation_period_in_days: the minimum days for a reservation
     * maximum_reservation_period_in_day: the maximum days for a reservation
     * maximum_quantity: the maximum items a user can book in one reservation
     * ignore_quantity_for_prices: use quantity for availability calculations but ignore it for pricing
     * free_cancellation_period: the number of days a user can cancel a reservation without being charged
     * full_payment_after_free_cancellation: If the reservation creation after is after free cancellation has passed, require the full amount
     * calculate_days_using_time: if true every reservation will charge a day for drop off time after pick up
     * decrease_availabilty_for_extra_time: if true, the extra day charged for usage over 24hr will behave as a normal reservation
     * admin_email: list of emails to be notified after a reservation has been made.
     */
    'enable_time' => false,
    'minimum_days_before' => 0,
    'minimum_reservation_period_in_days' => 1,
    'maximum_reservation_period_in_days' => 30,
    'maximum_quantity' => 8,
    'ignore_quantity_for_prices' => false,
    'free_cancellation_period' => 0,
    'full_payment_after_free_cancellation' => false,
    'calculate_days_using_time' => false,
    'decrease_availability_for_extra_time' => false,
    'admin_email' => false,
    'checkout_entry' => null,
    'checkout_completed_entry' => null,

    /**
     * Currency.
     *
     * Define your currency
     */
    'currency_name' => 'Euro',
    'currency_isoCode' => 'EUR', // Make sure to use ISO_4217 https://en.wikipedia.org/wiki/ISO_4217
    'currency_symbol' => '€',
    'currency_delimiter' => ',',

    /**
     * Checkout settings.
     * form_name: DEPRECATED. Kept for backwards compatibility and used only if checkout_forms_default is not set.
     * checkout_forms_default: default checkout form handle used when no entry/collection mapping matches
     * checkout_forms_collections: list of collection-specific checkout forms (rows: collection, form)
     * checkout_forms_entries: list of entry-specific checkout forms (rows: entry, form)
     * payment: full charges the whole amount, everything the amount plus extras and options and fixed charges a fixed deposit and percent charges a percentage
     * fixed_amount: the amount to charge for a reservation
     * percent_amount: the percentage of the reservation to charge as an amount
     * minutes_to_hold: how much time the user has the complete the checkout until availability is reset.
     */
    'form_name' => 'checkout',
    'checkout_forms_default' => 'checkout',
    'checkout_forms_collections' => [],
    'checkout_forms_entries' => [],
    // Optional nested alternative to the flat checkout_forms_* keys above.
    'checkout_forms' => [
        'default' => null,
        'collections' => [],
        'entries' => [],
    ],
    'payment' => 'full',
    'fixed_amount' => 50,
    'percent_amount' => 20,
    'minutes_to_hold' => 10,

    /**
     * Payment methods.
     *
     * If you want, you can swap our payment gateway with your own integration.
     */
    'payment_gateway' => Reach\StatamicResrv\Http\Payment\StripePaymentGateway::class,
    'stripe_secret_key' => env('RESRV_STRIPE_SECRET', ''),
    'stripe_publishable_key' => env('RESRV_STRIPE_PUBLISHABLE', ''),
    'stripe_webhook_secret' => env('RESRV_STRIPE_WEBHOOK_SECRET', ''),

    /**
     * Advanced features
     * enable_advanced_availability: set different availability and price for an item depending on the property.
     * enable_connected_availabilities: enable the ability to "connect" advanced availabilities.
     * enable_affiliates: enable the ability to have affiliates that can book on behalf of a customer and / or get commision based on the reservations they make.
     * enable_cutoff_rules: enable the ability to set cutoff times for bookings based on starting times and schedules.
     */
    'enable_advanced_availability' => false,
    'enable_connected_availabilities' => false,
    'enable_affiliates' => true,
    'enable_cutoff_rules' => false,

    /**
     * Abandoned reservation emails.
     * enable_abandoned_emails: send recovery emails for expired reservations with customer data.
     * abandoned_email_delay_days: days after expiration before sending (1 = next day).
     */
    'enable_abandoned_emails' => false,
    'abandoned_email_delay_days' => 1,

    /**
     * Reservation email overrides.
     *
     * reservation_emails_global: optional event-level defaults across all forms.
     * reservation_emails_forms: optional per-form event overrides.
     *
     * Event keys:
     * - customer_confirmed
     * - admin_made
     * - customer_refunded
     * - customer_abandoned
     */
    'reservation_emails_global' => [],
    'reservation_emails_forms' => [],
    // Optional nested alternative to the flat reservation_emails_* keys above.
    'reservation_emails' => [
        'global' => [],
        'forms' => [],
    ],
];
