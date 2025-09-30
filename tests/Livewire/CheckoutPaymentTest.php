<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\CheckoutPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class CheckoutPaymentTest extends TestCase
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
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    public function test_renders_successfully()
    {
        Livewire::test(CheckoutPayment::class)
            ->assertViewIs('statamic-resrv::livewire.checkout-payment')
            ->assertViewHas('checkoutCompletedUrl', function ($checkoutCompletedUrl) {
                return $checkoutCompletedUrl === Entry::find(Config::get('resrv-config.checkout_completed_entry'))->absoluteUrl();
            });
    }
}
