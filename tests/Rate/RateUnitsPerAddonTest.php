<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

/**
 * Schema and model contract for resrv_rates.units_per_addon ("booked units per add-on"), plus
 * the negative guarantee that the divisor only ever touches add-on pricing: cabin pricing,
 * availability confirmation and the stock decrement must be identical with or without it.
 */
class RateUnitsPerAddonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock mid-day so "today" is stable across a test (dates before today are
        // rejected by the availability engine, pending holds expire after minutes_to_hold).
        $this->travelTo(today()->setHour(12));
    }

    /**
     * An entry with a rate and 2 consecutive priced availability rows (today and tomorrow)
     * at 100/night with 5 units in stock. Returns [entry, rate, startDate].
     */
    private function createRateWithAvailability(array $rateAttributes = [], ?Rate $rate = null): array
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate ??= Rate::factory()->unitsPerAddon(2)->create($rateAttributes);

        $startDate = now()->startOfDay();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => $startDate],
                ['date' => $startDate->copy()->addDay()],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
                'price' => 100,
                'available' => 5,
            ]);

        return [$entry, $rate, $startDate];
    }

    /**
     * A 2-night stay for the given rate at quantity 2 (the "party of 3" cruise cabin shape).
     */
    private function pricingData(Rate $rate, $startDate, int $quantity = 2): array
    {
        return [
            'date_start' => $startDate->toDateString(),
            'date_end' => $startDate->copy()->addDays(2)->toDateString(),
            'quantity' => $quantity,
            'rate_id' => $rate->id,
        ];
    }

    private function availabilityRowsFor(Rate $rate, $startDate)
    {
        return Availability::where('rate_id', $rate->id)
            ->where('date', '>=', $startDate->toDateString())
            ->where('date', '<', $startDate->copy()->addDays(2)->toDateString())
            ->get();
    }

    // ---------------------------------------------------------------------------------------
    // Schema and model contract
    // ---------------------------------------------------------------------------------------

    public function test_factory_defaults_units_per_addon_to_null()
    {
        $rate = Rate::factory()->create();

        $this->assertNull($rate->fresh()->units_per_addon);

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'units_per_addon' => null,
        ]);
    }

    public function test_units_per_addon_is_cast_to_integer()
    {
        $fromString = Rate::factory()->create(['units_per_addon' => '2']);

        $this->assertSame(2, $fromString->fresh()->units_per_addon);

        $fromState = Rate::factory()->unitsPerAddon(3)->create(['slug' => 'triple-berth']);

        $this->assertSame(3, $fromState->fresh()->units_per_addon);
    }

    public function test_units_per_addon_is_mass_assignable()
    {
        $rate = Rate::factory()->create();

        $rate->update(['units_per_addon' => 4]);

        $this->assertSame(4, $rate->fresh()->units_per_addon);

        $created = Rate::create([
            'collection' => 'pages',
            'title' => 'Single Cabin',
            'slug' => 'single-cabin',
            'units_per_addon' => 1,
        ]);

        $this->assertSame(1, $created->fresh()->units_per_addon);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'single-cabin',
            'units_per_addon' => 1,
        ]);
    }

    public function test_migration_adds_and_drops_the_units_per_addon_column()
    {
        $migration = include __DIR__.'/../../database/migrations/2026_09_08_000000_add_units_per_addon_to_rates.php';

        $this->assertTrue(Schema::hasColumn('resrv_rates', 'units_per_addon'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('resrv_rates', 'units_per_addon'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('resrv_rates', 'units_per_addon'));

        $rate = Rate::factory()->unitsPerAddon(2)->create();

        $this->assertDatabaseHas('resrv_rates', [
            'id' => $rate->id,
            'units_per_addon' => 2,
        ]);
    }

    public function test_units_per_addon_for_returns_null_without_a_rate()
    {
        $this->assertNull(Rate::unitsPerAddonFor(null));

        // An id that was never persisted must not throw.
        $this->assertNull(Rate::unitsPerAddonFor(999999));
    }

    public function test_units_per_addon_for_returns_null_for_a_rate_without_a_divisor()
    {
        $rate = Rate::factory()->create();

        $this->assertNull(Rate::unitsPerAddonFor($rate->id));
    }

    public static function unitsPerAddonValues(): array
    {
        return [
            'two berths per guest' => [2, 2],
            // 1 is stored as-is; the pricing seam treats it as "no divisor" (see quantityForAddons()).
            'one unit per add-on' => [1, 1],
        ];
    }

    #[DataProvider('unitsPerAddonValues')]
    public function test_units_per_addon_for_returns_the_rate_divisor(int $stored, int $expected)
    {
        $rate = Rate::factory()->unitsPerAddon($stored)->create();

        $this->assertSame($expected, Rate::unitsPerAddonFor($rate->id));
    }

    /**
     * withTrashed on purpose: a reservation booked under a rate that was later deleted must keep
     * pricing its add-ons under that rate's divisor.
     */
    public function test_units_per_addon_for_survives_a_soft_deleted_rate()
    {
        $rate = Rate::factory()->unitsPerAddon(2)->create();

        $rate->delete();

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);
        $this->assertSame(2, Rate::unitsPerAddonFor($rate->id));
    }

    private function rateQueries(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'resrv_rates'))
            ->count();
    }

    /**
     * Every extra and option value priced under a rate asks for its divisor. That lookup is
     * memoised for the request, so a batch of 20 add-ons costs one query per distinct rate,
     * whether or not the rate carries a divisor.
     */
    public function test_units_per_addon_for_is_resolved_once_per_rate_for_the_request()
    {
        $divisor = Rate::factory()->unitsPerAddon(2)->create();
        $plain = Rate::factory()->create(['slug' => 'plain']);

        Rate::resetUnitsPerAddonCache();
        DB::enableQueryLog();

        foreach (range(1, 10) as $i) {
            $this->assertSame(2, Rate::unitsPerAddonFor($divisor->id));
            $this->assertNull(Rate::unitsPerAddonFor($plain->id));
        }

        $this->assertSame(2, $this->rateQueries());

        DB::disableQueryLog();
    }

    /**
     * The memo is bound to the current request object, so a long-lived worker (Octane) that
     * serves a new request starts cold instead of reusing a divisor edited by another process.
     */
    public function test_units_per_addon_for_starts_cold_on_a_new_request()
    {
        $rate = Rate::factory()->unitsPerAddon(2)->create();

        Rate::resetUnitsPerAddonCache();
        DB::enableQueryLog();

        $this->assertSame(2, Rate::unitsPerAddonFor($rate->id));
        $this->assertSame(2, Rate::unitsPerAddonFor($rate->id));
        $this->assertSame(1, $this->rateQueries());

        // A raw write is invisible to the memo held for this request...
        DB::table('resrv_rates')->where('id', $rate->id)->update(['units_per_addon' => 3]);
        $this->assertSame(2, Rate::unitsPerAddonFor($rate->id));

        // ...and visible as soon as a new request object is bound.
        app()->instance('request', Request::create('/'));

        $this->assertSame(3, Rate::unitsPerAddonFor($rate->id));
        $this->assertSame(2, $this->rateQueries());

        DB::disableQueryLog();
    }

    /**
     * The memo must never outlive a change to the rate: saving, deleting or restoring the rate
     * through Eloquent refreshes the value on the next lookup.
     */
    public function test_units_per_addon_for_refreshes_after_the_rate_changes()
    {
        $rate = Rate::factory()->unitsPerAddon(2)->create();

        $this->assertSame(2, Rate::unitsPerAddonFor($rate->id));

        $rate->update(['units_per_addon' => 3]);
        $this->assertSame(3, Rate::unitsPerAddonFor($rate->id));

        $rate->update(['units_per_addon' => null]);
        $this->assertNull(Rate::unitsPerAddonFor($rate->id));

        $rate->update(['units_per_addon' => 4]);
        $rate->delete();
        $this->assertSame(4, Rate::unitsPerAddonFor($rate->id));

        $rate->restore();
        $this->assertSame(4, Rate::unitsPerAddonFor($rate->id));

        // A raw write bypasses model events; the explicit reset is the escape hatch.
        DB::table('resrv_rates')->where('id', $rate->id)->update(['units_per_addon' => 5]);
        $this->assertSame(4, Rate::unitsPerAddonFor($rate->id));

        Rate::resetUnitsPerAddonCache();
        $this->assertSame(5, Rate::unitsPerAddonFor($rate->id));
    }

    // ---------------------------------------------------------------------------------------
    // Negative guarantee: cabin pricing and stock are untouched by the divisor
    // ---------------------------------------------------------------------------------------

    /**
     * Cabin price is 100/night x 2 nights x quantity 2 = 400. A units_per_addon of 2 must not
     * halve it: the divisor only applies to extras and option values.
     */
    public function test_cabin_pricing_ignores_units_per_addon()
    {
        [$entry, $rate, $startDate] = $this->createRateWithAvailability();

        $this->assertSame(2, $rate->fresh()->units_per_addon);

        $pricing = (new Availability)->getPricing($this->pricingData($rate, $startDate), $entry->id());

        $this->assertSame('400.00', $pricing['price']);
        $this->assertNull($pricing['original_price']);

        // Payment type defaults to "full", so the payment due equals the reservation price.
        $this->assertSame('full', config('resrv-config.payment'));
        $this->assertSame('400.00', $pricing['payment']);

        $onlyPrice = (new Availability)->getPricing($this->pricingData($rate, $startDate), $entry->id(), true);

        $this->assertSame('400.00', $onlyPrice);
    }

    public function test_confirm_availability_and_price_ignores_units_per_addon()
    {
        [$entry, $rate, $startDate] = $this->createRateWithAvailability();

        $this->assertTrue((new Availability)->confirmAvailabilityAndPrice(
            $this->pricingData($rate, $startDate) + ['price' => '400.00', 'payment' => '400.00'],
            $entry->id()
        ));

        // The halved price (what a divisor-leaking bug would produce) must be rejected.
        $this->assertFalse((new Availability)->confirmAvailabilityAndPrice(
            $this->pricingData($rate, $startDate) + ['price' => '200.00', 'payment' => '200.00'],
            $entry->id()
        ));
    }

    public function test_availability_for_entry_ignores_units_per_addon()
    {
        [$entry, $rate, $startDate] = $this->createRateWithAvailability();

        $result = (new Availability)->getAvailabilityForEntry($this->pricingData($rate, $startDate), $entry->id());

        $this->assertTrue($result['message']['status']);
        $this->assertSame('400.00', $result['data']['price']);
        $this->assertEquals($rate->id, $result['data']['rate_id']);
        $this->assertSame(2, $result['request']['quantity']);
    }

    /**
     * Booking quantity 2 takes 2 units out of stock on every night, not ceil(2 / 2) = 1.
     */
    public function test_stock_decrement_ignores_units_per_addon()
    {
        [$entry, $rate, $startDate] = $this->createRateWithAvailability();

        AvailabilityRepository::decrement(
            date_start: $startDate->toDateString(),
            date_end: $startDate->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $entry->id(),
            rateId: $rate->id,
            reservationId: 1,
        );

        $rows = $this->availabilityRowsFor($rate, $startDate);

        $this->assertCount(2, $rows);

        foreach ($rows as $availability) {
            $this->assertEquals(3, $availability->available);
            $this->assertContains('r1', $availability->pending);
        }
    }

    /**
     * The cruise "single" cabin: a relative (-25%) shared rate carrying units_per_addon = 2 whose
     * stock lives on the "double" base rate. 75/night x 2 nights x quantity 2 = 300, and the
     * decrement hits the base rows by the full quantity.
     */
    public function test_shared_relative_rate_pricing_and_stock_ignore_units_per_addon()
    {
        $base = Rate::factory()->create([
            'collection' => 'pages',
            'slug' => 'double',
        ]);

        [$entry, , $startDate] = $this->createRateWithAvailability(rate: $base);

        $single = Rate::factory()->relative()->shared()->unitsPerAddon(2)->create([
            'collection' => 'pages',
            'slug' => 'single',
            'base_rate_id' => $base->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $this->assertSame(2, $single->fresh()->units_per_addon);
        $this->assertNull($base->fresh()->units_per_addon);

        $price = (new Availability)->getPricing($this->pricingData($single, $startDate), $entry->id(), true);

        $this->assertSame('300.00', $price);

        AvailabilityRepository::decrement(
            date_start: $startDate->toDateString(),
            date_end: $startDate->copy()->addDays(2)->toDateString(),
            quantity: 2,
            statamic_id: $entry->id(),
            rateId: $single->id,
            reservationId: 1,
        );

        $baseRows = $this->availabilityRowsFor($base, $startDate);

        $this->assertCount(2, $baseRows);

        foreach ($baseRows as $availability) {
            $this->assertEquals(3, $availability->available);
            $this->assertContains('r1', $availability->pending);
        }

        // The shared rate never owns availability rows of its own.
        $this->assertCount(0, $this->availabilityRowsFor($single, $startDate));
    }

    // ---------------------------------------------------------------------------------------
    // Relations keep exposing the divisor for historical reservations
    // ---------------------------------------------------------------------------------------

    public function test_reservation_rate_relations_expose_units_per_addon_after_the_rate_is_deleted()
    {
        $entry = $this->makeStatamicItemWithResrvAvailabilityField();

        $rate = Rate::factory()->unitsPerAddon(2)->create();

        $reservation = Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $entry->id(),
        ]);

        $child = ChildReservation::factory()->withRate($rate->id)->create([
            'reservation_id' => $reservation->id,
        ]);

        $rate->delete();

        $this->assertSoftDeleted('resrv_rates', ['id' => $rate->id]);

        $this->assertSame(2, $reservation->fresh()->rate->units_per_addon);
        $this->assertSame(2, $child->fresh()->rate->units_per_addon);
    }
}
