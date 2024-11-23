<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Antlers;
use Statamic\Tags\Collection\Collection;

class DynamicPricingApplyTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    public $date;

    public $entry;

    public $collectionTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entry = $this->makeStatamicItem();
        $this->collectionTag = (new Collection)
            ->setParser(Antlers::parser())
            ->setContext([]);
        $this->travelTo(today()->setHour(11));
    }

    private function createDynamicPricing($factory, $attributes = [], $clear = true)
    {
        if ($clear) {
            DB::table('resrv_dynamic_pricing')->delete();
            DB::table('resrv_dynamic_pricing_assignments')->delete();
        }

        if ($factory == 'create') {
            $dynamic = DynamicPricing::factory()->create($attributes);
        } else {
            $dynamic = DynamicPricing::factory()->$factory()->create($attributes);
        }

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entry->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();

        return $dynamic;
    }

    private function assertPrice($expectedPrice, $expectedOriginalPrice = null, $dates = null, $quantity = 1, $advanced = null)
    {
        $dates = $dates ?? [
            'date_start' => $this->date->toISOString(),
            'date_end' => $this->date->copy()->add(4, 'day')->toISOString(),
        ];

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id()])
            ->dispatch('availability-search-updated', [
                'dates' => $dates,
                'quantity' => $quantity,
                'advanced' => $advanced,
            ])
            ->assertViewHas('availability.data.price', $expectedPrice)
            ->assertViewHas('availability.data.original_price', $expectedOriginalPrice);
    }

    private function assertIndexPrice($expectedPrice, $expectedOriginalPrice = null, $dates = null, $quantity = 1, $advanced = null)
    {
        $dates = $dates ?? [
            'date_start' => $this->date,
            'date_end' => $this->date->copy()->add(4, 'day'),
        ];

        $this->collectionTag->setParameters([
            'collection' => 'pages',
            'query_scope' => 'resrv_search',
            'resrv_search:resrv_availability' => [
                'dates' => $dates,
                'quantity' => $quantity,
                'advanced' => $advanced,
            ],
        ]);

        $returnedEntries = $this->collectionTag->index()->first();

        $this->assertEquals($expectedPrice, $returnedEntries->get('live_availability')['payment']);
        $this->assertEquals($expectedOriginalPrice, $returnedEntries->get('live_availability')['original_price']);
    }

    public function test_dynamic_pricing_changes_availability_prices()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);

        // Baseline price
        $this->assertPrice('100.92');
        $this->assertIndexPrice('100.92');

        // We should get 80.74 for 20% percent decrease
        $this->createDynamicPricing('create');

        $this->assertPrice('80.74', '100.92');
        $this->assertIndexPrice('80.74', '100.92');

        // We should get 121.10 for 20% percent increase
        $this->createDynamicPricing('percentIncrease');

        $this->assertPrice('121.10', '100.92');
        $this->assertIndexPrice('121.10', '100.92');

        // We should get 80 for 20.92 fixed decrease
        $this->createDynamicPricing('fixedDecrease');

        $this->assertPrice('80.00', '100.92');
        $this->assertIndexPrice('80.00', '100.92');

        // We should get 111.00 for fixed increase
        $this->createDynamicPricing('fixedIncrease');

        $this->assertPrice('111.00', '100.92');
        $this->assertIndexPrice('111.00', '100.92');
    }

    public function test_dynamic_pricing_date_conditions()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);

        // Check that it doesn't work if date is outside range
        $this->createDynamicPricing('create');

        $dates = [
            'date_start' => $this->date->copy()->addDays(7)->toISOString(),
            'date_end' => $this->date->copy()->addDays(11)->toISOString(),
        ];

        $this->assertPrice('100.92', null, $dates);
        $this->assertIndexPrice('100.92', null, $dates);

        // But it works for "start" date condition
        $this->createDynamicPricing('dateStart');

        $dates = [
            'date_start' => $this->date->copy()->addDays(7)->toISOString(),
            'date_end' => $this->date->copy()->addDays(11)->toISOString(),
        ];

        $this->assertPrice('80.74', '100.92', $dates);
        $this->assertIndexPrice('80.74', '100.92', $dates);

        // And "most" date condition
        $this->createDynamicPricing('dateMost');

        $dates = [
            'date_start' => $this->date->copy()->addDays(7)->toISOString(),
            'date_end' => $this->date->copy()->addDays(11)->toISOString(),
        ];

        $this->assertPrice('80.74', '100.92', $dates);
        $this->assertIndexPrice('80.74', '100.92', $dates);

        // Let's check even further up in time

        // Now it shouldn't work for the date_start condition
        $this->createDynamicPricing('dateStart');

        $dates = [
            'date_start' => $this->date->copy()->addDays(11)->toISOString(),
            'date_end' => $this->date->copy()->addDays(15)->toISOString(),
        ];

        $this->assertPrice('100.92', null, $dates);
        $this->assertIndexPrice('100.92', null, $dates);

        // And also not work for the "most" date condition
        $this->createDynamicPricing('dateMost');

        $dates = [
            'date_start' => $this->date->copy()->addDays(11)->toISOString(),
            'date_end' => $this->date->copy()->addDays(15)->toISOString(),
        ];

        $this->assertPrice('100.92', null, $dates);
        $this->assertIndexPrice('100.92', null, $dates);
    }

    public function test_dynamic_pricing_reservation_price_conditions()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);

        // Should work for 100.92 original price
        $this->createDynamicPricing('conditionPriceOver');
        $this->assertPrice('80.74', '100.92');
        $this->assertIndexPrice('80.74', '100.92');

        // Shouldn't work for 100.92 original price
        $this->createDynamicPricing('conditionPriceUnder');
        $this->assertPrice('100.92');
        $this->assertIndexPrice('100.92');

        // Should work without dates
        $this->createDynamicPricing('noDates');
        $this->assertPrice('80.74', '100.92');
        $this->assertIndexPrice('80.74', '100.92');
    }

    public function test_dynamic_pricing_applies_by_ordering()
    {
        $this->createAvailabilityForEntry($this->entry, 50, 2);

        $dynamic1 = $this->createDynamicPricing('percentDecrease', [
            'title' => 'Take 50% off',
            'amount' => '50',
        ], false);

        $dynamic2 = $this->createDynamicPricing('fixedIncrease', [
            'title' => 'Add 50',
            'amount' => '50',
        ], false);

        // Reorder them
        $dynamic1->update(['order' => 2]);
        $dynamic2->update(['order' => 1]);

        $this->assertPrice('125.00', '200.00');
        $this->assertIndexPrice('125.00', '200.00');
    }

    public function test_dynamic_pricing_with_expiration_date()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);
        $this->createDynamicPricing('expires');

        $futureDates = [
            'date_start' => $this->date->copy()->add(10, 'day')->toISOString(),
            'date_end' => $this->date->copy()->add(14, 'day')->toISOString(),
        ];

        $this->assertPrice('80.74', '100.92', $futureDates);

        // Testing for time - after 5 days and at 5:00 it should not have expired
        $this->travelBack();
        $this->travelTo(today()->add(5, 'day')->setHour(5));
        $this->assertPrice('80.74', '100.92', $futureDates);

        // Testing for time - after 5 days and at 11:00 it should have expired
        $this->travelBack();
        $this->travelTo(today()->add(5, 'day')->setHour(11));
        $this->assertPrice('100.92', null, $futureDates);

        // Also after 6 days it should have expired
        $this->travelBack();
        $this->travelTo(today()->add(6, 'day')->setHour(11));
        $this->assertPrice('100.92', null, $futureDates);
    }

    public function test_dynamic_pricing_with_coupon()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);
        $this->createDynamicPricing('withCoupon');

        $this->assertPrice('100.92');

        session(['resrv_coupon' => '20OFF']);

        $this->assertPrice('80.74', '100.92');
    }

    public function test_dynamic_pricing_that_overrides_others()
    {
        $this->createAvailabilityForEntry($this->entry, 50, 2);

        $this->createDynamicPricing('percentDecrease', [
            'title' => 'Take 20% off',
            'amount' => '20',
        ], false);

        $this->createDynamicPricing('percentDecrease', [
            'title' => 'Take 10% off',
            'amount' => '10',
        ], false);

        $this->assertPrice('144.00', '200.00');

        $this->createDynamicPricing('overridesAll', [
            'title' => 'Take 15% off',
            'amount' => '15',
        ], false);

        $this->assertPrice('170.00', '200.00');
    }

    public function test_dynamic_pricing_applies_to_extras()
    {
        $this->createAvailabilityForEntry($this->entry, 25.23, 2);

        $extra = Extra::factory()->fixed()->create();

        $entry = ResrvEntry::itemId($this->entry->id())->first();

        $entry->extras()->attach($extra->id);

        $dynamic = DynamicPricing::factory()->extra()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $extra->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Extra',
        ]);

        Cache::flush();

        Livewire::test(AvailabilityResults::class, ['entry' => $this->entry->id(), 'showExtras' => true])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'advanced' => null,
            ])
            ->assertSet('extras.0.price', '23.00');
    }
}
