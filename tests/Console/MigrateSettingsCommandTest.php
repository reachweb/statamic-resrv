<?php

namespace Reach\StatamicResrv\Tests\Console;

use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\YAML;

class MigrateSettingsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([config_path('resrv-config.php'), resource_path('addons/statamic-resrv.yaml')] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_reports_nothing_to_migrate_without_a_published_config()
    {
        $this->artisan('resrv:settings:migrate')
            ->expectsOutputToContain('nothing to migrate')
            ->assertExitCode(0);
    }

    public function test_dry_run_reports_without_writing_the_settings_store()
    {
        file_put_contents(
            config_path('resrv-config.php'),
            "<?php\n\nreturn ['currency_isoCode' => 'USD', 'form_name' => 'checkout'];"
        );

        $this->artisan('resrv:settings:migrate', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('currency_isoCode')
            ->expectsOutputToContain('form_name')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(resource_path('addons/statamic-resrv.yaml'));
    }

    public function test_migrates_customized_values_into_the_settings_store()
    {
        file_put_contents(
            config_path('resrv-config.php'),
            "<?php\n\nreturn ['currency_isoCode' => 'USD', 'maximum_quantity' => 8];"
        );

        $this->artisan('resrv:settings:migrate')
            ->expectsOutputToContain('Seeded into CP settings')
            ->expectsOutputToContain('Safe to delete from config/resrv-config.php')
            ->assertExitCode(0);

        $saved = YAML::file(resource_path('addons/statamic-resrv.yaml'))->parse();

        $this->assertSame('USD', $saved['currency_isoCode']);
        $this->assertArrayNotHasKey('maximum_quantity', $saved);
    }

    public function test_migrates_nested_overrides_into_flat_cp_keys()
    {
        file_put_contents(
            config_path('resrv-config.php'),
            "<?php\n\nreturn [\n"
            ."    'checkout_forms' => ['default' => 'checkout'],\n"
            ."    'reservation_emails' => ['global' => ['customer_confirmed' => ['subject' => 'Hi']]],\n"
            .'];'
        );

        $this->artisan('resrv:settings:migrate')
            ->expectsOutputToContain('Seeded into CP settings')
            ->expectsOutputToContain('Safe to delete from config/resrv-config.php')
            ->assertExitCode(0);

        $saved = YAML::file(resource_path('addons/statamic-resrv.yaml'))->parse();

        $this->assertSame('checkout', $saved['checkout_forms_default']);
        $this->assertSame(
            [['event' => 'customer_confirmed', 'subject' => 'Hi']],
            $saved['reservation_emails_global']
        );
    }
}
