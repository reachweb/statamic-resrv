<?php

namespace Reach\StatamicResrv\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reach\StatamicResrv\Support\AvailabilitySortBuilder;

class AvailabilitySortBuilderTest extends TestCase
{
    public function test_parse_returns_null_for_empty_input(): void
    {
        $this->assertNull(AvailabilitySortBuilder::parse(null));
        $this->assertNull(AvailabilitySortBuilder::parse(''));
        $this->assertNull(AvailabilitySortBuilder::parse('   '));
    }

    public function test_parse_extracts_field_and_direction(): void
    {
        $this->assertSame(['field' => 'price', 'direction' => 'asc'], AvailabilitySortBuilder::parse('price'));
        $this->assertSame(['field' => 'price', 'direction' => 'asc'], AvailabilitySortBuilder::parse('price:asc'));
        $this->assertSame(['field' => 'price', 'direction' => 'desc'], AvailabilitySortBuilder::parse('price:desc'));
        $this->assertSame(['field' => 'discount', 'direction' => 'desc'], AvailabilitySortBuilder::parse('DISCOUNT:DESC'));
    }

    public function test_parse_returns_null_for_unknown_field(): void
    {
        $this->assertNull(AvailabilitySortBuilder::parse('garbage'));
        $this->assertNull(AvailabilitySortBuilder::parse('title:asc'));
    }

    public function test_parse_falls_back_to_asc_for_unknown_direction(): void
    {
        $this->assertSame(
            ['field' => 'price', 'direction' => 'asc'],
            AvailabilitySortBuilder::parse('price:sideways')
        );
    }

    public function test_sort_orders_entries_by_price_ascending(): void
    {
        $result = $this->buildResult([
            'a' => 100,
            'b' => 50,
            'c' => 200,
        ]);

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'price', 'direction' => 'asc']);

        $this->assertSame(['b', 'a', 'c'], $sorted);
    }

    public function test_sort_orders_entries_by_price_descending(): void
    {
        $result = $this->buildResult([
            'a' => 100,
            'b' => 50,
            'c' => 200,
        ]);

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'price', 'direction' => 'desc']);

        $this->assertSame(['c', 'a', 'b'], $sorted);
    }

    public function test_sort_uses_cheapest_property_for_multi_property_entries(): void
    {
        $result = [
            'data' => collect([
                'a' => collect([
                    ['price' => '50', 'original_price' => null],   // cheapest property of A
                    ['price' => '120', 'original_price' => null],
                ]),
                'b' => collect([
                    ['price' => '70', 'original_price' => null],
                ]),
            ]),
        ];

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'price', 'direction' => 'asc']);

        $this->assertSame(['a', 'b'], $sorted);
    }

    public function test_sort_breaks_ties_by_id_for_stability(): void
    {
        $result = $this->buildResult([
            'zebra' => 100,
            'alpha' => 100,
            'mike' => 100,
        ]);

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'price', 'direction' => 'asc']);

        $this->assertSame(['alpha', 'mike', 'zebra'], $sorted);
    }

    public function test_sort_by_discount_treats_null_original_as_zero(): void
    {
        $result = [
            'data' => collect([
                'has-discount' => collect([
                    ['price' => '80', 'original_price' => '100'], // discount = 20
                ]),
                'no-discount' => collect([
                    ['price' => '60', 'original_price' => null],  // discount = 0
                ]),
                'big-discount' => collect([
                    ['price' => '50', 'original_price' => '200'], // discount = 150
                ]),
            ]),
        ];

        $sortedDesc = AvailabilitySortBuilder::sort($result, ['field' => 'discount', 'direction' => 'desc']);
        $this->assertSame(['big-discount', 'has-discount', 'no-discount'], $sortedDesc);

        $sortedAsc = AvailabilitySortBuilder::sort($result, ['field' => 'discount', 'direction' => 'asc']);
        $this->assertSame(['no-discount', 'has-discount', 'big-discount'], $sortedAsc);
    }

    public function test_sort_handles_decimal_price_strings(): void
    {
        // Price::format() returns DecimalMoneyFormatter output: "1500.50"
        $result = [
            'data' => collect([
                'a' => collect([
                    ['price' => '1500.50', 'original_price' => null],
                ]),
                'b' => collect([
                    ['price' => '2000.00', 'original_price' => null],
                ]),
                'c' => collect([
                    ['price' => '500.00', 'original_price' => null],
                ]),
            ]),
        ];

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'price', 'direction' => 'asc']);

        $this->assertSame(['c', 'a', 'b'], $sorted);
    }

    public function test_sort_handles_empty_data(): void
    {
        $sorted = AvailabilitySortBuilder::sort(['data' => collect([])], ['field' => 'price', 'direction' => 'asc']);

        $this->assertSame([], $sorted);
    }

    public function test_sort_negative_discount_clamped_to_zero(): void
    {
        // original_price < price (shouldn't happen in practice but be defensive).
        $result = [
            'data' => collect([
                'odd' => collect([
                    ['price' => '120', 'original_price' => '100'],
                ]),
                'normal' => collect([
                    ['price' => '80', 'original_price' => '100'],
                ]),
            ]),
        ];

        $sorted = AvailabilitySortBuilder::sort($result, ['field' => 'discount', 'direction' => 'desc']);

        $this->assertSame(['normal', 'odd'], $sorted);
    }

    /**
     * Helper: build a result where each entry has a single property at the given price.
     *
     * @param  array<string, int|float>  $entryPrices
     */
    protected function buildResult(array $entryPrices): array
    {
        return [
            'data' => collect($entryPrices)->map(function ($price) {
                return collect([
                    ['price' => (string) $price, 'original_price' => null],
                ]);
            }),
        ];
    }
}
