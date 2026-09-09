<?php

namespace Reach\StatamicResrv\Tests\Option;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

/**
 * Option value pricing under resrv_rates.units_per_addon ("booked units per add-on").
 *
 * A rate may divide the reservation quantity when pricing add-ons: the effective add-on
 * quantity is max(1, ceil(quantity / units_per_addon)). NULL or 1 keeps the old behaviour
 * (add-ons multiply by the booked quantity). The divisor rate here is the cruise
 * "single occupancy" cabin sold as 2 berths to 1 guest (units_per_addon = 2).
 *
 * Fixture prices: fixed value 30.00, perday value 22.75/day over a 2-night stay (45.50).
 */
class OptionValueUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $entries;

    public $item;

    public $defaultRate;

    public $divisorRate;

    public $option;

    public $fixedValue;

    public $perdayValue;

    public $freeValue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setHour(12));
        $this->entries = $this->createEntries();
        $this->item = $this->entries->first();

        // createEntries() creates the 'default' rate for the 'pages' collection.
        $this->defaultRate = Rate::where('collection', 'pages')->where('slug', 'default')->firstOrFail();

        $this->divisorRate = Rate::factory()->unitsPerAddon(2)->create([
            'collection' => 'pages',
            'slug' => 'single-occupancy',
            'title' => 'Single occupancy',
        ]);

        $this->option = Option::factory()->create([
            'item_id' => $this->item->id(),
        ]);

        // fixed() state: 30.00
        $this->fixedValue = OptionValue::factory()->fixed()->create([
            'option_id' => $this->option->id,
        ]);

        // Default state: perday at 22.75/day
        $this->perdayValue = OptionValue::factory()->create([
            'option_id' => $this->option->id,
        ]);

        $this->freeValue = OptionValue::factory()->create([
            'option_id' => $this->option->id,
            'price' => '0',
            'price_type' => 'free',
        ]);
    }

    /**
     * A 2-night stay starting today, without any rate_id key.
     */
    private function baseData(int $quantity): array
    {
        return [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => $quantity,
        ];
    }

    private function data(int $quantity, $rateId): array
    {
        return array_merge($this->baseData($quantity), ['rate_id' => $rateId]);
    }

    /**
     * OptionValue::priceForDates() / calculatePrice() mutate the model, so always price a
     * freshly fetched instance.
     */
    private function fixed(): OptionValue
    {
        return OptionValue::find($this->fixedValue->id);
    }

    private function perday(): OptionValue
    {
        return OptionValue::find($this->perdayValue->id);
    }

    private function free(): OptionValue
    {
        return OptionValue::find($this->freeValue->id);
    }

    public function test_fixed_value_is_charged_once_per_addon_unit_under_a_divisor_rate()
    {
        $data = $this->data(2, $this->divisorRate->id);

        // quantity 2 / units_per_addon 2 = 1 add-on unit → 30.00 (not 60.00)
        $this->assertEquals('30.00', $this->fixed()->priceForDates($data));
        $this->assertEquals('30.00', $this->fixed()->calculatePrice($data)->format());
    }

    public function test_fixed_value_keeps_multiplying_by_quantity_under_the_default_rate()
    {
        $data = $this->data(2, $this->defaultRate->id);

        $this->assertEquals('60.00', $this->fixed()->priceForDates($data));
        $this->assertEquals('60.00', $this->fixed()->calculatePrice($data)->format());
    }

    public function test_perday_value_is_charged_once_per_addon_unit_under_a_divisor_rate()
    {
        $data = $this->data(2, $this->divisorRate->id);

        // 22.75 × 2 nights × 1 add-on unit = 45.50 (not 91.00)
        $this->assertEquals('45.50', $this->perday()->priceForDates($data));
        $this->assertEquals('45.50', $this->perday()->calculatePrice($data)->format());
    }

    public function test_perday_value_keeps_multiplying_by_quantity_under_the_default_rate()
    {
        $data = $this->data(2, $this->defaultRate->id);

        // 22.75 × 2 nights × 2 = 91.00
        $this->assertEquals('91.00', $this->perday()->priceForDates($data));
        $this->assertEquals('91.00', $this->perday()->calculatePrice($data)->format());
    }

    public static function roundingProvider(): array
    {
        // [booked quantity, expected fixed price] with units_per_addon = 2 and a 30.00 fixed value
        return [
            'qty 1 → 1 add-on unit' => [1, '30.00'],
            'qty 2 → 1 add-on unit' => [2, '30.00'],
            'qty 3 → 2 add-on units' => [3, '60.00'],
            'qty 4 → 2 add-on units' => [4, '60.00'],
            'qty 5 → 3 add-on units' => [5, '90.00'],
        ];
    }

    /**
     * The add-on quantity is ceil(quantity / units_per_addon): a partial unit is never free,
     * so 3 berths at 2 berths per add-on is charged as 2 add-ons, and 5 as 3.
     */
    #[DataProvider('roundingProvider')]
    public function test_addon_quantity_rounds_up(int $quantity, string $expected)
    {
        // The qty 5 case relies on the global maximum quantity allowing it (default 8).
        $this->assertGreaterThanOrEqual(5, config('resrv-config.maximum_quantity'));

        $data = $this->data($quantity, $this->divisorRate->id);

        $this->assertEquals($expected, $this->fixed()->priceForDates($data));
        $this->assertEquals($expected, $this->fixed()->calculatePrice($data)->format());
    }

    public function test_free_value_stays_free_regardless_of_rate_and_quantity()
    {
        $this->assertEquals('0.00', $this->free()->priceForDates($this->data(2, $this->divisorRate->id)));
        $this->assertEquals('0.00', $this->free()->priceForDates($this->data(2, $this->defaultRate->id)));
        $this->assertEquals('0.00', $this->free()->priceForDates($this->data(1, $this->defaultRate->id)));
    }

    /**
     * The global ignore_quantity_for_prices flag wins: when it is on, add-ons are never
     * multiplied by the quantity, whether or not the rate carries a divisor.
     */
    public function test_ignore_quantity_for_prices_flag_wins_over_the_divisor()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $this->assertEquals('30.00', $this->fixed()->priceForDates($this->data(2, $this->divisorRate->id)));
        $this->assertEquals('30.00', $this->fixed()->priceForDates($this->data(2, $this->defaultRate->id)));
        $this->assertEquals('45.50', $this->perday()->priceForDates($this->data(2, $this->defaultRate->id)));
    }

    /**
     * The divisor is resolved withTrashed so a reservation booked under a rate that was later
     * deleted keeps pricing its add-ons the way it was sold.
     */
    public function test_soft_deleted_divisor_rate_still_divides_the_addon_quantity()
    {
        $this->divisorRate->delete();
        $this->assertTrue(Rate::withTrashed()->find($this->divisorRate->id)->trashed());

        $data = $this->data(2, $this->divisorRate->id);

        $this->assertEquals('30.00', $this->fixed()->priceForDates($data));
        $this->assertEquals('45.50', $this->perday()->priceForDates($data));
    }

    /**
     * Rate::unitsPerAddonFor() memoises the divisor for the request; Rate's saved/deleted/restored
     * model events flush that memo, so an Eloquent update to units_per_addon (what the CP does)
     * is visible to the very next pricing call without any manual cache reset.
     */
    public function test_divisor_changes_saved_through_eloquent_are_visible_to_the_next_pricing_call()
    {
        $data = $this->data(2, $this->divisorRate->id);

        $this->assertEquals('30.00', $this->fixed()->priceForDates($data));

        $this->divisorRate->update(['units_per_addon' => null]);
        $this->assertEquals('60.00', $this->fixed()->priceForDates($data));

        // ceil(2 / 4) = 1 add-on unit
        $this->divisorRate->update(['units_per_addon' => 4]);
        $this->assertEquals('30.00', $this->fixed()->priceForDates($data));
    }

    public static function unresolvableRateProvider(): array
    {
        // Extra keys merged over baseData(): 'any' and null map to no rate, a missing key is
        // no rate, and an unknown id resolves to no divisor.
        return [
            'any' => [['rate_id' => 'any']],
            'null' => [['rate_id' => null]],
            'missing' => [[]],
            'unknown' => [['rate_id' => 99999]],
        ];
    }

    #[DataProvider('unresolvableRateProvider')]
    public function test_rate_id_without_a_divisor_keeps_multiplying_by_quantity(array $rateData)
    {
        $data = array_merge($this->baseData(2), $rateData);

        $this->assertEquals('60.00', $this->fixed()->priceForDates($data));
        $this->assertEquals('91.00', $this->perday()->priceForDates($data));
    }

    // Option::calculatePrice($data, $valueId) is the entry point used by
    // Reservation::extraCharges() / validateExtraCharges().
    public function test_option_calculate_price_entry_point_applies_the_divisor()
    {
        $divisorData = $this->data(2, $this->divisorRate->id);
        $defaultData = $this->data(2, $this->defaultRate->id);

        $this->assertEquals('30.00', Option::find($this->option->id)->calculatePrice($divisorData, $this->fixedValue->id)->format());
        $this->assertEquals('45.50', Option::find($this->option->id)->calculatePrice($divisorData, $this->perdayValue->id)->format());
        $this->assertEquals('60.00', Option::find($this->option->id)->calculatePrice($defaultData, $this->fixedValue->id)->format());
        $this->assertEquals('91.00', Option::find($this->option->id)->calculatePrice($defaultData, $this->perdayValue->id)->format());
    }

    // Option::valuesPriceForDates($data) is the display entry point used by the Options
    // Livewire component.
    public function test_option_values_price_for_dates_display_entry_point_applies_the_divisor()
    {
        $option = Option::find($this->option->id)->valuesPriceForDates($this->data(2, $this->divisorRate->id));

        $fixed = $option->values->firstWhere('price_type', 'fixed');
        $perday = $option->values->firstWhere('price_type', 'perday');
        $free = $option->values->firstWhere('price_type', 'free');

        $this->assertEquals('30.00', $fixed->price->format());
        $this->assertEquals('30.00', $fixed->original_price);
        $this->assertEquals('45.50', $perday->price->format());
        $this->assertEquals('22.75', $perday->original_price);
        $this->assertEquals('0.00', $free->price->format());

        // Same values under the default rate keep the ×quantity behaviour.
        $option = Option::find($this->option->id)->valuesPriceForDates($this->data(2, $this->defaultRate->id));

        $this->assertEquals('60.00', $option->values->firstWhere('price_type', 'fixed')->price->format());
        $this->assertEquals('91.00', $option->values->firstWhere('price_type', 'perday')->price->format());
    }

    /**
     * Pricing a batch of values under one rate must resolve the divisor once, not once per value
     * (20 values used to add 20 identical rate queries), for rates with and without a divisor.
     */
    public function test_pricing_many_values_resolves_the_divisor_once()
    {
        $option = Option::factory()->create(['item_id' => $this->item->id()]);
        OptionValue::factory()->fixed()->count(20)->create(['option_id' => $option->id]);

        foreach ([$this->divisorRate, $this->defaultRate] as $rate) {
            Rate::resetUnitsPerAddonCache();
            DB::flushQueryLog();
            DB::enableQueryLog();

            $priced = Option::find($option->id)->valuesPriceForDates($this->data(2, $rate->id));

            $rateQueries = collect(DB::getQueryLog())
                ->filter(fn ($query) => str_contains($query['query'], 'resrv_rates'))
                ->count();

            DB::disableQueryLog();

            $this->assertCount(20, $priced->values);
            $this->assertSame(1, $rateQueries, "Expected one rate lookup for rate {$rate->slug}");
        }

        // The prices themselves are unaffected by the memo.
        $this->assertEquals('30.00', Option::find($option->id)->valuesPriceForDates($this->data(2, $this->divisorRate->id))->values->first()->price->format());
        $this->assertEquals('60.00', Option::find($option->id)->valuesPriceForDates($this->data(2, $this->defaultRate->id))->values->first()->price->format());
    }
}
