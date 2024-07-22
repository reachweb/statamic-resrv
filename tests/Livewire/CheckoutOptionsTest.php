<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
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
            'item_id' => $this->entries->first()->id(),
        ]);

        $this->options = Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->create([
                'item_id' => $this->entries->first()->id(),
            ]);
    }

    /** @test */
    public function loads_options_for_the_entry()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
    }

    /** @test */
    public function loads_options_for_the_entry_with_correct_price_for_extra_quantity()
    {
        $reservation = Reservation::factory()->create([
            'quantity' => 2,
            'item_id' => $this->entries->first()->id(),
        ]);
        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('91.00', $component->options->first()->values->first()->price->format());
    }

    /** @test */
    public function loads_options_for_the_entry_with_extra_quantity_but_same_price_if_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $reservation = Reservation::factory()->create([
            'quantity' => 2,
            'item_id' => $this->entries->first()->id(),
        ]);
        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('Reservation option', $component->options->first()->name);
        $this->assertEquals('45.50', $component->options->first()->values->first()->price->format());
    }

    /** @test */
    public function loads_options_list_in_the_view()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->assertSee($this->options->first()->name)
            ->assertSee($this->options->first()->values->first()->name);
    }

    /** @test */
    public function loads_if_option_is_selected_we_see_it_in_the_pricing_table_and_the_final_price()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->set('enabledOptions.options', [[
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
            ]])
            ->assertSee($this->options->first()->name.': <span class="font-medium">'.$this->options->first()->values->first()->name, false)
            ->assertSee('222.75');
    }
}
