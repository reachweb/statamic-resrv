<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Scopes\ResrvSearch;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Entry;

class AvailabilityScopeTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $rateEntries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->rateEntries = $this->createRateEntries();
    }

    // Test that it can filter Entries based on availability
    public function test_it_can_filter_entries()
    {
        $query = Entry::query()->where('collection', 'pages');

        $beforeScope = $query->get()->pluck('id')->all();

        $this->assertCount(5, $beforeScope);
        $this->assertContains($this->entries->first()->id(), $beforeScope);
        $this->assertContains($this->entries->get('none-available')->id(), $beforeScope);
        $this->assertContains($this->entries->get('two-available')->id(), $beforeScope);
        $this->assertContains($this->entries->get('stop-sales')->id(), $beforeScope);

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(3, $afterScope);
        $this->assertContains($this->entries->first()->id(), $afterScope);
        $this->assertNotContains($this->entries->get('none-available')->id(), $afterScope);
        $this->assertContains($this->entries->get('two-available')->id(), $afterScope);
        $this->assertNotContains($this->entries->get('stop-sales')->id(), $afterScope);
        $this->assertContains($this->entries->get('half-price')->id(), $afterScope);
    }

    // Test that it correctly filters everything if no availability exists for that period
    public function test_it_returns_nothing_for_7_days()
    {
        $query = Entry::query()->where('collection', 'pages');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(7, 'day'),
            ],
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(0, $afterScope);
        // Another one because it was showing up as risky
        $this->assertEmpty($afterScope);
    }

    // Test that it returns the correct Entry when asking for a quantity of 2
    public function test_it_returns_the_correct_one_for_quantity_2()
    {
        $query = Entry::query()->where('collection', 'pages');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 2,
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(1, $afterScope);
        $this->assertContains($this->entries->get('two-available')->id(), $afterScope);
    }

    // Test that it filters by rate_id
    public function test_it_filters_by_rate_entry()
    {
        $query = Entry::query()->where('collection', 'advanced');

        $rateIds = $this->getRateIdsForSlug('test');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 1,
            'advanced' => implode('|', $rateIds),
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(3, $afterScope);
        $this->assertContains($this->rateEntries->first()->id(), $afterScope);
    }

    // Test that it filters by rate_id and quantity
    public function test_it_filters_by_rate_entry_and_quantity()
    {
        $query = Entry::query()->where('collection', 'advanced');

        $rateIds = $this->getRateIdsForSlug('test');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 2,
            'advanced' => implode('|', $rateIds),
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(1, $afterScope);
        $this->assertContains($this->rateEntries[2]->id(), $afterScope);
        $this->assertNotContains($this->rateEntries->first()->id(), $afterScope);
    }

    // Test that it can get all available when using the 'any' magic value
    public function test_it_can_get_all_availability_with_the_any_magic_word()
    {
        $query = Entry::query()->where('collection', 'advanced');

        $values = ['resrv_search:resrv_availability' => [
            'dates' => [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ],
            'quantity' => 1,
            'advanced' => 'any',
        ]];

        (new ResrvSearch)->apply($query, $values);

        $afterScope = $query->get()->pluck('id')->all();

        $this->assertCount(6, $afterScope);
        $this->assertContains($this->rateEntries->first()->id(), $afterScope);
        $this->assertNotContains($this->rateEntries[1]->id(), $afterScope);
    }

    /**
     * Get all rate_ids for rates with a given slug across all rate entries.
     *
     * @return array<int>
     */
    protected function getRateIdsForSlug(string $slug): array
    {
        $entryIds = $this->rateEntries->map(fn ($entry) => $entry->id())->toArray();

        return Rate::whereIn('statamic_id', $entryIds)
            ->where('slug', $slug)
            ->pluck('id')
            ->toArray();
    }
}
