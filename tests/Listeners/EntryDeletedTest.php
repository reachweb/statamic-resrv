<?php

namespace Reach\StatamicResrv\Tests\Listeners;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class EntryDeletedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_deleting_an_entry_removes_all_of_its_availability()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);
        Availability::factory()->create([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDays(2)->isoFormat('YYYY-MM-DD'),
        ]);

        $item->delete();

        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $item->id(),
        ]);
    }

    public function test_deleting_an_entry_removes_its_dynamic_pricing_assignments()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();
        $other = $this->makeStatamicItemWithResrvAvailabilityField();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            [
                'dynamic_pricing_id' => 1,
                'dynamic_pricing_assignment_id' => $item->id(),
                'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
            ],
            [
                'dynamic_pricing_id' => 1,
                'dynamic_pricing_assignment_id' => $other->id(),
                'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
            ],
        ]);

        $item->delete();

        $this->assertDatabaseMissing('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $item->id(),
        ]);

        // The assignment for an unrelated entry must survive.
        $this->assertDatabaseHas('resrv_dynamic_pricing_assignments', [
            'dynamic_pricing_assignment_id' => $other->id(),
        ]);
    }

    public function test_deleting_an_entry_clears_the_disabled_ids_cache()
    {
        $item = $this->makeStatamicItemWithResrvAvailabilityField();

        Cache::put('resrv_disabled_entry_ids', ['sentinel'], 300);

        $item->delete();

        $this->assertFalse(Cache::has('resrv_disabled_entry_ids'));
    }

    public function test_deleting_an_entry_without_the_availability_field_leaves_resrv_data_untouched()
    {
        $resrvItem = $this->makeStatamicItemWithResrvAvailabilityField();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        Availability::factory()->create([
            'statamic_id' => $resrvItem->id(),
            'rate_id' => $rate->id,
            'date' => today()->addDay()->isoFormat('YYYY-MM-DD'),
        ]);

        $plainItem = $this->makeStatamicWithoutResrvAvailabilityField();

        $plainItem->delete();

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $resrvItem->id(),
        ]);
    }
}
