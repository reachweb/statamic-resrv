<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\CheckoutForm;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;

class CheckoutFormTest extends TestCase
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

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
    }

    /** @test */
    public function renders_successfully_and_loads_form_and_form_data()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(CheckoutForm::class, ['checkoutForm' => $this->reservation->getCheckoutForm()->toArray()])
            ->assertViewIs('statamic-resrv::livewire.checkout-form')
            ->assertViewHas('checkoutForm')
            ->assertViewHas('form', fn ($data) => array_key_exists('first_name', $data));
    }
}
