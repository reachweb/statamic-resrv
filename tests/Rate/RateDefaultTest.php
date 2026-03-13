<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\TestCase;

class RateDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_default_rate_for_entry_without_rates()
    {
        $item = $this->makeStatamicItem();

        $rate = Rate::findOrCreateDefaultForEntry($item->id());

        $this->assertNotNull($rate);
        $this->assertEquals('pages', $rate->collection);
        $this->assertEquals('default', $rate->slug);
        $this->assertEquals('Default', $rate->title);
        $this->assertTrue($rate->apply_to_all);
        $this->assertTrue($rate->published);
        $this->assertDatabaseCount('resrv_rates', 1);
    }

    public function test_returns_existing_default_rate_without_creating_duplicate()
    {
        $item = $this->makeStatamicItem();

        $existing = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'default',
            'title' => 'Default',
            'apply_to_all' => true,
        ]);

        $rate = Rate::findOrCreateDefaultForEntry($item->id());

        $this->assertEquals($existing->id, $rate->id);
        $this->assertDatabaseCount('resrv_rates', 1);
    }

    public function test_attaches_pivot_when_existing_default_is_not_apply_to_all()
    {
        $item = $this->makeStatamicItem();

        $existing = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'default',
            'title' => 'Default',
            'apply_to_all' => false,
        ]);

        $rate = Rate::findOrCreateDefaultForEntry($item->id());

        $this->assertEquals($existing->id, $rate->id);
        $this->assertDatabaseCount('resrv_rates', 1);
        $this->assertDatabaseHas('resrv_rate_entries', [
            'rate_id' => $rate->id,
            'statamic_id' => $item->id(),
        ]);

        // Verify forEntry() now finds it
        $found = Rate::forEntry($item->id())->first();
        $this->assertNotNull($found);
        $this->assertEquals($rate->id, $found->id);
    }

    public function test_returns_null_for_unknown_entry()
    {
        $rate = Rate::findOrCreateDefaultForEntry('nonexistent-id');

        $this->assertNull($rate);
        $this->assertDatabaseCount('resrv_rates', 0);
    }

    public function test_restores_soft_deleted_default_rate()
    {
        $item = $this->makeStatamicItem();

        $existing = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'default',
            'title' => 'Default',
            'apply_to_all' => true,
        ]);
        $existing->delete();

        $this->assertSoftDeleted('resrv_rates', ['id' => $existing->id]);

        $rate = Rate::findOrCreateDefaultForEntry($item->id());

        $this->assertNotNull($rate);
        $this->assertEquals($existing->id, $rate->id);
        $this->assertNull($rate->deleted_at);
        $this->assertDatabaseCount('resrv_rates', 1);
    }
}
