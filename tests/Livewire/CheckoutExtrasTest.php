<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;

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
        // Override the price here because we are getting it from availability
        $this->reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
        ]);

        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        $this->extras = ResrvExtra::getPriceForDates($this->reservation);
    }

    // Test that extras are correctly loaded for the Reservation
    public function test_it_loads_the_extras_for_the_entry_and_reservation()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->assertSee('This is an extra');

        $this->assertEquals('This is an extra', $component->extras->first()->name);
        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    // Test that extras prices are correctly calculated when Reservation quantity is greater than 1
    public function test_loads_extras_for_the_reservation_with_extra_quantity()
    {
        $extraQuantityReservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'quantity' => 2,
        ]);

        session(['resrv_reservation' => $extraQuantityReservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('18.60', $component->extras->first()->price);
    }

    // Test that extras prices remain the same when ignore_quantity_for_prices config is set to true
    public function test_loads_extras_for_the_reservation_with_extra_quantity_but_same_price_if_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $extraQuantityReservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'quantity' => 2,
        ]);

        session(['resrv_reservation' => $extraQuantityReservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    // Test that selected extras are correctly displayed in the pricing table and final price
    public function test_loads_if_extra_is_selected_we_see_it_in_the_pricing_table_and_the_final_price()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [[
                'id' => $this->extras->first()->id,
                'quantity' => 1,
                'price' => $this->extras->first()->price,
            ]])
            ->assertSee('€ 9.3')
            ->assertSee('109.30');
    }

    // Test that extras relative to a checkout form item are correctly calculated
    public function test_gets_correct_price_for_custom_price_extra()
    {
        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'customer' => ['adults' => 3],
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $extra = ResrvExtra::factory()->custom()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        $extras = ResrvExtra::getPriceForDates($reservation);

        Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [[
                'id' => $extra->id,
                'quantity' => 1,
                'price' => '10.00',
            ]])
            ->assertSee('€ 30');
    }

    // Test that relative price extras are correctly calculated based on the Reservation price
    public function test_gets_correct_price_for_relative_price_extra()
    {
        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'customer' => ['adults' => 3],
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $extra = ResrvExtra::factory()->relative()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        $this->travelTo(today()->subDay()->setHour(12));

        $component = Livewire::test(Checkout::class);

        $this->assertEquals('50.00', $component->extras[1]->price);
    }

    // Test that it handles required extras
    public function test_it_handles_required_extra()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $extra = $this->extras->first();

        ExtraCondition::factory()->requiredAlways()->create([
            'extra_id' => $extra->id,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Test with no extras (should fail)
        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors('reservation');

        // Test with the required extra
        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [
                ['id' => $extra->id,
                    'quantity' => 1,
                    'price' => $extra->price],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation'])
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra->id,
        ]);
    }

    // Test that it handles extras that are required when a specific other extra is selected
    public function test_it_handles_extra_that_is_required_when_another_is_selected()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $item = $this->entries->first();

        $extra = $this->extras->first();
        $extra2 = ResrvExtra::factory()->fixed()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            ['statamicentry_id' => $item->id(), 'extra_id' => $extra2->id],
        ]);

        ExtraCondition::factory()->requiredExtraSelected()->create([
            'extra_id' => $extra2->id,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Test with only the first extra (should faiil because extra2 is required when extra1 is selected)
        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [
                ['id' => $extra->id, 'quantity' => 1, 'price' => $extra->price],
            ])
            ->call('handleFirstStep')
            ->assertHasErrors(['reservation']);

        // Test with required extra (should pass)
        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', [
                ['id' => $extra->id, 'quantity' => 1, 'price' => $extra->price],
                ['id' => $extra2->id, 'quantity' => 1, 'price' => $extra2->price->format()],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation'])
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra->id,
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra2->id,
        ]);
    }
}
