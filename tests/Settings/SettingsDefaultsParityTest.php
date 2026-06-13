<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Tests\TestCase;

class SettingsDefaultsParityTest extends TestCase
{
    /**
     * The effective defaults the addon shipped before the blueprint became the single
     * source of CP-managed defaults (the old config/config.php values). This pins the
     * blueprint `default:` lines against accidental drift — value AND type.
     */
    public const LEGACY_DEFAULTS = [
        'name' => 'Resrv',
        'address1' => 'Somestreet 8',
        'zip_city' => '00000 City',
        'country' => 'Greece',
        'phone' => '+30 0000 000000',
        'mail' => 'resrv@resrv.app',
        'enable_time' => false,
        'enable_affiliates' => true,
        'enable_cutoff_rules' => false,
        'minimum_days_before' => 0,
        'minimum_reservation_period_in_days' => 1,
        'maximum_reservation_period_in_days' => 30,
        'maximum_quantity' => 8,
        'ignore_quantity_for_prices' => false,
        'free_cancellation_period' => 0,
        'full_payment_after_free_cancellation' => false,
        'calculate_days_using_time' => false,
        'decrease_availability_for_extra_time' => false,
        'payment' => 'full',
        'fixed_amount' => 50,
        'percent_amount' => 20,
        'minutes_to_hold' => 10,
        'enable_abandoned_emails' => false,
        'abandoned_email_delay_days' => 1,
        'currency_name' => 'Euro',
        'currency_isoCode' => 'EUR',
        'currency_symbol' => '€',
        'currency_delimiter' => ',',
    ];

    public const NO_DEFAULT_KEYS = [
        'logo',
        'admin_email',
        'checkout_entry',
        'checkout_completed_entry',
        'checkout_forms_default',
        'checkout_forms_collections',
        'checkout_forms_entries',
        'reservation_emails_global',
        'reservation_emails_forms',
    ];

    public function test_blueprint_defaults_match_the_legacy_config_defaults_with_types()
    {
        foreach (self::LEGACY_DEFAULTS as $key => $expected) {
            $this->assertSame(
                $expected,
                config("resrv-config.{$key}"),
                "Effective default for [{$key}] drifted from the legacy config value."
            );
        }
    }

    public function test_keys_without_a_blueprint_default_resolve_to_null()
    {
        foreach (self::NO_DEFAULT_KEYS as $key) {
            $this->assertNull(
                config("resrv-config.{$key}"),
                "Expected [{$key}] to have no default."
            );
        }
    }

    public function test_grid_sub_field_defaults_do_not_leak_to_top_level_config()
    {
        $this->assertNull(config('resrv-config.enabled'));
        $this->assertNull(config('resrv-config.event'));
        $this->assertNull(config('resrv-config.recipient_sources'));
    }

    public function test_developer_keys_come_from_the_package_config()
    {
        $this->assertSame(StripePaymentGateway::class, config('resrv-config.payment_gateway'));
        $this->assertSame([], config('resrv-config.payment_gateways'));
        $this->assertSame('', config('resrv-config.stripe_secret_key'));
        $this->assertSame('', config('resrv-config.stripe_publishable_key'));
        $this->assertSame('', config('resrv-config.stripe_webhook_secret'));
    }

    public function test_retired_nested_checkout_and_email_keys_are_no_longer_shipped()
    {
        $this->assertNull(config('resrv-config.checkout_forms'));
        $this->assertNull(config('resrv-config.reservation_emails'));
    }
}
