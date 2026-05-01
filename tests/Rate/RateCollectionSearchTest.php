<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Entry;

class RateCollectionSearchTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
        $this->travelTo(today()->setHour(12));
    }

    public function test_collection_search_excludes_entry_when_rate_fails_min_stay()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'long-stay-only',
            'min_stay' => 5,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        // Search for 2 days — rate requires min_stay of 5
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_excludes_entry_when_rate_fails_lead_time()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'advance-booking',
            'min_days_before' => 7,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        // Search starting tomorrow — rate requires 7 days lead time
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->addDay()->toDateString(),
            'date_end' => today()->addDays(3)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_excludes_entry_when_rate_fails_date_range()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'future-rate',
            'date_start' => today()->addMonth()->toDateString(),
            'date_end' => today()->addMonths(3)->toDateString(),
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        // Search for dates before rate's date_start
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_includes_entry_when_at_least_one_rate_passes()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        // Restricted rate — fails min_stay
        $restrictedRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted',
            'min_stay' => 10,
        ]);

        // Unrestricted rate — passes
        $openRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'open',
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $restrictedRate->id, 4);
        $this->createAvailabilityForEntry($entry, 80, 2, $openRate->id, 4);

        // Search for 2 days — restricted rate fails, open rate passes
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertTrue($result['message']['status']);
        $this->assertArrayHasKey($entry->id(), $result['data']->toArray());
    }

    public function test_scope_excludes_entry_when_only_rate_fails_restrictions()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'restricted-scope',
            'min_stay' => 5,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $query = Entry::query()->where('collection', 'pages');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => today()->addDay(),
                'date_end' => today()->addDays(3),
            ],
            'quantity' => 1,
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertNotContains($entry->id(), $afterScope);
    }

    public function test_collection_search_includes_unrestricted_rate_entry()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'no-restrictions',
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertTrue($result['message']['status']);
        $this->assertArrayHasKey($entry->id(), $result['data']->toArray());
    }

    public function test_collection_search_excludes_entry_when_only_rate_is_unpublished()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'unpublished',
            'published' => false,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $rate->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_includes_entry_when_one_rate_published()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $unpublished = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'unpublished',
            'published' => false,
        ]);

        $published = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'published',
            'published' => true,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $unpublished->id, 4);
        $this->createAvailabilityForEntry($entry, 80, 2, $published->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertTrue($result['message']['status']);
        $this->assertArrayHasKey($entry->id(), $result['data']->toArray());
    }

    public function test_collection_search_includes_entry_when_base_rate_unpublished_but_shared_rate_published()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertTrue($result['message']['status']);
        $this->assertArrayHasKey($entry->id(), $result['data']->toArray());
    }

    public function test_collection_search_excludes_entry_when_shared_rate_also_unpublished()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => false,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_applies_shared_rate_relative_pricing()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->relative()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 4);

        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        $this->assertTrue($result['message']['status']);

        $entryData = $result['data'][$entry->id()]->first();
        // 2 days * 90 (100 - 10%) = 180.00
        $this->assertEquals('180.00', $entryData['price']);
    }

    public function test_collection_search_respects_shared_rate_min_stay_when_filtering_by_rate()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'min_stay' => 5,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 10);

        // Search for 2 days with shared rate that requires min_stay of 5
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $sharedRate->id,
        ]);

        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_includes_entry_when_shared_rate_min_stay_is_met()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $sharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'min_stay' => 5,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 10);

        // Search for 5 days — meets shared rate's min_stay
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(5)->toDateString(),
            'quantity' => 1,
            'rate_id' => $sharedRate->id,
        ]);

        $this->assertTrue($result['message']['status']);
        $this->assertArrayHasKey($entry->id(), $result['data']->toArray());
    }

    public function test_collection_search_excludes_shared_rate_that_fails_restrictions_in_browse()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
            'published' => false,
        ]);

        Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'published' => true,
            'min_stay' => 5,
        ]);

        $this->createAvailabilityForEntry($entry, 100, 2, $baseRate->id, 4);

        // Browse (no rate_id) for 2 days — shared rate requires min_stay 5
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
        ]);

        // Base rate unpublished, shared rate fails restrictions → entry excluded
        $this->assertFalse($result['message']['status']);
    }

    public function test_collection_search_excludes_unassigned_entry_for_scoped_shared_rate()
    {
        $entry1 = $this->makeStatamicItemWithResrvAvailabilityField();
        $entry2 = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'base-rate',
        ]);

        $scopedSharedRate = Rate::factory()->shared()->create([
            'collection' => 'pages',
            'base_rate_id' => $baseRate->id,
            'apply_to_all' => false,
        ]);

        // Only attach to entry1
        $scopedSharedRate->entries()->attach($entry1->id());

        $this->createAvailabilityForEntry($entry1, 100, 2, $baseRate->id, 4);
        $this->createAvailabilityForEntry($entry2, 100, 2, $baseRate->id, 4);

        // Search with scoped shared rate — should only return entry1
        $result = app(Availability::class)->getAvailable([
            'date_start' => today()->toDateString(),
            'date_end' => today()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $scopedSharedRate->id,
        ]);

        $this->assertTrue($result['message']['status']);
        $data = $result['data']->toArray();
        $this->assertArrayHasKey($entry1->id(), $data);
        $this->assertArrayNotHasKey($entry2->id(), $data);
    }
}
