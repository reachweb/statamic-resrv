<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class CheckoutExtrasTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    public $extras;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        $this->extras = ResrvExtra::getPriceForDates($this->reservation);
    }

    /** @test */
    public function it_loads_the_extras_for_the_entry_and_reservation()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->assertSee('This is an extra');

        $this->assertEquals('This is an extra', $component->extras->first()->name);
        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    /** @test */
    public function loads_extras_for_the_reservation_with_extra_quantity()
    {
        $extraQuantityReservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'quantity' => 2,
        ]);

        session(['resrv_reservation' => $extraQuantityReservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('18.60', $component->extras->first()->price);
    }

    /** @test */
    public function loads_if_extra_is_selected_we_see_it_in_the_pricing_table_and_the_final_price()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [[
                'id' => $this->extras->first()->id,
                'quantity' => 1,
                'price' => $this->extras->first()->price,
            ]])
            ->assertSee('€ 9.3')
            ->assertSee('209.30');
    }
}
