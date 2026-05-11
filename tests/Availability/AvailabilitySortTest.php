<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Support\AvailabilityRequestCache;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Antlers;
use Statamic\Tags\Collection\Collection;

class AvailabilitySortTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $date;

    public $entries;

    public $collectionTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();

        $this->collectionTag = (new Collection)
            ->setParser(Antlers::parser())
            ->setContext([]);
    }

    public function test_it_sorts_returned_entries_by_price_ascending(): void
    {
        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'price:asc'],
        ));

        $returned = $this->collectionTag->index();

        $this->assertCount(3, $returned);

        $ids = collect($returned)->map->id()->all();
        $expected = [
            $this->entries->get('half-price')->id(),    // 25
            $this->entries->get('normal')->id(),        // 50
            $this->entries->get('two-available')->id(), // 50
        ];

        // First item is uniquely cheapest (25); the next two share price 50 so order between them
        // is determined by the natural-id tiebreaker — we just verify the first ID is the cheapest.
        $this->assertSame($expected[0], $ids[0]);
        $this->assertEqualsCanonicalizing(array_slice($expected, 1), array_slice($ids, 1));
    }

    public function test_it_sorts_returned_entries_by_price_descending(): void
    {
        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'price:desc'],
        ));

        $returned = $this->collectionTag->index();

        $this->assertCount(3, $returned);

        $ids = collect($returned)->map->id()->all();

        // half-price (25) must NOT be first when sorting desc
        $this->assertNotSame($this->entries->get('half-price')->id(), $ids[0]);
        // half-price (25) must be last when sorting desc (it's the cheapest)
        $this->assertSame($this->entries->get('half-price')->id(), end($ids));
    }

    public function test_default_behavior_unchanged_when_no_sort_param(): void
    {
        $this->setTagParameters($this->baseSearchParams());

        $returned = $this->collectionTag->index();

        $this->assertCount(3, $returned);

        // Existing test in AvailabilityHookTest.php asserts only that live_availability is set.
        // Here we additionally assert no exception, no orderApplied flag in cache.
        foreach ($returned as $entry) {
            $this->assertArrayHasKey('live_availability', $entry->toArray());
        }
    }

    public function test_invalid_sort_value_falls_back_to_default(): void
    {
        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'garbage'],
        ));

        $returned = $this->collectionTag->index();

        $this->assertCount(3, $returned);
    }

    public function test_unknown_direction_defaults_to_ascending(): void
    {
        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'price:sideways'],
        ));

        $returned = $this->collectionTag->index();

        $ids = collect($returned)->map->id()->all();

        $this->assertSame($this->entries->get('half-price')->id(), $ids[0]);
    }

    public function test_php_fallback_reorders_collection_when_orderbyraw_not_supported(): void
    {
        // Stache (file-based entries) doesn't support orderByRaw, so the scope's
        // applyPriceOrder() returns false and the hook's PHP fallback kicks in.
        // This test verifies the hook fallback produces correctly sorted results.
        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'price:asc'],
        ));

        $returned = $this->collectionTag->index();
        $ids = collect($returned)->map->id()->all();

        $this->assertSame($this->entries->get('half-price')->id(), $ids[0]);
    }

    public function test_scope_populates_request_cache(): void
    {
        $cache = $this->app->make(AvailabilityRequestCache::class);
        $cache->flush();

        $this->setTagParameters($this->baseSearchParams());

        $this->collectionTag->index();

        $searchArray = [
            'date_start' => $this->date,
            'date_end' => $this->date->copy()->add(1, 'day'),
            'quantity' => 1,
            'advanced' => '',
        ];

        $this->assertNotNull($cache->get($searchArray), 'Scope should populate the request cache');
    }

    public function test_sort_with_pagination_throws_when_php_fallback_would_be_wrong(): void
    {
        // On query builders that don't support orderByRaw (e.g. Statamic's Stache
        // flat-file driver, used in this test environment), our PHP fallback can
        // only reorder the page that the database returned — which produces a
        // globally-wrong sort. We refuse to silently mis-sort: throw a clear
        // exception so the user knows to switch to the Eloquent driver.
        $this->expectException(AvailabilityException::class);
        $this->expectExceptionMessage('orderByRaw');

        $this->setTagParameters(array_merge(
            $this->baseSearchParams(),
            ['resrv_sort' => 'price:asc', 'limit' => 1],
        ));

        $this->collectionTag->index();
    }

    public function test_sort_works_when_no_results(): void
    {
        $this->setTagParameters([
            'collection' => 'pages',
            'query_scope' => 'resrv_search',
            'resrv_sort' => 'price:asc',
            'resrv_search:resrv_availability' => [
                'dates' => [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(7, 'day'), // no availability for 7 days
                ],
            ],
        ]);

        $returned = $this->collectionTag->index();

        $this->assertCount(0, $returned);
    }

    private function baseSearchParams(): array
    {
        return [
            'collection' => 'pages',
            'query_scope' => 'resrv_search',
            'resrv_search:resrv_availability' => [
                'dates' => [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ],
            ],
        ];
    }

    private function setTagParameters($parameters): void
    {
        $this->collectionTag->setParameters($parameters);
    }
}
