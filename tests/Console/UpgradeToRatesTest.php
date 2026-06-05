<?php

namespace Reach\StatamicResrv\Tests\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

class UpgradeToRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_no_rates_exist()
    {
        $this->artisan('resrv:upgrade-to-rates')
            ->expectsOutputToContain('resrv_rates table does not exist or has no data')
            ->assertExitCode(1);
    }

    public function test_renames_property_slug_rates_to_their_blueprint_labels()
    {
        $this->collectionWithAvailabilityField('rooms', [
            'advanced_availability' => [
                'mountain-view' => 'Mountain View',
                'sea-view' => 'Sea View',
            ],
        ]);

        $mountain = $this->makeRate('rooms', 'mountain-view');
        $sea = $this->makeRate('rooms', 'sea-view');

        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();

        $this->assertEquals('Mountain View', $mountain->fresh()->title);
        $this->assertEquals('Sea View', $sea->fresh()->title);
    }

    public function test_renames_default_rate_to_standard_rate_when_collection_has_no_properties()
    {
        $this->collectionWithAvailabilityField('villas');

        $default = $this->makeRate('villas', 'default');

        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();

        $this->assertEquals('Standard Rate', $default->fresh()->title);
    }

    public function test_does_not_overwrite_a_customized_default_rate_title()
    {
        $this->collectionWithAvailabilityField('villas');

        $custom = Rate::factory()->create([
            'collection' => 'villas',
            'slug' => 'default',
            'title' => 'My Custom Rate',
        ]);

        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();

        $this->assertEquals('My Custom Rate', $custom->fresh()->title);
    }

    public function test_multiple_propertyless_blueprints_process_the_default_rate_once()
    {
        $this->collectionWithAvailabilityField('villas');
        $this->availabilityBlueprint('villas', 'second');

        $this->makeRate('villas', 'default');

        // Dry-run isolates the dedupe: without it, each blueprint re-counts the
        // same (unchanged) default rate and the projected total is wrong.
        $this->artisan('resrv:upgrade-to-rates', ['--dry-run' => true])
            ->expectsOutputToContain('1 rate title(s) would be updated')
            ->assertSuccessful();
    }

    public function test_dry_run_followed_by_real_run_still_updates_the_default_rate()
    {
        $this->collectionWithAvailabilityField('villas');

        $default = $this->makeRate('villas', 'default');

        // Artisan reuses the same command instance within one process, so the
        // dedupe state recorded by the dry run must not leak into the real run.
        $this->artisan('resrv:upgrade-to-rates', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();

        $this->assertEquals('Standard Rate', $default->fresh()->title);
    }

    public function test_dry_run_makes_no_changes()
    {
        $this->collectionWithAvailabilityField('rooms', [
            'advanced_availability' => ['mountain-view' => 'Mountain View'],
        ]);

        $rate = $this->makeRate('rooms', 'mountain-view');

        $this->artisan('resrv:upgrade-to-rates', ['--dry-run' => true])
            ->expectsOutputToContain('1 rate title(s) would be updated')
            ->assertSuccessful();

        $this->assertEquals('mountain-view', $rate->fresh()->title);
    }

    public function test_running_twice_leaves_rate_titles_correct()
    {
        $this->collectionWithAvailabilityField('rooms', [
            'advanced_availability' => ['mountain-view' => 'Mountain View'],
        ]);

        $rate = $this->makeRate('rooms', 'mountain-view');

        // Run twice: the title==slug guard means the second pass skips the
        // already-renamed rate, so the title must stay correct (not re-mangled).
        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();
        $this->artisan('resrv:upgrade-to-rates')->assertSuccessful();

        $this->assertEquals('Mountain View', $rate->fresh()->title);
    }

    public function test_warns_about_cross_entry_connected_availabilities()
    {
        $this->collectionWithAvailabilityField('rooms', [
            'connected_availabilities' => [
                ['connected_availability_type' => 'same_slug'],
            ],
        ]);

        $this->makeRate('rooms', 'default');

        $this->artisan('resrv:upgrade-to-rates')
            ->expectsOutputToContain('Cross-entry connected availabilities detected')
            ->assertSuccessful();
    }

    private function makeRate(string $collection, string $slug): Rate
    {
        return Rate::factory()->create([
            'collection' => $collection,
            'slug' => $slug,
            'title' => $slug,
        ]);
    }

    private function collectionWithAvailabilityField(string $handle, array $fieldConfig = []): void
    {
        Collection::make($handle)->routes('/{slug}')->save();

        $this->availabilityBlueprint($handle, $handle, $fieldConfig);
    }

    private function availabilityBlueprint(string $collection, string $handle, array $fieldConfig = []): void
    {
        Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                        ['handle' => 'resrv_availability', 'field' => array_merge([
                            'type' => 'resrv_availability',
                            'display' => 'Resrv Availability',
                        ], $fieldConfig)],
                    ],
                ],
            ],
        ])->setHandle($handle)->setNamespace('collections.'.$collection)->save();
    }
}
