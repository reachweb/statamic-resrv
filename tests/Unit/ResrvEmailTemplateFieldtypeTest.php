<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Illuminate\Support\Facades\File;
use Reach\StatamicResrv\Fieldtypes\ResrvEmailTemplate;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Addon;
use Statamic\Fields\FieldtypeRepository;

class ResrvEmailTemplateFieldtypeTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/vendor/statamic-resrv'));

        parent::tearDown();
    }

    public function test_it_lists_the_shipped_email_templates_as_namespaced_views()
    {
        $options = collect((new ResrvEmailTemplate)->preload()['options']);

        $values = $options->pluck('value');

        $this->assertContains('statamic-resrv::email.reservations.confirmed', $values);
        $this->assertContains('statamic-resrv::email.reservations.made', $values);
        $this->assertContains('statamic-resrv::email.reservations.refunded', $values);
        $this->assertContains('statamic-resrv::email.reservations.abandoned', $values);
        $this->assertContains('statamic-resrv::email.reservations.orphaned-payment', $values);

        $this->assertEquals('Confirmed', $options->firstWhere('value', 'statamic-resrv::email.reservations.confirmed')['label']);
        $this->assertEquals('Orphaned payment', $options->firstWhere('value', 'statamic-resrv::email.reservations.orphaned-payment')['label']);
    }

    public function test_it_does_not_list_theme_partials()
    {
        $values = collect((new ResrvEmailTemplate)->preload()['options'])->pluck('value');

        $values->each(fn (string $value) => $this->assertStringNotContainsString('theme', $value));
    }

    public function test_published_overrides_are_deduplicated_against_vendor_views()
    {
        File::ensureDirectoryExists(resource_path('views/vendor/statamic-resrv/email/reservations'));
        File::put(resource_path('views/vendor/statamic-resrv/email/reservations/confirmed.blade.php'), 'override');

        $values = collect((new ResrvEmailTemplate)->preload()['options'])->pluck('value');

        $this->assertCount(1, $values->filter(fn (string $value) => $value === 'statamic-resrv::email.reservations.confirmed'));
    }

    public function test_custom_published_templates_are_listed()
    {
        File::ensureDirectoryExists(resource_path('views/vendor/statamic-resrv/email/reservations'));
        File::put(resource_path('views/vendor/statamic-resrv/email/reservations/my-custom.blade.php'), 'custom');

        $options = collect((new ResrvEmailTemplate)->preload()['options']);

        $this->assertContains('statamic-resrv::email.reservations.my-custom', $options->pluck('value'));
        $this->assertEquals('My custom', $options->firstWhere('value', 'statamic-resrv::email.reservations.my-custom')['label']);
    }

    public function test_templates_with_dots_in_their_name_are_excluded()
    {
        File::ensureDirectoryExists(resource_path('views/vendor/statamic-resrv/email/reservations'));
        File::put(resource_path('views/vendor/statamic-resrv/email/reservations/my.custom.blade.php'), 'custom');

        $values = collect((new ResrvEmailTemplate)->preload()['options'])->pluck('value');

        $this->assertNotContains('statamic-resrv::email.reservations.my.custom', $values);
    }

    public function test_the_fieldtype_is_registered()
    {
        $this->assertInstanceOf(ResrvEmailTemplate::class, app(FieldtypeRepository::class)->find('resrv_email_template'));
    }

    public function test_the_settings_blueprint_uses_the_fieldtype_for_both_markdown_fields()
    {
        $blueprint = Addon::get('reachweb/statamic-resrv')->settingsBlueprint();

        foreach (['reservation_emails_global', 'reservation_emails_forms'] as $handle) {
            $grid = $blueprint->field($handle);

            $markdown = collect($grid->config()['fields'])->firstWhere('handle', 'markdown');

            $this->assertSame('resrv_email_template', $markdown['field']['type'], "Markdown field in {$handle} should use the resrv_email_template fieldtype");
        }
    }
}
