<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;

class CheckoutTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    public $reservation;

    public $extra;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
        Config::set('resrv-config.checkout_completed_entry', $entry->id());

        $this->extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $this->extra->id,
        ]);
    }

    /** @test */
    public function renders_successfully()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout')
            ->assertStatus(200);
    }

    /** @test */
    public function loads_reservation_and_entry()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout');

        $this->assertEquals($this->reservation->id, $component->reservation->id);
        $this->assertEquals($this->reservation->date_start, $component->reservation->date_start);
        $this->assertEquals($this->reservation->quantity, $component->reservation->quantity);
        $this->assertEquals($this->reservation->date_start, $component->reservation->date_start);

        $this->assertEquals($this->entries->first(), $component->entry);
    }

    /** @test */
    public function it_handles_first_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras', collect([0 => [
                'id' => $this->extra->id,
                'price' => $extras->first()->price,
                'quantity' => 1,
            ]]))
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'price' => '200',
            'total' => '209.30',
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $this->extra->id,
            'quantity' => 1,
            'price' => '9.30',
        ]);
    }

    /** @test */
    public function it_handles_second_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3);

        $this->assertDatabaseMissing('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);
    }

    /** @test */
    public function it_throws_exception_if_the_reservation_is_expired()
    {
        $reservation = Reservation::factory()->expired()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->expectException(\Illuminate\View\ViewException::class);

        Livewire::test(Checkout::class);
    }

    /** @test */
    public function it_throws_an_error_if_a_user_takes_too_long_in_the_extras_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->call('handleFirstStep')
            ->assertHasErrors('reservation');
    }

    /** @test */
    public function it_throws_an_error_if_a_user_takes_too_long_in_the_customer_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->dispatch('checkout-form-submitted')
            ->assertHasErrors('reservation');
    }
}
