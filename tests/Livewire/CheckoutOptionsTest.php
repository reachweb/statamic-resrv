<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Options;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class CheckoutOptionsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    public $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
        ]);

        $this->options = Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->forEntry($this->entries->first()->id())
            ->create();
    }

    // Test that it loads and displays options for the entry
    public function test_loads_options_for_the_entry()
    {
        $component = Livewire::test(Options::class, ['reservation' => $this->reservation]);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
    }

    // selectOption is a public client-callable action; an unknown option or value id must be
    // ignored rather than dereferencing null and throwing a 500.
    public function test_select_option_ignores_unknown_option_id_without_erroring()
    {
        Livewire::test(Options::class, ['reservation' => $this->reservation])
            ->call('selectOption', 99999, 88888)
            ->assertHasNoErrors()
            ->assertNotDispatched('options-updated');
    }

    public function test_select_option_ignores_unknown_value_id_without_erroring()
    {
        Livewire::test(Options::class, ['reservation' => $this->reservation])
            ->call('selectOption', $this->options->id, 88888)
            ->assertHasNoErrors()
            ->assertNotDispatched('options-updated');
    }

    // The inverse of Option::values() must resolve back to the parent Option.
    public function test_option_value_belongs_to_its_option()
    {
        $value = $this->options->values->first();

        $this->assertInstanceOf(Option::class, $value->option);
        $this->assertEquals($this->options->id, $value->option->id);
    }

    // An unknown price_type (legacy/tampered data) must fall back to the base price instead of
    // returning null and fataling at the ->format() call in priceForDates().
    public function test_calculate_price_falls_back_to_base_price_for_unknown_price_type()
    {
        $value = OptionValue::factory()->create([
            'option_id' => $this->options->id,
            'price' => '15',
            'price_type' => 'bogus',
        ]);

        $data = [
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ];

        $this->assertEquals('15.00', $value->priceForDates($data));
    }

    // Test that it loads options list in the view
    public function test_loads_options_list_in_the_view()
    {
        Livewire::test(Options::class, ['reservation' => $this->reservation])
            ->assertSee($this->options->first()->name)
            ->assertSee($this->options->first()->values->first()->name);
    }

    // Test that it loads and displays options for the entry with correct price for extra quantity
    public function test_loads_options_for_the_entry_with_correct_price_for_extra_quantity()
    {
        $reservation = Reservation::factory()->create([
            'quantity' => 2,
            'item_id' => $this->entries->first()->id(),
        ]);

        $component = Livewire::test(Options::class, ['reservation' => $reservation]);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('91.00', $component->options->first()->values->first()->price->format());
    }

    // Test that it loads and displays options for the entry with extra quantity but same price if configured
    public function test_loads_options_for_the_entry_with_extra_quantity_but_same_price_if_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $reservation = Reservation::factory()->create([
            'quantity' => 2,
            'item_id' => $this->entries->first()->id(),
        ]);

        $component = Livewire::test(Options::class, ['reservation' => $reservation]);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
    }

    // Test that if an option is selected we see it in the pricing table and the final price
    public function test_loads_if_option_is_selected_we_see_it_in_the_pricing_table_and_the_final_price()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class, ['reservation' => $this->reservation])
            ->dispatch('options-updated', [$this->options->first()->id => [
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
                'optionName' => $this->options->first()->name,
                'valueName' => $this->options->first()->values->first()->name,
            ]])
            ->assertSee($this->options->first()->name.': <span class="font-medium">'.$this->options->first()->values->first()->name, false)
            ->assertSee('22.75');
    }

    // Test that it gives an error at step 1 if a required option is not selected
    public function test_it_gives_an_error_if_required_option_is_not_selected()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $option = Option::find($this->options->first()->id)->valuesPriceForDates($this->reservation);

        // Test without any options (should fail)
        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors('options');

        // Test with a value selected for the option
        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [$option->id => [
                'id' => $option->id,
                'value' => $option->values->first()->id,
                'price' => $option->values->first()->price->format(),
                'optionName' => $option->name,
                'valueName' => $option->values->first()->name,
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors('options')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => $this->reservation->id,
            'option_id' => $option->id,
            'value' => $option->values[0]->id,
        ]);
    }

    // A tampered payload that submits a required, paid option with a non-existent value id at a
    // zero price must be rejected at step 1 — the invalid value cannot be priced as free and synced.
    public function test_it_rejects_a_required_option_submitted_with_an_invalid_value()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        // setUp's option is the only required option. Submitting its id satisfies the
        // required-option check, but at a zero price with a value that does not belong to it —
        // the bypass we are guarding against. Base price (100) == reservation price, so under the
        // buggy zero-pricing the total would match and the option would sync free.
        $option = $this->options->first();

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [$option->id => [
                'id' => $option->id,
                'value' => 99999, // value id that does not belong to this option
                'price' => '0.00',
                'optionName' => $option->name,
                'valueName' => 'Tampered',
            ]])
            ->call('handleFirstStep')
            ->assertHasErrors('options')
            ->assertSet('step', 1);

        // The option must not have been synced at no charge.
        $this->assertDatabaseMissing('resrv_reservation_option', [
            'reservation_id' => $this->reservation->id,
            'option_id' => $option->id,
        ]);
    }

    public function test_parent_option_prices_sum_per_child_dates()
    {
        // Default OptionValue factory: perday at 22.75/day
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $this->entries->first()->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: 2 days, qty 1
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: 1 day, qty 1
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(4)->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 1,
        ]);

        $component = Livewire::test(Options::class, ['reservation' => $reservation]);

        // Per-day option at 22.75/day:
        // Child 1: 22.75 * 2 days * 1 qty = 45.50
        // Child 2: 22.75 * 1 day  * 1 qty = 22.75
        // Total: 68.25
        // NOT: 22.75 * 5 days * 2 qty = 227.50 (parent collapsed dates + total qty)
        $this->assertEquals('68.25', $component->options->first()->values->first()->price);
    }
}
