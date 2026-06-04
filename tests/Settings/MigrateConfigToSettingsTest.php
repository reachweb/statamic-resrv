<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\UpdateScripts\MigrateConfigToSettings;
use Statamic\Facades\Addon;
use Statamic\Testing\Concerns\RunsUpdateScripts;

class MigrateConfigToSettingsTest extends TestCase
{
    use RunsUpdateScripts;

    protected function tearDown(): void
    {
        foreach ([config_path('resrv-config.php'), resource_path('addons/statamic-resrv.yaml')] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_update_script_is_registered()
    {
        $this->assertUpdateScriptRegistered(MigrateConfigToSettings::class);
    }

    public function test_update_script_seeds_published_config_into_cp_settings()
    {
        file_put_contents(
            config_path('resrv-config.php'),
            "<?php\n\nreturn ['currency_isoCode' => 'USD'];\n"
        );

        $this->runUpdateScript(MigrateConfigToSettings::class, 'reachweb/statamic-resrv');

        $this->assertSame(
            'USD',
            Addon::get('reachweb/statamic-resrv')->settings()->raw()['currency_isoCode']
        );
    }
}
