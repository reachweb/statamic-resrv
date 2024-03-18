<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
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
    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout')
            ->assertStatus(200);
    }

    /** @test */
    public function loads_reservation_entry_checkout_form_computed_properties()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout');

        $reservation = $component->get('reservation');
        $this->assertEquals($this->reservation->id, $reservation->id);
        $this->assertEquals($this->reservation->date_start, $reservation->date_start);
        $this->assertEquals($this->reservation->quantity, $reservation->quantity);
        $this->assertEquals($this->reservation->date_start, $reservation->date_start);

        $entry = $component->get('entry');
        $this->assertEquals($this->entries->first()->toAugmentedArray(), $entry);

        $form = $component->get('checkoutForm');
        $this->assertContains('first_name', $form->first()->toArray());
    }
}
