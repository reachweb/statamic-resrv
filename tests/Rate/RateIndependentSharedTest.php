<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Tests\TestCase;

class RateIndependentSharedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    protected function createIndependentSharedSetup(int $baseAvailable = 5, bool $requirePriceOverride = false): array
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'adult-rate',
            'title' => 'Adult',
        ]);

        $childRate = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'child-rate',
            'title' => 'Child',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'require_price_override' => $requirePriceOverride,
        ]);

        $startDate = today();

        // Seed via CP endpoint so dates are stored as 'Y-m-d' (no timestamp)
        $this->postJson(cp_route('resrv.availability.update'), [
            'statamic_id' => $entry->id(),
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'price' => 100,
            'available' => $baseAvailable,
            'rate_ids' => [$baseRate->id],
        ])->assertStatus(200);

        return [
            'entry' => $entry,
            'baseRate' => $baseRate,
            'childRate' => $childRate,
            'startDate' => $startDate,
        ];
    }

    public function test_can_create_shared_independent_rate_via_cp()
    {
        $this->makeStatamicItemWithResrvAvailabilityField();

        $baseRate = Rate::factory()->create(['collection' => 'pages']);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Child',
            'slug' => 'child',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'require_price_override' => false,
            'published' => true,
        ];

        $response = $this->postJson(cp_route('resrv.rate.store'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'child',
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $baseRate->id,
            'require_price_override' => false,
        ]);
    }

    public function test_pricing_type_cannot_be_changed_after_creation()
    {
        $this->withExceptionHandling();
        $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Updated',
            'slug' => $rate->slug,
            'pricing_type' => 'relative',
            'availability_type' => 'independent',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 10,
            'base_rate_id' => null,
            'published' => true,
        ];

        $response = $this->patchJson(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('pricing_type');
    }

    public function test_availability_type_cannot_be_changed_after_creation()
    {
        $this->withExceptionHandling();
        $this->makeStatamicItemWithResrvAvailabilityField();

        $base = Rate::factory()->create(['collection' => 'pages', 'slug' => 'base']);

        $rate = Rate::factory()->create([
            'collection' => 'pages',
            'pricing_type' => 'independent',
            'availability_type' => 'independent',
        ]);

        $payload = [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Updated',
            'slug' => $rate->slug,
            'pricing_type' => 'independent',
            'availability_type' => 'shared',
            'base_rate_id' => $base->id,
            'published' => true,
        ];

        $response = $this->patchJson(cp_route('resrv.rate.update', $rate->id), $payload);
        $response->assertStatus(422)->assertJsonValidationErrors('availability_type');
    }

    public function test_deleting_rate_cascades_rate_prices()
    {
        $setup = $this->createIndependentSharedSetup();

        RatePrice::create([
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'date' => $setup['startDate']->toDateString(),
            'price' => 50,
        ]);

        $this->assertDatabaseHas('resrv_rate_prices', ['rate_id' => $setup['childRate']->id]);

        $this->delete(cp_route('resrv.rate.destroy', $setup['childRate']->id));

        $this->assertDatabaseMissing('resrv_rate_prices', ['rate_id' => $setup['childRate']->id]);
    }

    public function test_cp_index_returns_overrides_for_shared_independent_rate()
    {
        $setup = $this->createIndependentSharedSetup();

        RatePrice::create([
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'date' => $setup['startDate']->toDateString(),
            'price' => 50,
        ]);

        $response = $this->getJson(cp_route('resrv.availability.index', [
            'statamic_id' => $setup['entry']->id(),
            'identifier' => $setup['childRate']->id,
        ]));

        $response->assertStatus(200);

        $data = collect($response->json());
        $this->assertCount(3, $data);

        $startKey = $setup['startDate']->toDateString();
        $secondKey = $setup['startDate']->copy()->addDay()->toDateString();

        $startEntry = $data->first(fn ($r) => str_starts_with((string) $r['date'], $startKey));
        $secondEntry = $data->first(fn ($r) => str_starts_with((string) $r['date'], $secondKey));

        $this->assertNotNull($startEntry);
        $this->assertTrue($startEntry['price_override']);
        $this->assertStringContainsString('50', (string) $startEntry['price']);

        $this->assertNotNull($secondEntry);
        $this->assertFalse($secondEntry['price_override']);
        $this->assertStringContainsString('100', (string) $secondEntry['price']);
    }

    public function test_cp_update_writes_price_to_overrides_for_shared_independent_rate()
    {
        $setup = $this->createIndependentSharedSetup();

        $payload = [
            'statamic_id' => $setup['entry']->id(),
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->toDateString(),
            'price' => '60.00',
            'available' => null,
            'rate_ids' => [$setup['childRate']->id],
        ];

        $response = $this->postJson(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_rate_prices', [
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'price' => 60,
        ]);

        // Base price is unchanged
        $baseRow = Availability::where('rate_id', $setup['baseRate']->id)
            ->where('date', $setup['startDate']->toDateString())
            ->first();
        $this->assertSame('100.00', $baseRow->price->format());
    }

    public function test_cp_update_writes_available_to_base_for_shared_independent_rate()
    {
        $setup = $this->createIndependentSharedSetup();

        $payload = [
            'statamic_id' => $setup['entry']->id(),
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->toDateString(),
            'price' => null,
            'available' => 7,
            'rate_ids' => [$setup['childRate']->id],
        ];

        $response = $this->postJson(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $baseRow = Availability::where('rate_id', $setup['baseRate']->id)
            ->where('date', $setup['startDate']->toDateString())
            ->first();
        $this->assertSame(7, $baseRow->available);
    }

    public function test_pricing_uses_overrides_with_fallback_to_base()
    {
        $setup = $this->createIndependentSharedSetup();

        // Override only the first day; second day falls back to base price (100)
        RatePrice::create([
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'date' => $setup['startDate']->toDateString(),
            'price' => 50,
        ]);

        $availability = new Availability;

        $confirmed = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['childRate']->id,
            'price' => '150.00',
        ], $setup['entry']->id());

        $this->assertTrue($confirmed);
    }

    public function test_pricing_returns_unavailable_when_require_override_and_no_overrides()
    {
        $setup = $this->createIndependentSharedSetup(requirePriceOverride: true);

        $availability = new Availability;

        $confirmed = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['childRate']->id,
            'price' => '200.00',
        ], $setup['entry']->id());

        $this->assertFalse($confirmed);
    }

    public function test_pricing_succeeds_when_require_override_and_all_overrides_present()
    {
        $setup = $this->createIndependentSharedSetup(requirePriceOverride: true);

        foreach ([0, 1] as $offset) {
            RatePrice::create([
                'rate_id' => $setup['childRate']->id,
                'statamic_id' => $setup['entry']->id(),
                'date' => $setup['startDate']->copy()->addDays($offset)->toDateString(),
                'price' => 30,
            ]);
        }

        $availability = new Availability;

        $confirmed = $availability->confirmAvailabilityAndPrice([
            'date_start' => $setup['startDate']->toDateString(),
            'date_end' => $setup['startDate']->copy()->addDays(2)->toDateString(),
            'quantity' => 1,
            'rate_id' => $setup['childRate']->id,
            'price' => '60.00',
        ], $setup['entry']->id());

        $this->assertTrue($confirmed);
    }

    public function test_decrement_targets_base_rate_for_shared_independent()
    {
        $setup = $this->createIndependentSharedSetup(baseAvailable: 5);

        AvailabilityRepository::decrement(
            date_start: $setup['startDate']->toDateString(),
            date_end: $setup['startDate']->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $setup['entry']->id(),
            rateId: $setup['childRate']->id,
            reservationId: 1,
        );

        $rows = Availability::where('rate_id', $setup['baseRate']->id)
            ->where('date', '>=', $setup['startDate']->toDateString())
            ->where('date', '<', $setup['startDate']->copy()->addDays(2)->toDateString())
            ->get();

        foreach ($rows as $row) {
            $this->assertSame(3, $row->available);
        }
    }

    public function test_calendar_filters_dates_without_overrides_when_required()
    {
        $setup = $this->createIndependentSharedSetup(requirePriceOverride: true);

        // Only override the first date
        RatePrice::create([
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'date' => $setup['startDate']->toDateString(),
            'price' => 30,
        ]);

        $availability = new Availability;
        $calendar = $availability->getAvailabilityCalendar(
            $setup['entry']->id(),
            (string) $setup['childRate']->id,
        );

        $startKey = $setup['startDate']->format('Y-m-d');
        $secondKey = $setup['startDate']->copy()->addDay()->format('Y-m-d');

        $this->assertArrayHasKey($startKey, $calendar);
        $this->assertArrayNotHasKey($secondKey, $calendar);
    }

    public function test_calendar_uses_override_price_for_shared_independent()
    {
        $setup = $this->createIndependentSharedSetup();

        RatePrice::create([
            'rate_id' => $setup['childRate']->id,
            'statamic_id' => $setup['entry']->id(),
            'date' => $setup['startDate']->toDateString(),
            'price' => 30,
        ]);

        $availability = new Availability;
        $calendar = $availability->getAvailabilityCalendar(
            $setup['entry']->id(),
            (string) $setup['childRate']->id,
        );

        $startKey = $setup['startDate']->format('Y-m-d');

        $this->assertArrayHasKey($startKey, $calendar);
        $this->assertStringContainsString('30', (string) $calendar[$startKey]['price']);
    }
}
