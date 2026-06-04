<?php

use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;

/**
 * Developer configuration for Statamic Resrv.
 *
 * Only the keys below belong in this file. All user-facing settings (business
 * information, reservation rules, currency, checkout, emails) are managed in the
 * Control Panel under Resrv → Settings and stored in resources/addons/statamic-resrv.yaml.
 * Defining a CP-managed key in this file has no effect once it has been saved in the CP —
 * run `php please resrv:settings:migrate` to move legacy values into the CP settings.
 */
return [

    /**
     * Payment gateway.
     *
     * If you want, you can swap our payment gateway with your own integration.
     */
    'payment_gateway' => StripePaymentGateway::class,

    /**
     * Multiple payment gateways (optional).
     *
     * When set, customers can choose between payment methods during checkout.
     * Each gateway must implement PaymentInterface. The first gateway is the default.
     * When using multiple gateways, configure per-gateway webhook URLs in each
     * provider's dashboard (e.g., /resrv/api/webhook/stripe, /resrv/api/webhook/paypal).
     *
     * A built-in OfflinePaymentGateway is included for bank transfers or
     * pay-at-premises scenarios. It confirms reservations without an external
     * payment provider and sends the confirmation email immediately.
     *
     * 'payment_gateways' => [
     *     'stripe' => [
     *         'class' => \Reach\StatamicResrv\Http\Payment\StripePaymentGateway::class,
     *         'label' => 'Credit Card',
     *     ],
     *     // Optional surcharge per gateway (percent or fixed):
     *     // 'paypal' => [
     *     //     'class' => YourPaypalGateway::class,
     *     //     'label' => 'PayPal',
     *     //     'surcharge' => ['type' => 'percent', 'amount' => 4],
     *     // ],
     *     'offline' => [
     *         'class' => \Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway::class,
     *         'label' => 'Bank Transfer / Pay at Premises',
     *     ],
     * ],
     */
    'payment_gateways' => [],

    'stripe_secret_key' => env('RESRV_STRIPE_SECRET', ''),
    'stripe_publishable_key' => env('RESRV_STRIPE_PUBLISHABLE', ''),
    'stripe_webhook_secret' => env('RESRV_STRIPE_WEBHOOK_SECRET', ''),

    /**
     * Checkout form overrides (optional developer alternative).
     *
     * The CP manages the flat checkout_forms_default / checkout_forms_collections /
     * checkout_forms_entries settings. This nested structure takes precedence over
     * them when set, for setups that need the mapping under version control.
     */
    'checkout_forms' => [
        'default' => null,
        'collections' => [],
        'entries' => [],
    ],

    /**
     * Reservation email overrides (optional developer alternative).
     *
     * The CP manages the flat reservation_emails_global / reservation_emails_forms
     * settings. This nested structure takes precedence over them when set.
     *
     * Event keys:
     * - customer_confirmed
     * - admin_made
     * - customer_refunded
     * - customer_abandoned
     */
    'reservation_emails' => [
        'global' => [],
        'forms' => [],
    ],
];
