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
];
