<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Reach\StatamicResrv\Support\SettingsBlueprint;
use Reach\StatamicResrv\Support\SettingsMigrator;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Addon;

class SettingsMigratorTest extends TestCase
{
    protected SettingsMigrator $migrator;

    protected array $blueprintFields;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new SettingsMigrator;
        $this->blueprintFields = SettingsBlueprint::fields(
            Addon::get('reachweb/statamic-resrv')->settingsBlueprint()->contents()
        );
    }

    protected function tearDown(): void
    {
        if (is_file($path = resource_path('addons/statamic-resrv.yaml'))) {
            unlink($path);
        }

        parent::tearDown();
    }

    protected function settings()
    {
        return Addon::get('reachweb/statamic-resrv')->settings();
    }

    public function test_values_equal_to_blueprint_defaults_are_not_seeded_but_remain_deletable()
    {
        $result = $this->migrator->migrate(
            ['maximum_quantity' => 8, 'payment' => 'full'],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->seeded);
        $this->assertEqualsCanonicalizing(['maximum_quantity', 'payment'], $result->deletable);
        $this->assertFalse($result->hasChanges());
    }

    public function test_customized_values_are_seeded_with_native_types()
    {
        $result = $this->migrator->migrate(
            ['currency_isoCode' => 'USD', 'maximum_quantity' => 4, 'enable_affiliates' => false],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame(
            ['currency_isoCode' => 'USD', 'maximum_quantity' => 4, 'enable_affiliates' => false],
            $result->seeded
        );
    }

    public function test_null_and_sentinel_false_values_are_never_seeded()
    {
        $result = $this->migrator->migrate(
            ['logo' => false, 'admin_email' => false, 'checkout_entry' => null],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->seeded);

        $result = $this->migrator->migrate(
            ['logo' => 'false'],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->seeded);
    }

    public function test_existing_cp_values_win_and_differences_surface_as_conflicts()
    {
        $settings = $this->settings();
        $settings->set(['currency_isoCode' => 'EUR']);

        $result = $this->migrator->migrate(
            ['currency_isoCode' => 'USD'],
            $settings,
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->seeded);
        $this->assertSame(['currency_isoCode' => ['file' => 'USD', 'cp' => 'EUR']], $result->conflicts);
    }

    public function test_stale_keys_are_reported_separately_from_developer_keys()
    {
        $result = $this->migrator->migrate(
            ['form_name' => 'checkout', 'enable_advanced_availability' => true, 'payment_gateways' => []],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertEqualsCanonicalizing(['form_name', 'enable_advanced_availability'], $result->stale);
        $this->assertSame([], $result->deletable);
    }

    public function test_logo_values_that_both_mean_no_logo_are_not_reported_as_conflicts()
    {
        $settings = $this->settings();
        $settings->set(['logo' => 'false']);

        $result = $this->migrator->migrate(
            ['logo' => false],
            $settings,
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->conflicts);

        $result = $this->migrator->migrate(
            ['logo' => false],
            $settings->set(['logo' => 'https://example.com/logo.png']),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame(['logo' => ['file' => false, 'cp' => 'https://example.com/logo.png']], $result->conflicts);
    }

    public function test_file_logo_url_replaces_a_legacy_no_logo_sentinel_in_cp_settings()
    {
        $settings = $this->settings();
        $settings->set(['logo' => 'false']);

        $result = $this->migrator->migrate(
            ['logo' => 'https://example.com/logo.png'],
            $settings,
            $this->blueprintFields
        );

        $this->assertSame(['logo' => 'https://example.com/logo.png'], $result->seeded);
        $this->assertSame([], $result->conflicts);
        $this->assertSame(['logo'], $result->normalized);
        $this->assertSame('https://example.com/logo.png', $this->settings()->raw()['logo']);
    }

    public function test_legacy_false_logo_is_removed_from_existing_cp_settings()
    {
        $settings = $this->settings();
        $settings->set(['logo' => 'false', 'maximum_quantity' => 4]);

        $result = $this->migrator->migrate([], $settings, $this->blueprintFields);

        $this->assertSame(['logo'], $result->normalized);
        $this->assertTrue($result->hasChanges());

        $raw = $this->settings()->raw();
        $this->assertArrayNotHasKey('logo', $raw);
        $this->assertSame(4, $raw['maximum_quantity']);
    }

    public function test_migration_persists_seeded_values_and_is_idempotent()
    {
        $result = $this->migrator->migrate(
            ['currency_isoCode' => 'USD'],
            $this->settings(),
            $this->blueprintFields
        );

        $this->assertTrue($result->hasChanges());
        $this->assertSame('USD', $this->settings()->raw()['currency_isoCode']);

        $second = $this->migrator->migrate(
            ['currency_isoCode' => 'USD'],
            $this->settings(),
            $this->blueprintFields
        );

        $this->assertFalse($second->hasChanges());
        $this->assertSame([], $second->seeded);
        $this->assertSame([], $second->conflicts);
    }

    public function test_dry_run_never_writes_the_settings_store()
    {
        $this->migrator->migrate(
            ['currency_isoCode' => 'USD'],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertFileDoesNotExist(resource_path('addons/statamic-resrv.yaml'));
    }

    public function test_nested_checkout_forms_config_is_migrated_into_flat_cp_keys()
    {
        $result = $this->migrator->migrate(
            [
                'checkout_forms' => [
                    'default' => 'checkout',
                    'collections' => [['collection' => 'pages', 'form' => 'pages-form']],
                    'entries' => ['some-entry-id' => 'entry-form'],
                ],
            ],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame('checkout', $result->seeded['checkout_forms_default']);
        $this->assertSame(
            [['collection' => 'pages', 'form' => 'pages-form']],
            $result->seeded['checkout_forms_collections']
        );
        $this->assertSame(
            [['entry' => 'some-entry-id', 'form' => 'entry-form']],
            $result->seeded['checkout_forms_entries']
        );
        $this->assertContains('checkout_forms', $result->deletable);
        $this->assertSame([], $result->stale);
    }

    public function test_nested_reservation_emails_config_is_migrated_into_flat_cp_keys()
    {
        $result = $this->migrator->migrate(
            [
                'reservation_emails' => [
                    'global' => [
                        'customer_confirmed' => ['subject' => 'Hi', 'recipients' => 'customer,admins'],
                    ],
                    'forms' => [
                        'checkout' => [
                            'customer_confirmed' => ['subject' => 'Form subject'],
                        ],
                    ],
                ],
            ],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame(
            [['event' => 'customer_confirmed', 'subject' => 'Hi', 'recipients' => 'customer,admins']],
            $result->seeded['reservation_emails_global']
        );
        $this->assertSame(
            [['form' => 'checkout', 'event' => 'customer_confirmed', 'subject' => 'Form subject']],
            $result->seeded['reservation_emails_forms']
        );
        $this->assertContains('reservation_emails', $result->deletable);
        $this->assertSame([], $result->stale);
    }

    public function test_empty_nested_stub_is_not_seeded_but_remains_deletable()
    {
        $result = $this->migrator->migrate(
            [
                'checkout_forms' => ['default' => null, 'collections' => [], 'entries' => []],
                'reservation_emails' => ['global' => [], 'forms' => []],
            ],
            $this->settings(),
            $this->blueprintFields,
            dryRun: true
        );

        $this->assertSame([], $result->seeded);
        $this->assertFalse($result->hasChanges());
        $this->assertEqualsCanonicalizing(['checkout_forms', 'reservation_emails'], $result->deletable);
        $this->assertSame([], $result->stale);
    }

    public function test_nested_config_migration_persists_and_is_idempotent()
    {
        $config = ['checkout_forms' => ['default' => 'checkout']];

        $result = $this->migrator->migrate($config, $this->settings(), $this->blueprintFields);

        $this->assertTrue($result->hasChanges());
        $this->assertSame('checkout', $this->settings()->raw()['checkout_forms_default']);

        $second = $this->migrator->migrate($config, $this->settings(), $this->blueprintFields);

        $this->assertFalse($second->hasChanges());
        $this->assertSame([], $second->seeded);
    }
}
