<?php

namespace Reach\StatamicResrv\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reach\StatamicResrv\Support\AvailabilityRequestCache;

class AvailabilityRequestCacheTest extends TestCase
{
    public function test_it_stores_and_retrieves_a_result(): void
    {
        $cache = new AvailabilityRequestCache;

        $search = ['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''];
        $result = ['data' => collect(['1' => collect([])])];

        $cache->put($search, $result);

        $this->assertTrue($cache->has($search));

        $entry = $cache->get($search);

        $this->assertSame($result, $entry['result']);
        $this->assertNull($entry['sortedIds']);
        $this->assertFalse($entry['orderApplied']);
    }

    public function test_it_returns_null_for_a_missing_key(): void
    {
        $cache = new AvailabilityRequestCache;

        $this->assertNull($cache->get(['date_start' => '2026-06-01', 'date_end' => '2026-06-05']));
    }

    public function test_it_keys_by_normalized_search_data(): void
    {
        $cache = new AvailabilityRequestCache;

        $cache->put(['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''], ['data' => 'A']);

        // Same dates, different quantity → different key
        $this->assertNull($cache->get(['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 2, 'advanced' => '']));

        // Same dates, default quantity → same key
        $this->assertSame(['data' => 'A'], $cache->get(['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''])['result']);
    }

    public function test_advanced_array_is_normalized_in_key(): void
    {
        $cache = new AvailabilityRequestCache;

        $cache->put([
            'date_start' => '2026-06-01',
            'date_end' => '2026-06-05',
            'quantity' => 1,
            'advanced' => ['b', 'a'],
        ], ['data' => 'A']);

        $entry = $cache->get([
            'date_start' => '2026-06-01',
            'date_end' => '2026-06-05',
            'quantity' => 1,
            'advanced' => ['a', 'b'],
        ]);

        $this->assertSame(['data' => 'A'], $entry['result']);
    }

    public function test_flush_clears_all_entries(): void
    {
        $cache = new AvailabilityRequestCache;

        $cache->put(['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''], ['data' => 'A']);
        $cache->put(['date_start' => '2026-07-01', 'date_end' => '2026-07-05', 'quantity' => 1, 'advanced' => ''], ['data' => 'B']);

        $cache->flush();

        $this->assertNull($cache->get(['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => '']));
        $this->assertNull($cache->get(['date_start' => '2026-07-01', 'date_end' => '2026-07-05', 'quantity' => 1, 'advanced' => '']));
    }

    public function test_forget_clears_a_single_entry(): void
    {
        $cache = new AvailabilityRequestCache;

        $a = ['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''];
        $b = ['date_start' => '2026-07-01', 'date_end' => '2026-07-05', 'quantity' => 1, 'advanced' => ''];

        $cache->put($a, ['data' => 'A']);
        $cache->put($b, ['data' => 'B']);

        $cache->forget($a);

        $this->assertNull($cache->get($a));
        $this->assertSame(['data' => 'B'], $cache->get($b)['result']);
    }

    public function test_it_stores_sorted_ids_and_order_flag(): void
    {
        $cache = new AvailabilityRequestCache;
        $search = ['date_start' => '2026-06-01', 'date_end' => '2026-06-05', 'quantity' => 1, 'advanced' => ''];

        $cache->put($search, ['data' => 'A'], ['id-2', 'id-1', 'id-3'], true);

        $entry = $cache->get($search);

        $this->assertSame(['id-2', 'id-1', 'id-3'], $entry['sortedIds']);
        $this->assertTrue($entry['orderApplied']);
    }
}
