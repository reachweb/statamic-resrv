<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\CheckoutOptions;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class CheckoutOptionsTest extends TestCase
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
        Livewire::test(CheckoutOptions::class, ['options' => collect([])])
            ->assertViewIs('statamic-resrv::livewire.checkout-options')
            ->assertStatus(200);
    }

    // /** @test */
    // public function it_loads_the_extras_for_the_entry_and_reservation()
    // {
    //     $extra = ResrvExtra::factory()->create();

    //     DB::table('resrv_statamicentry_extra')->insert([
    //         'statamicentry_id' => $this->entries->first()->id,
    //         'extra_id' => $extra->id,
    //     ]);

    //     $extras = ResrvExtra::getPriceForDates($this->reservation);

    //     Livewire::test(CheckoutExtras::class, ['extras' => $extras, 'enabledExtras' => collect([])])
    //         ->assertViewIs('statamic-resrv::livewire.checkout-extras')
    //         ->assertViewHas('extras', fn ($extras) => $extras->first()->price == '9.30');
    // }

    // /** @test */
    // public function it_listens_to_the_extra_changed_event_and_changes_the_enabled_extras_array()
    // {
    //     $component = Livewire::test(CheckoutExtras::class, ['enabledExtras' => collect([]), 'extras' => collect([])])
    //         ->dispatch('extra-changed', [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 1,
    //         ])
    //         ->assertSet('enabledExtras', collect([0 => [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 1,
    //         ]])
    //         )
    //         ->dispatch('extra-changed', [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 0,
    //         ])
    //         ->assertSet('enabledExtras', collect())
    //         ->dispatch('extra-changed', [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 3,
    //         ])
    //         ->assertSet('enabledExtras', collect([0 => [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 3,
    //         ]])
    //         )
    //         ->dispatch('extra-changed', [
    //             'id' => 1,
    //             'price' => 4.65,
    //             'quantity' => 0,
    //         ])
    //         ->assertSet('enabledExtras', collect());
    // }
}
