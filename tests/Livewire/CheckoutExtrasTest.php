<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\CheckoutExtras;
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

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(CheckoutExtras::class, ['reservationId' => $this->reservation->id])
            ->assertViewIs('statamic-resrv::livewire.checkout-extras')
            ->assertStatus(200);
    }

    /** @test */
    public function it_loads_the_extras_for_the_entry()
    {
        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        // We need to do this because of the way we get the extra data in the Livewire component
        $extra = json_decode($extra->toJson());

        $component = Livewire::test(CheckoutExtras::class, ['reservationId' => $this->reservation->id])
            ->assertViewIs('statamic-resrv::livewire.checkout-extras');

        // This price are for this reservation only
        $this->assertEquals('9.30', $component->extras->first()->price);
        $this->assertEquals('perday', $component->extras->first()->price_type);
    }

    /** @test */
    public function it_listens_to_the_extra_changed_event_and_changed_the_enabled_extras_array()
    {

        $component = Livewire::test(CheckoutExtras::class, ['reservationId' => $this->reservation->id])
            ->dispatch('extra-changed', [
                'id' => 1,
                'price' => 4.65,
                'quantity' => 1,
            ])
            ->assertSet('enabledExtras', collect([0 => [
                'id' => 1,
                'price' => 4.65,
                'quantity' => 1,
            ]])
            )
            ->dispatch('extra-changed', [
                 'id' => 1,
                 'price' => 4.65,
                 'quantity' => 0,
             ])
            ->assertSet('enabledExtras', collect()
            );
    }
}
