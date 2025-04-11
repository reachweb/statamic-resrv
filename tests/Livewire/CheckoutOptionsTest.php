<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Options;
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

    public function setUp(): void
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
            ->create([
                'item_id' => $this->entries->first()->id(),
            ]);
    }

    // Test that it loads and displays options for the entry
    public function test_loads_options_for_the_entry()
    {
        $component = Livewire::test(Options::class, ['reservation' => $this->reservation]);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
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
}
