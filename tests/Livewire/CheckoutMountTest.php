<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class CheckoutMountTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    public $checkoutEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));

        $this->checkoutEntry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);
        $this->checkoutEntry->save();

        Config::set('resrv-config.checkout_entry', $this->checkoutEntry->id());
        Config::set('resrv-config.checkout_completed_entry', $this->checkoutEntry->id());
    }

    public function test_mount_with_confirmed_reservation_redirects_to_checkout_complete_entry()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_already_confirmed',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertRedirect();
    }

    public function test_mount_with_confirmed_reservation_does_not_wipe_payment_fields()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_preserve_me',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class);

        $fresh = $reservation->fresh();
        $this->assertEquals('pi_preserve_me', $fresh->payment_id, 'mount() must not clear payment_id on a CONFIRMED reservation — downstream refund relies on it');
        $this->assertEquals('fake', $fresh->payment_gateway, 'mount() must not clear payment_gateway on a CONFIRMED reservation');
    }

    public function test_mount_with_partner_reservation_redirects_and_preserves_payment_fields()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'partner',
            'payment_id' => 'pi_partner_preserve',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertRedirect();

        $fresh = $reservation->fresh();
        $this->assertEquals('pi_partner_preserve', $fresh->payment_id);
        $this->assertEquals('fake', $fresh->payment_gateway);
    }

    public function test_mount_with_refunded_reservation_redirects_and_preserves_payment_fields()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'refunded',
            'payment_id' => 'pi_refunded_preserve',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertRedirect();

        $fresh = $reservation->fresh();
        $this->assertEquals('pi_refunded_preserve', $fresh->payment_id);
        $this->assertEquals('fake', $fresh->payment_gateway);
    }

    public function test_mount_with_expired_reservation_shows_terminal_error()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'expired',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertSet('reservationError', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_mount_with_pending_reservation_proceeds_normally()
    {
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'pending',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertSet('reservationError', false)
            ->assertNoRedirect();
    }
}
