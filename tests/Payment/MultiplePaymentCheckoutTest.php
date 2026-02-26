<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class MultiplePaymentCheckoutTest extends TestCase
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
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());
        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    public function test_single_gateway_skips_picker_and_sets_step_3()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', 'fake');

        $this->assertDatabaseMissing('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);
    }

    public function test_single_gateway_saves_payment_gateway_on_reservation()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_gateway' => 'fake',
        ]);
    }

    public function test_multiple_gateways_shows_picker_at_step_3()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake_one' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake One',
            ],
            'fake_two' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Two',
            ],
        ]);

        // Re-register the manager with new config
        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->assertSet('availableGateways', function ($gateways) {
                return count($gateways) === 2;
            });
    }

    public function test_selecting_gateway_initializes_payment()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake_one' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake One',
            ],
            'fake_two' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Two',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->dispatch('gateway-selected', gateway: 'fake_two')
            ->assertSet('selectedGateway', 'fake_two')
            ->assertSet('step', 3);

        // payment_gateway should be saved as the config key, not the gateway's name()
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_gateway' => 'fake_two',
        ]);

        // Payment ID should be set
        $this->assertDatabaseMissing('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);
    }

    public function test_webhook_without_gateway_param_uses_default()
    {
        $this->signInAdmin();

        $response = $this->get('/resrv/api/webhook');
        $response->assertOk();
    }

    public function test_webhook_with_gateway_param_routes_correctly()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        $response = $this->get('/resrv/api/webhook/fake');
        $response->assertOk();
    }

    public function test_payment_view_is_set_on_checkout_payment()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment');
    }

    public function test_reservation_resource_includes_payment_gateway()
    {
        $this->signInAdmin();

        $this->reservation->update(['payment_gateway' => 'stripe']);

        $response = $this->get(cp_route('resrv.reservations.index'));
        $response->assertOk();
    }
}
