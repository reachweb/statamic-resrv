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

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();

        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
    }

    public function test_renders_successfully_and_loads_form_and_form_data()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->assertViewIs('statamic-resrv::livewire.checkout-form')
            ->assertViewHas('form', fn ($data) => array_key_exists('first_name', $data));

        $this->assertNotNull($component->checkoutForm);
    }

    public function test_renders_successfully_and_preloads_custom_data()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->assertViewIs('statamic-resrv::livewire.checkout-form')
            ->assertViewHas('form', fn ($data) => $data['email'] === $this->reservation->customer->email);

        $this->assertNotNull($component->checkoutForm);
    }

    public function test_checkout_form_validation_works()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        Livewire::test(CheckoutForm::class, ['reservation' => $this->reservation])
            ->set('form', ['first_name' => '', 'last_name' => 'Superman'])
            ->call('submit')
            ->assertHasErrors('form.first_name')
            ->assertHasNoErrors('form.last_name');
    }

    public function test_checkout_form_saves_customer()
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

        $this->assertDatabaseHas('resrv_customers', [
            'data->first_name' => 'Jerry',
            'data->last_name' => 'Seinfeld',
        ]);
    }
}
