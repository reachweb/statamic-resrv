<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Reach\StatamicResrv\Support\SettingsBlueprint;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Addon;

class SettingsGuardrailTest extends TestCase
{
    protected static ?string $configPath = null;

    /**
     * The published config file must exist before providers boot, since
     * ResrvProvider::injectPublishedConfigWarning() runs inside bootAddon().
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        static::$configPath = $app->configPath('resrv-config.php');

        $contents = str_contains($this->name(), 'developer_only')
            ? "<?php\n\nreturn ['payment_gateways' => []];"
            : "<?php\n\nreturn ['maximum_quantity' => 8, 'currency_isoCode' => 'USD'];";

        file_put_contents(static::$configPath, $contents);
    }

    protected function tearDown(): void
    {
        if (static::$configPath && is_file(static::$configPath)) {
            unlink(static::$configPath);
        }

        parent::tearDown();
    }

    public function test_warns_when_published_config_defines_cp_managed_keys()
    {
        $contents = Addon::get('reachweb/statamic-resrv')->settingsBlueprint()->contents();

        $firstSection = collect($contents['tabs'])->first()['sections'][0];

        $this->assertSame('⚠ Published config file detected', $firstSection['display']);
        $this->assertStringContainsString('maximum_quantity', $firstSection['instructions']);
        $this->assertStringContainsString('currency_isoCode', $firstSection['instructions']);
        $this->assertStringContainsString('resrv:settings:migrate', $firstSection['instructions']);
    }

    public function test_warning_section_does_not_add_field_handles()
    {
        $contents = Addon::get('reachweb/statamic-resrv')->settingsBlueprint()->contents();

        $fields = SettingsBlueprint::fields($contents);

        $this->assertArrayHasKey('maximum_quantity', $fields);
        $this->assertArrayHasKey('currency_isoCode', $fields);
    }

    public function test_no_warning_for_developer_only_config()
    {
        $contents = Addon::get('reachweb/statamic-resrv')->settingsBlueprint()->contents();

        $firstSection = collect($contents['tabs'])->first()['sections'][0];

        $this->assertNotSame('⚠ Published config file detected', $firstSection['display']);
    }
}
