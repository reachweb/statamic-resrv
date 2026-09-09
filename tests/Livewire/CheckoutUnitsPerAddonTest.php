<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Livewire\Options;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;

/**
 * Cruise fixture used throughout: a 'double' independent rate (100/night, stock 5) and a
 * 'single' relative (-25%) shared rate with units_per_addon = 2 whose stock lives on the base
 * rate. Port taxes are a fixed extra at 350. Every reservation spans 2 nights (today → +2).
 *
 * A single-rate reservation at quantity 2 books 2 berths for 1 guest, so add-ons are charged
 * once: ceil(2 / 2) = 1. A double-rate reservation at quantity 2 keeps today's behaviour (×2).
 */
class CheckoutUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $entry;

    public $double;

    public $single;

    public $portTaxes;

    public $solo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setHour(12));

        $this->createCabinEntry();

        // 1 guest on the single rate: 2 berths booked (quantity 2), 75 × 2 nights × 2 = 300.
        $this->solo = Reservation::factory()->withRate($this->single->id)->create([
            'item_id' => $this->entry->id(),
            'quantity' => 2,
            'price' => '300.00',
            'payment' => '300.00',
        ]);
    }

    // Single rate, quantity 2, divisor 2 → port taxes charged once: 350 × ceil(2 / 2) = 350.
    public function test_extras_component_divides_the_booked_quantity_for_a_single_rate_reservation()
    {
        $component = Livewire::test(Extras::class, ['reservation' => $this->solo]);

        $this->assertEquals('350.00', $component->extras->firstWhere('id', $this->portTaxes->id)->price);
    }

    // Double rate has no divisor, so quantity 2 still multiplies: 350 × 2 = 700.
    public function test_extras_component_keeps_multiplying_for_a_rate_without_a_divisor()
    {
        $component = Livewire::test(Extras::class, ['reservation' => $this->createDoubleSibling()]);

        $this->assertEquals('700.00', $component->extras->firstWhere('id', $this->portTaxes->id)->price);
    }

    /**
     * The global ignore_quantity_for_prices flag wins over the divisor: with it on, add-ons are
     * never multiplied, so both the single (divisor 2) and the double (no divisor) reservation
     * show the bare 350.00 rather than 350.00 / 700.00.
     */
    public function test_ignore_quantity_flag_wins_over_the_divisor_in_the_extras_component()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $single = Livewire::test(Extras::class, ['reservation' => $this->solo]);
        $double = Livewire::test(Extras::class, ['reservation' => $this->createDoubleSibling()]);

        $this->assertEquals('350.00', $single->extras->firstWhere('id', $this->portTaxes->id)->price);
        $this->assertEquals('350.00', $double->extras->firstWhere('id', $this->portTaxes->id)->price);
    }

    // Fixed option value 30: single → 30 × ceil(2 / 2) = 30; double → 30 × 2 = 60.
    public function test_options_component_divides_the_booked_quantity_for_fixed_values()
    {
        $option = Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $this->entry->id()]);

        $single = Livewire::test(Options::class, ['reservation' => $this->solo]);
        $double = Livewire::test(Options::class, ['reservation' => $this->createDoubleSibling()]);

        $this->assertEquals($option->id, $single->options->first()->id);
        $this->assertEquals('30.00', $single->options->first()->values->first()->price->format());
        $this->assertEquals('60.00', $double->options->first()->values->first()->price->format());
    }

    // Per-day option value 22.75 over 2 nights: single → 45.50 × 1; double → 45.50 × 2 = 91.00.
    public function test_options_component_divides_the_booked_quantity_for_perday_values()
    {
        Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $this->entry->id()]);

        $single = Livewire::test(Options::class, ['reservation' => $this->solo]);
        $double = Livewire::test(Options::class, ['reservation' => $this->createDoubleSibling()]);

        $this->assertEquals('45.50', $single->options->first()->values->first()->price->format());
        $this->assertEquals('91.00', $double->options->first()->values->first()->price->format());
    }

    /**
     * Party of three: double child (quantity 2, no divisor) + single child (quantity 2, divisor 2).
     * Each child prices add-ons under its own rate: 350 × 2 + 350 × 1 = 1050 (old behaviour 1400).
     */
    public function test_extras_component_sums_parent_children_under_their_own_divisors()
    {
        $component = Livewire::test(Extras::class, ['reservation' => $this->createPartyOfThree()]);

        $this->assertEquals('1050.00', $component->extras->firstWhere('id', $this->portTaxes->id)->price);
    }

    // Fixed option value 30 across the party of three: 30 × 2 + 30 × 1 = 90 (old behaviour 120).
    public function test_options_component_sums_parent_children_under_their_own_divisors()
    {
        Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $this->entry->id()]);

        $component = Livewire::test(Options::class, ['reservation' => $this->createPartyOfThree()]);

        $this->assertEquals('90.00', $component->options->first()->values->first()->price->format());
    }

    // Step 1 re-prices the extra under the single rate's divisor: 300 + 350 × 1 = 650.
    public function test_first_step_accepts_the_divided_extra_total_for_a_single_rate_reservation()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        session(['resrv_reservation' => $this->solo->id]);

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$this->portTaxes->id => [
                'id' => $this->portTaxes->id,
                'quantity' => 1,
                'price' => '350.00',
                'name' => 'Port taxes',
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation', 'extras'])
            ->assertSet('step', 2);

        $this->assertEquals('650.00', $this->solo->fresh()->total->format());

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->solo->id,
            'extra_id' => $this->portTaxes->id,
            'quantity' => 1,
            'price' => '350.00',
        ]);
    }

    // A client still sending the undivided 700 (350 × quantity 2) drifts from the server's 650.
    public function test_first_step_rejects_the_undivided_extra_total_for_a_single_rate_reservation()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        session(['resrv_reservation' => $this->solo->id]);

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$this->portTaxes->id => [
                'id' => $this->portTaxes->id,
                'quantity' => 1,
                'price' => '700.00',
                'name' => 'Port taxes',
            ]])
            ->call('handleFirstStep')
            ->assertHasErrors(['reservation'])
            ->assertSet('step', 1);

        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => $this->solo->id,
            'extra_id' => $this->portTaxes->id,
        ]);
    }

    /**
     * Parent validation re-prices the extra per child under each child's rate:
     * double 350 × 2 + single 350 × 1 = 1050, on top of 400 + 300 = 700 for the cabins → 1750.
     */
    public function test_first_step_accepts_the_per_child_divided_extra_total_for_a_parent()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $parent = $this->createPartyOfThree();

        session(['resrv_reservation' => $parent->id]);

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$this->portTaxes->id => [
                'id' => $this->portTaxes->id,
                'quantity' => 1,
                'price' => '1050.00',
                'name' => 'Port taxes',
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation', 'extras'])
            ->assertSet('step', 2);

        $fresh = $parent->fresh();

        $this->assertEquals('1750.00', $fresh->total->format());
        $this->assertEquals('1050.00', $fresh->extraCharges()->format());

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $parent->id,
            'extra_id' => $this->portTaxes->id,
            'quantity' => 1,
            'price' => '1050.00',
        ]);
    }

    // The old per-berth total (350 × 4 = 1400) no longer matches the per-child 1050.
    public function test_first_step_rejects_the_undivided_extra_total_for_a_parent()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $parent = $this->createPartyOfThree();

        session(['resrv_reservation' => $parent->id]);

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$this->portTaxes->id => [
                'id' => $this->portTaxes->id,
                'quantity' => 1,
                'price' => '1400.00',
                'name' => 'Port taxes',
            ]])
            ->call('handleFirstStep')
            ->assertHasErrors(['reservation'])
            ->assertSet('step', 1);

        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => $parent->id,
            'extra_id' => $this->portTaxes->id,
        ]);
    }

    // Required fixed option (30) across the party of three: 30 × 2 + 30 × 1 = 90 → total 790.
    public function test_first_step_accepts_the_per_child_divided_option_total_for_a_parent()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $option = Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $this->entry->id()]);
        $value = $option->values->first();

        $parent = $this->createPartyOfThree();

        session(['resrv_reservation' => $parent->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [$option->id => [
                'id' => $option->id,
                'value' => $value->id,
                'price' => '90.00',
                'optionName' => $option->name,
                'valueName' => $value->name,
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation', 'options'])
            ->assertSet('step', 2);

        $this->assertEquals('790.00', $parent->fresh()->total->format());

        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => $parent->id,
            'option_id' => $option->id,
            'value' => $value->id,
        ]);
    }

    private function createCabinEntry(): void
    {
        $this->entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'double',
            title: 'Cruise cabin',
        );

        $this->double = Rate::forEntry($this->entry->id())->where('slug', 'double')->first();

        // Stock lives on the base (double) rate; the single rate is priced at -25% of it.
        $this->single = Rate::factory()->relative()->shared()->unitsPerAddon(2)->create([
            'collection' => 'cabins',
            'title' => 'Single',
            'slug' => 'single',
            'base_rate_id' => $this->double->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $this->portTaxes = ResrvExtra::factory()->fixed()->create([
            'id' => 7,
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '350',
        ]);

        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($this->portTaxes->id);
    }

    // 2 guests on the double rate: quantity 2, 100 × 2 nights × 2 = 400.
    private function createDoubleSibling(): Reservation
    {
        return Reservation::factory()->withRate($this->double->id)->create([
            'reference' => 'DOUBLE',
            'item_id' => $this->entry->id(),
            'quantity' => 2,
            'price' => '400.00',
            'payment' => '400.00',
        ]);
    }

    // Party of three: double at quantity 2 (400) + single at quantity 2 (300) → parent 700.
    private function createPartyOfThree(): Reservation
    {
        $parent = Reservation::factory()->create([
            'type' => 'parent',
            'reference' => 'PARTY3',
            'item_id' => $this->entry->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 4,
            'price' => '700.00',
            'payment' => '700.00',
        ]);

        ChildReservation::factory()->withRate($this->double->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 2,
            'price' => '400.00',
        ]);

        ChildReservation::factory()->withRate($this->single->id)->create([
            'reservation_id' => $parent->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 2,
            'price' => '300.00',
        ]);

        return $parent;
    }
}
