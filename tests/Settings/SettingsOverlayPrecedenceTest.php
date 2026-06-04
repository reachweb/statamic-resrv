<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Reach\StatamicResrv\Tests\TestCase;

class SettingsOverlayPrecedenceTest extends TestCase
{
    protected static ?string $settingsPath = null;

    /**
     * The CP settings YAML must exist before providers boot, since the overlay in
     * ResrvProvider::mergeAddonSettings() runs inside bootAddon().
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        static::$settingsPath = $app->resourcePath('addons/statamic-resrv.yaml');

        if (! is_dir(dirname(static::$settingsPath))) {
            mkdir(dirname(static::$settingsPath), 0755, true);
        }

        file_put_contents(static::$settingsPath, implode("\n", [
            'maximum_quantity: 4',
            'enable_cutoff_rules: true',
            'minutes_to_hold: 15',
            'currency_isoCode: USD',
            "logo: 'false'",
        ]));

        $app['config']->set('resrv-config.minimum_days_before', 5);
        $app['config']->set('resrv-config.minutes_to_hold', 99);
    }

    protected function tearDown(): void
    {
        if (static::$settingsPath && is_file(static::$settingsPath)) {
            unlink(static::$settingsPath);
        }

        parent::tearDown();
    }

    public function test_cp_settings_beat_blueprint_defaults_and_keep_native_types()
    {
        $this->assertSame(4, config('resrv-config.maximum_quantity'));
        $this->assertSame(true, config('resrv-config.enable_cutoff_rules'));
        $this->assertSame('USD', config('resrv-config.currency_isoCode'));
    }

    public function test_cp_settings_beat_values_already_in_config()
    {
        $this->assertSame(15, config('resrv-config.minutes_to_hold'));
    }

    public function test_config_values_beat_blueprint_defaults()
    {
        $this->assertSame(5, config('resrv-config.minimum_days_before'));
    }

    public function test_untouched_keys_fall_through_to_blueprint_defaults()
    {
        $this->assertSame('full', config('resrv-config.payment'));
        $this->assertSame('Resrv', config('resrv-config.name'));
    }

    public function test_legacy_no_logo_sentinel_is_normalized_to_null()
    {
        $this->assertNull(config('resrv-config.logo'));
    }
}
