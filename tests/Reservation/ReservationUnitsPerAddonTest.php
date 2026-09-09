<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Exceptions\ReservationDriftException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

/**
 * Reservation-side aggregation of add-on prices under Rate::units_per_addon.
 *
 * Cruise fixture: a 'double' independent rate (100/night) and a 'single' occupancy rate
 * (relative -25%, shared stock on the base rate) with units_per_addon = 2 — a single
 * occupancy cabin is booked as 2 berths for 1 guest. "Port taxes" is a fixed 350 extra.
 *
 * Party of three = parent with a double child (quantity 2) and a single child (quantity 2):
 *   port taxes  350 × 2            (double)  + 350 × ceil(2 / 2)         (single) = 1050 (old: 1400)
 *   cabins      100 × 2 nights × 2 (double)  + 75 × 2 nights × 2         (single) = 700
 */
class ReservationUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    private $entry;

    private Rate $double;

    private Rate $single;

    private ResrvExtra $portTaxes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));

        $this->createCabinEntry();
    }

    private function createCabinEntry(): void
    {
        $this->entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'double',
        );

        $this->double = Rate::forEntry($this->entry->id())->where('slug', 'double')->first();

        $this->single = Rate::factory()->relative()->shared()->unitsPerAddon(2)->create([
            'collection' => 'cabins',
            'slug' => 'single',
            'title' => 'Single occupancy',
            'base_rate_id' => $this->double->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $this->portTaxes = ResrvExtra::factory()->fixed()->create([
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '350',
        ]);

        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($this->portTaxes->id);
    }

    private function createNormalReservation(Rate $rate): Reservation
    {
        return Reservation::factory()->withRate($rate->id)->create([
            'item_id' => $this->entry->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 2,
            'price' => $rate->is($this->single) ? '300.00' : '400.00',
            'payment' => $rate->is($this->single) ? '300.00' : '400.00',
        ]);
    }

    private function createPartyOfThree(array $attributes = []): Reservation
    {
        $reservation = Reservation::factory()->create(array_merge([
            'type' => 'parent',
            'item_id' => $this->entry->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 4,
            'price' => '700.00',
            'payment' => '700.00',
        ], $attributes));

        foreach ([$this->double, $this->single] as $rate) {
            ChildReservation::factory()->withRate($rate->id)->create([
                'reservation_id' => $reservation->id,
                'date_start' => today()->toIso8601String(),
                'date_end' => today()->addDays(2)->toIso8601String(),
                'quantity' => 2,
            ]);
        }

        return $reservation;
    }

    private function attachFixedOption(Reservation $reservation): array
    {
        $option = Option::factory()->create(['item_id' => $this->entry->id()]);
        $value = OptionValue::factory()->fixed()->create(['option_id' => $option->id]);

        $reservation->options()->attach($option->id, ['value' => $value->id]);

        return [$option, $value];
    }

    private function checkoutData(Reservation $reservation, string $total, array $overrides = []): array
    {
        return array_merge([
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
            'payment' => $reservation->payment,
            'price' => $reservation->price,
            'total' => Price::create($total),
            'extras' => collect(),
            'options' => collect(),
            'customer' => collect(),
        ], $overrides);
    }

    private function portTaxesPayload(string $price, int $quantity = 1): Collection
    {
        return collect([[
            'id' => $this->portTaxes->id,
            'quantity' => $quantity,
            'price' => $price,
            'name' => 'Port taxes',
        ]]);
    }

    public function test_normal_reservation_on_divisor_rate_charges_extra_once()
    {
        $reservation = $this->createNormalReservation($this->single);
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);

        // 2 berths / units_per_addon 2 = 1 add-on unit: 350 × 1
        $this->assertEquals('350.00', $reservation->extraCharges()->format());
        $this->assertNotEquals('700.00', $reservation->extraCharges()->format());
    }

    public function test_normal_reservation_on_plain_rate_keeps_multiplying_extra_by_quantity()
    {
        $reservation = $this->createNormalReservation($this->double);
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);

        // No divisor on the double rate: 350 × 2
        $this->assertEquals('700.00', $reservation->extraCharges()->format());
    }

    public function test_validate_total_accepts_divided_extra_total_on_normal_reservation()
    {
        $reservation = $this->createNormalReservation($this->single);

        // Cabin 75 × 2 nights × 2 = 300, port taxes 350 × ceil(2 / 2) = 350
        $data = $this->checkoutData($reservation, '650.00', [
            'extras' => $this->portTaxesPayload('350.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));
    }

    public function test_validate_total_rejects_undivided_extra_total_on_normal_reservation()
    {
        $reservation = $this->createNormalReservation($this->single);

        // The old 350 × 2 = 700 extras figure (cabin 300 + 700 = 1000) is drift now.
        $data = $this->checkoutData($reservation, '1000.00', [
            'extras' => $this->portTaxesPayload('700.00'),
        ]);

        $this->expectException(ReservationDriftException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_parent_extra_charges_divide_quantity_per_child_rate()
    {
        $reservation = $this->createPartyOfThree();
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);

        // Double child: 350 × 2 = 700; single child: 350 × ceil(2 / 2) = 350
        $this->assertEquals('1050.00', $reservation->extraCharges()->format());
        $this->assertNotEquals('1400.00', $reservation->extraCharges()->format());
    }

    public function test_validate_total_accepts_divided_extras_on_parent()
    {
        $reservation = $this->createPartyOfThree();

        // Cabins 400 + 300 = 700, port taxes 700 + 350 = 1050
        $data = $this->checkoutData($reservation, '1750.00', [
            'extras' => $this->portTaxesPayload('1050.00'),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));
    }

    public function test_validate_total_rejects_undivided_extras_on_parent()
    {
        $reservation = $this->createPartyOfThree();

        // The old 350 × 4 = 1400 extras figure (700 + 1400 = 2100) is drift now.
        $data = $this->checkoutData($reservation, '2100.00', [
            'extras' => $this->portTaxesPayload('1400.00'),
        ]);

        $this->expectException(ReservationDriftException::class);
        $reservation->validateTotal($data, $this->entry->id());
    }

    public function test_validate_reservation_full_path_passes_on_parent()
    {
        // The parent path checks the children's summed quantity (4) against the global maximum.
        $this->assertGreaterThanOrEqual(4, config('resrv-config.maximum_quantity'));

        $reservation = $this->createPartyOfThree();

        $data = $this->checkoutData($reservation, '1750.00', [
            'extras' => $this->portTaxesPayload('1050.00'),
        ]);

        $this->assertTrue($reservation->validateReservation($data, $this->entry->id()));
    }

    public function test_parent_fixed_option_divides_quantity_per_child_rate()
    {
        $reservation = $this->createPartyOfThree();
        $this->attachFixedOption($reservation);

        // Fixed value 30: double child 30 × 2 = 60; single child 30 × ceil(2 / 2) = 30
        $this->assertEquals('90.00', $reservation->extraCharges()->format());
        $this->assertNotEquals('120.00', $reservation->extraCharges()->format());
    }

    public function test_parent_perday_option_divides_quantity_per_child_rate()
    {
        $reservation = $this->createPartyOfThree();

        $option = Option::factory()->create(['item_id' => $this->entry->id()]);
        $value = OptionValue::factory()->create(['option_id' => $option->id]);
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        // Per-day value 22.75 × 2 nights = 45.50: double child × 2 = 91.00; single child × 1 = 45.50
        $this->assertEquals('136.50', $reservation->extraCharges()->format());
        $this->assertNotEquals('182.00', $reservation->extraCharges()->format());
    }

    public function test_parent_extras_and_fixed_option_sum_and_validate_with_divisor()
    {
        $reservation = $this->createPartyOfThree();
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);
        [$option, $value] = $this->attachFixedOption($reservation);

        // Port taxes 1050 + fixed option 90
        $this->assertEquals('1140.00', $reservation->extraCharges()->format());

        // Cabins 700 + add-ons 1140
        $data = $this->checkoutData($reservation, '1840.00', [
            'extras' => $this->portTaxesPayload('1050.00'),
            'options' => collect([[
                'id' => $option->id,
                'value' => $value->id,
                'price' => '90.00',
                'optionName' => $option->name,
                'valueName' => $value->name,
            ]]),
        ]);

        $this->assertTrue($reservation->validateTotal($data, $this->entry->id()));
    }

    public function test_parent_selected_extra_quantity_multiplies_before_the_divisor()
    {
        $reservation = $this->createPartyOfThree();
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 2, 'price' => '0']]);

        // Selected qty 2: double child 350 × 2 × 2 = 1400; single child 350 × 2 × ceil(2 / 2) = 700
        $this->assertEquals('2100.00', $reservation->extraCharges()->format());
        $this->assertNotEquals('2800.00', $reservation->extraCharges()->format());
    }

    /**
     * unitsPerAddonFor() resolves withTrashed so a historical reservation keeps pricing its
     * add-ons under the divisor of the rate it was booked with after that rate is deleted.
     */
    public function test_deleted_divisor_rate_still_prices_historical_parent_add_ons()
    {
        $reservation = $this->createPartyOfThree();
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);

        $this->single->delete();

        $this->assertTrue($this->single->fresh()->trashed());
        $this->assertEquals('1050.00', Reservation::find($reservation->id)->extraCharges()->format());
    }

    public function test_parent_custom_extra_divides_customer_multiplied_price_per_child_rate()
    {
        $customExtra = ResrvExtra::factory()->custom()->create();
        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($customExtra->id);

        $reservation = $this->createPartyOfThree([
            'customer_id' => Customer::factory()->withGuests(3)->create()->id,
        ]);
        $reservation->extras()->sync([$customExtra->id => ['quantity' => 1, 'price' => '0']]);

        // 10 per adult × 3 adults = 30: double child × 2 = 60; single child × ceil(2 / 2) = 30
        $this->assertEquals('90.00', $reservation->extraCharges()->format());
        $this->assertNotEquals('120.00', $reservation->extraCharges()->format());
    }

    /**
     * The global ignore_quantity_for_prices flag wins over the divisor: add-ons are charged
     * once per child regardless of quantity, so the divisor never re-introduces a multiplier.
     */
    public function test_ignore_quantity_flag_wins_over_divisor_on_parent()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $reservation = $this->createPartyOfThree();
        $reservation->extras()->sync([$this->portTaxes->id => ['quantity' => 1, 'price' => '0']]);

        // 350 (double child) + 350 (single child), no quantity multiplier on either
        $this->assertEquals('700.00', $reservation->extraCharges()->format());
    }
}
