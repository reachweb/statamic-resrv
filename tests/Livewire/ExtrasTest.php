<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;

class ExtrasTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    public $reservation;

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

    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(Extras::class, ['reservationId' => $this->reservation->id])
            ->assertViewIs('statamic-resrv::livewire.checkout-extras')
            ->assertStatus(200);
    }

    /** @test */
    public function it_loads_the_extras_for_the_entry()
    {
        $component = Livewire::test(Extras::class, ['reservationId' => $this->reservation->id])
            ->assertViewIs('statamic-resrv::livewire.checkout-extras');

        $this->assertNotNull($component->extras);
    }

   
}
