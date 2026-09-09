<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

/**
 * Model-level Extra pricing under Rate::units_per_addon ("booked units per add-on").
 *
 * Both pricing paths are covered for every case: priceForDates() (the display price the
 * Extras component shows) and calculatePrice($data, $selectedQuantity) (the validation price
 * Checkout re-derives). Fixture: the 'normal' entry from CreatesEntries (4 rows today..today+3
 * at 50/night on the 'default' rate) plus a 'single-occupancy' rate with units_per_addon = 2
 * that carries its own 50/night rows. Every $data spans 2 nights.
 */
class ExtraUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $entries;

    protected $item;

    protected Rate $defaultRate;

    protected Rate $divisorRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));

        $this->entries = $this->createEntries();
        $this->item = $this->entries->first();

        $this->defaultRate = Rate::forEntry($this->item->id())->where('slug', 'default')->first();

        $this->divisorRate = Rate::factory()->unitsPerAddon(2)->create([
            'collection' => 'pages',
            'slug' => 'single-occupancy',
            'title' => 'Single occupancy',
        ]);

        // Own availability rows so the relative extra can price against this rate.
        $this->createAvailabilityForEntry($this->item, 50, 2, $this->divisorRate->id, 4);
    }

    private function data(int $quantity, $rateId, array $extra = []): array
    {
        return array_merge([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => $quantity,
            'item_id' => $this->item->id(),
            'rate_id' => $rateId,
        ], $extra);
    }

    // priceForDates() mutates the model's price, so always price a freshly fetched instance.
    private function displayPrice(Extra $extra, array $data): string
    {
        return Extra::find($extra->id)->priceForDates($data);
    }

    private function validationPrice(Extra $extra, array $data, int $selectedQuantity = 1): string
    {
        return Extra::find($extra->id)->calculatePrice($data, $selectedQuantity)->format();
    }

    public function test_fixed_extra_divides_the_booked_quantity_on_the_divisor_rate()
    {
        $extra = Extra::factory()->fixed()->create();

        // 25 × ceil(2 / 2) = 25 instead of 25 × 2.
        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));

        // The customer-selected extra quantity still applies on top of the add-on quantity.
        $this->assertEquals('50.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 2));
    }

    public function test_fixed_extra_multiplies_the_full_quantity_on_a_rate_without_divisor()
    {
        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals('50.00', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id)));
        $this->assertEquals('50.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id), 1));
    }

    public function test_perday_extra_divides_the_booked_quantity_on_the_divisor_rate()
    {
        $extra = Extra::factory()->create();

        // 4.65 × 2 nights × ceil(2 / 2) = 9.30
        $this->assertEquals('9.30', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('9.30', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));

        // 4.65 × 2 nights × 2 = 18.60
        $this->assertEquals('18.60', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id)));
        $this->assertEquals('18.60', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id), 1));
    }

    public function test_custom_extra_divides_the_booked_quantity_on_the_divisor_rate()
    {
        $extra = Extra::factory()->custom()->create();
        $customer = ['customer' => collect(['adults' => 3])];

        // 10 × 3 adults × ceil(2 / 2) = 30
        $this->assertEquals('30.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id, $customer)));
        $this->assertEquals('30.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id, $customer), 1));
        $this->assertEquals('60.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id, $customer), 2));

        // 10 × 3 adults × 2 = 60
        $this->assertEquals('60.00', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id, $customer)));
        $this->assertEquals('60.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id, $customer), 1));
        $this->assertEquals('120.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id, $customer), 2));
    }

    /**
     * A relative extra prices against Availability::getPricing(), whose base ALREADY includes
     * ×quantity (HandlesPricing::getPrices), so a relative extra at quantity 2 is charged
     * quantity² on a rate without divisor — pre-existing behaviour that plan 010 will change.
     * The divisor therefore only removes the trailing add-on multiplier: 0.5 × 200 × 1 = 100.
     */
    public function test_relative_extra_keeps_the_quantity_in_its_base_and_only_divides_the_addon_multiplier()
    {
        $extra = Extra::factory()->relative()->create();

        // Sanity: the relative base for 2 nights at 50 and quantity 2 is 200 (base × quantity).
        $this->assertEquals('200.00', (new Availability)->getPricing($this->data(2, $this->divisorRate->id), $this->item->id(), true));

        // 0.5 × 200 × ceil(2 / 2) = 100
        $this->assertEquals('100.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('100.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));

        // 0.5 × 200 × 2 = 200 (quantity²)
        $this->assertEquals('200.00', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id)));
        $this->assertEquals('200.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id), 1));

        // 0.5 × 100 = 50 at quantity 1
        $this->assertEquals('50.00', $this->displayPrice($extra, $this->data(1, $this->defaultRate->id)));
        $this->assertEquals('50.00', $this->validationPrice($extra, $this->data(1, $this->defaultRate->id), 1));
    }

    public static function roundingProvider(): array
    {
        return [
            'quantity 1' => [1, '25.00'],
            'quantity 2' => [2, '25.00'],
            'quantity 3' => [3, '50.00'],
            'quantity 4' => [4, '50.00'],
            'quantity 5' => [5, '75.00'],
        ];
    }

    /**
     * A partial add-on unit is never free: the add-on quantity is ceil(quantity / 2), so
     * 3 booked units are charged 2 add-ons and 5 booked units are charged 3.
     */
    #[DataProvider('roundingProvider')]
    public function test_fixed_extra_rounds_partial_addon_units_up(int $quantity, string $expected)
    {
        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals($expected, $this->displayPrice($extra, $this->data($quantity, $this->divisorRate->id)));
        $this->assertEquals($expected, $this->validationPrice($extra, $this->data($quantity, $this->divisorRate->id), 1));
    }

    /**
     * ceil(2 / 3) = 1: a divisor larger than the booked quantity charges exactly one add-on.
     */
    public function test_divisor_larger_than_the_booked_quantity_charges_one_addon()
    {
        $tripleRate = Rate::factory()->unitsPerAddon(3)->create([
            'collection' => 'pages',
            'slug' => 'triple-occupancy',
            'title' => 'Triple occupancy',
        ]);

        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(2, $tripleRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(2, $tripleRate->id), 1));
    }

    public function test_units_per_addon_of_one_is_a_no_op()
    {
        $this->divisorRate->update(['units_per_addon' => 1]);

        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals('50.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('50.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));
    }

    public function test_rate_without_divisor_keeps_multiplying_by_the_booked_quantity()
    {
        $this->assertNull($this->defaultRate->units_per_addon);

        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(1, $this->defaultRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(1, $this->defaultRate->id), 1));

        $this->assertEquals('50.00', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id)));
        $this->assertEquals('50.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id), 1));
    }

    /**
     * The global ignore_quantity_for_prices flag wins: when it is on, add-ons are never
     * multiplied by the booked quantity, divisor or not.
     */
    public function test_ignore_quantity_for_prices_wins_over_the_divisor()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $extra = Extra::factory()->fixed()->create();

        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));

        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(2, $this->defaultRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(2, $this->defaultRate->id), 1));
    }

    public function test_soft_deleted_divisor_rate_still_divides()
    {
        $extra = Extra::factory()->fixed()->create();

        $this->divisorRate->delete();

        $this->assertTrue(Rate::withTrashed()->find($this->divisorRate->id)->trashed());

        $this->assertEquals('25.00', $this->displayPrice($extra, $this->data(2, $this->divisorRate->id)));
        $this->assertEquals('25.00', $this->validationPrice($extra, $this->data(2, $this->divisorRate->id), 1));
    }

    /**
     * Rate::unitsPerAddonFor() memoises the divisor for the request; Rate's saved/deleted/restored
     * model events flush that memo, so an Eloquent update to units_per_addon (what the CP does)
     * is visible to the very next pricing call without any manual cache reset.
     */
    public function test_divisor_changes_saved_through_eloquent_are_visible_to_the_next_pricing_call()
    {
        $extra = Extra::factory()->fixed()->create();
        $data = $this->data(2, $this->divisorRate->id);

        $this->assertEquals('25.00', $this->displayPrice($extra, $data));

        $this->divisorRate->update(['units_per_addon' => null]);

        $this->assertEquals('50.00', $this->displayPrice($extra, $data));
        $this->assertEquals('50.00', $this->validationPrice($extra, $data, 1));

        // ceil(2 / 4) = 1
        $this->divisorRate->update(['units_per_addon' => 4]);

        $this->assertEquals('25.00', $this->displayPrice($extra, $data));
        $this->assertEquals('25.00', $this->validationPrice($extra, $data, 1));
    }

    public function test_any_missing_or_unknown_rate_falls_back_to_the_full_quantity()
    {
        $extra = Extra::factory()->fixed()->create();

        $anyRate = $this->data(2, 'any');
        $this->assertEquals('50.00', $this->displayPrice($extra, $anyRate));
        $this->assertEquals('50.00', $this->validationPrice($extra, $anyRate, 1));

        $missingRate = $this->data(2, null);
        unset($missingRate['rate_id']);
        $this->assertArrayNotHasKey('rate_id', $missingRate);
        $this->assertEquals('50.00', $this->displayPrice($extra, $missingRate));
        $this->assertEquals('50.00', $this->validationPrice($extra, $missingRate, 1));

        $unknownRate = $this->data(2, 99999);
        $this->assertNull(Rate::withTrashed()->find(99999));
        $this->assertEquals('50.00', $this->displayPrice($extra, $unknownRate));
        $this->assertEquals('50.00', $this->validationPrice($extra, $unknownRate, 1));
    }

    public function test_get_price_for_dates_scope_prices_extras_under_the_reservation_rate_divisor()
    {
        $extra = Extra::factory()->fixed()->create();

        ResrvEntry::whereItemId($this->item->id())->extras()->attach($extra->id);

        $divisorReservation = Reservation::factory()->withRate($this->divisorRate->id)->create([
            'item_id' => $this->item->id(),
            'quantity' => 2,
        ]);

        $priced = Extra::getPriceForDates($divisorReservation)->firstWhere('id', $extra->id);

        $this->assertNotNull($priced);
        $this->assertEquals('25.00', $priced->price->format());
        $this->assertEquals('25.00', $priced->original_price);

        $defaultReservation = Reservation::factory()->withRate($this->defaultRate->id)->create([
            'item_id' => $this->item->id(),
            'quantity' => 2,
        ]);

        $priced = Extra::getPriceForDates($defaultReservation)->firstWhere('id', $extra->id);

        $this->assertNotNull($priced);
        $this->assertEquals('50.00', $priced->price->format());
    }

    /**
     * Pricing every extra of an entry under one rate must resolve the divisor once, not once per
     * extra, for rates with and without a divisor.
     */
    public function test_pricing_many_extras_resolves_the_divisor_once()
    {
        $resrvEntry = ResrvEntry::whereItemId($this->item->id());

        foreach (range(1, 10) as $i) {
            $extra = Extra::factory()->fixed()->create(['id' => 200 + $i, 'slug' => 'batch-extra-'.$i]);
            $resrvEntry->extras()->attach($extra->id);
        }

        foreach ([$this->divisorRate, $this->defaultRate] as $rate) {
            Rate::resetUnitsPerAddonCache();
            DB::flushQueryLog();
            DB::enableQueryLog();

            $priced = Extra::getPriceForDates($this->data(2, $rate->id));

            $rateQueries = collect(DB::getQueryLog())
                ->filter(fn ($query) => str_contains($query['query'], 'resrv_rates'))
                ->count();

            DB::disableQueryLog();

            $this->assertCount(10, $priced);
            $this->assertSame(1, $rateQueries, "Expected one rate lookup for rate {$rate->slug}");
        }

        $this->assertEquals('25.00', Extra::getPriceForDates($this->data(2, $this->divisorRate->id))->first()->price->format());
        $this->assertEquals('50.00', Extra::getPriceForDates($this->data(2, $this->defaultRate->id))->first()->price->format());
    }
}
