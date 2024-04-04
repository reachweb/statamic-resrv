<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
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

        $component = Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->assertViewIs('statamic-resrv::livewire.checkout-form')
            ->assertViewHas('form', fn ($data) => array_key_exists('first_name', $data));

        $this->assertNotNull($component->checkoutForm);
    }

    /** @test */
    public function renders_successfully_and_preloads_custom_data()
    {
        // Fake the AvailabilityForm data
        $availabilityForm = new \stdClass;
        $availabilityForm->dates = [];
        $availabilityForm->quantity = 1;
        $availabilityForm->advanced = null;
        $availabilityForm->custom = ['email' => 'larry@david.com'];

        session(['resrv_reservation' => $this->reservation->id]);
        session(['resrv-search' => $availabilityForm]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->assertViewIs('statamic-resrv::livewire.checkout-form')
            ->assertViewHas('form', fn ($data) => $data['email'] === 'larry@david.com');

        $this->assertNotNull($component->checkoutForm);
    }

    /** @test */
    public function checkout_form_validation_works()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->set('form', ['first_name' => '', 'last_name' => 'Superman'])
            ->call('submit')
            ->assertHasErrors('form.first_name')
            ->assertHasNoErrors('form.last_name');
    }

    /** @test */
    public function checkout_form_saves_customer()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->set('form', [
                'first_name' => 'Jerry',
                'last_name' => 'Seinfeld',
                'email' => 'about@nothing.com',
                'repeat_email' => 'about@nothing.com',
                'phone' => '1234567890',
            ])
            ->call('submit')
            ->assertDispatchedTo(Checkout::class, 'checkout-form-submitted')
            ->assertHasNoErrors('form.last_name');

        $this->assertDatabaseHas('resrv_reservations', [
            'customer->first_name' => 'Jerry',
            'customer->last_name' => 'Seinfeld',
        ]);
    }
}
