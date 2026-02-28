<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\CheckoutPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class OfflinePaymentGatewayTest extends TestCase
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

    // --- Unit tests for the gateway class ---

    public function test_implements_payment_interface()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertInstanceOf(PaymentInterface::class, $gateway);
    }

    public function test_name_returns_offline()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertEquals('offline', $gateway->name());
    }

    public function test_label_returns_default_label()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertNotEmpty($gateway->label());
    }

    public function test_payment_view_returns_offline_view()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertEquals('statamic-resrv::livewire.checkout-payment-offline', $gateway->paymentView());
    }

    public function test_payment_intent_returns_valid_structure()
    {
        $gateway = new OfflinePaymentGateway;
        $intent = $gateway->paymentIntent($this->reservation->payment, $this->reservation, []);

        $this->assertIsObject($intent);
        $this->assertStringStartsWith('offline_', $intent->id);
        $this->assertStringStartsWith('offline_', $intent->client_secret);
    }

    public function test_refund_returns_true()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertTrue($gateway->refund($this->reservation));
    }

    public function test_supports_webhooks_returns_false()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertFalse($gateway->supportsWebhooks());
    }

    public function test_redirects_for_payment_returns_false()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertFalse($gateway->redirectsForPayment());
    }

    public function test_get_public_key_returns_empty_string()
    {
        $gateway = new OfflinePaymentGateway;
        $this->assertSame('', $gateway->getPublicKey($this->reservation));
    }

    // --- Integration tests (Livewire checkout flow) ---

    public function test_offline_gateway_initializes_correctly_in_checkout()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', 'offline')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment-offline');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertStringStartsWith('offline_', $reservation->payment_id);
        $this->assertEquals('offline', $reservation->payment_gateway);
    }

    public function test_offline_gateway_in_multi_gateway_picker()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->assertSet('availableGateways', function ($gateways) {
                return count($gateways) === 2
                    && $gateways[0]['name'] === 'fake'
                    && $gateways[1]['name'] === 'offline';
            })
            ->dispatch('gateway-selected', gateway: 'offline')
            ->assertSet('selectedGateway', 'offline')
            ->assertSet('paymentView', 'statamic-resrv::livewire.checkout-payment-offline');
    }

    public function test_confirm_payment_dispatches_reservation_confirmed()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        Event::assertDispatched(ReservationConfirmed::class, function ($event) {
            return $event->reservation->id === $this->reservation->id;
        });
    }

    public function test_confirm_payment_redirects_to_checkout_completed()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        $checkoutEntry = \Statamic\Facades\Entry::query()->where('slug', 'checkout')->first();

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect($checkoutEntry->absoluteUrl().'?payment_pending='.$this->reservation->id);
    }

    public function test_confirm_payment_prevents_double_confirmation()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['status' => 'confirmed', 'payment_gateway' => 'offline']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        Event::assertNotDispatched(ReservationConfirmed::class);
    }

    public function test_confirm_payment_rejects_null_payment_gateway()
    {
        $this->reservation->update(['payment_gateway' => null]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_rejects_non_offline_gateway()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'fake']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'test',
            'publicKey' => 'test',
            'amount' => 100.00,
            'paymentView' => '',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_confirm_payment_works_with_custom_offline_gateway_key()
    {
        Event::fake([ReservationConfirmed::class]);

        Config::set('resrv-config.payment_gateways', [
            'bank_transfer' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update(['payment_gateway' => 'bank_transfer']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertRedirect();

        Event::assertDispatched(ReservationConfirmed::class);
    }

    public function test_confirm_payment_rejects_expired_reservation()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'created_at' => now()->subHours(2),
            'status' => 'expired',
            'payment_gateway' => 'offline',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(CheckoutPayment::class, [
            'clientSecret' => 'offline_test',
            'publicKey' => '',
            'amount' => 100.00,
            'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline',
        ])
            ->call('confirmPayment')
            ->assertHasErrors('reservation');
    }

    public function test_offline_refund_succeeds_in_cp()
    {
        $this->signInAdmin();

        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        $this->reservation->update([
            'payment_gateway' => 'offline',
            'status' => 'confirmed',
        ]);

        $response = $this->patch(cp_route('resrv.reservation.refund'), [
            'id' => $this->reservation->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'refunded',
        ]);
    }

    public function test_offline_gateway_payment_gateway_saved_on_reservation()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_gateway' => 'offline',
        ]);
    }
}
