<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\Support\FakeRedirectGateway;
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

    public function test_redirect_gateway_in_normal_checkout_returns_to_the_checkout_complete_entry()
    {
        $this->useGateways(['fakeredirect' => ['class' => FakeRedirectGateway::class, 'label' => 'Fake Redirect']]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertRedirect();

        // Normal checkout is unchanged: the gateway's return-URL base is the checkout-complete entry.
        $gateway = app(PaymentGatewayManager::class)->gateway('fakeredirect');

        $this->assertSame(
            Entry::find(Config::get('resrv-config.checkout_completed_entry'))->absoluteUrl(),
            $gateway->lastReturnUrl,
        );
    }

    public function test_single_gateway_skips_picker_and_sets_step_3()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', 'fake');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertNotNull($reservation->payment_id);
        $this->assertNotSame('', $reservation->payment_id);
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
        $this->useGateways([
            'fake_one' => ['label' => 'Fake One'],
            'fake_two' => ['label' => 'Fake Two'],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->assertSet('availableGateways', function ($gateways) {
                return count($gateways) === 2;
            });
    }

    public function test_selecting_gateway_initializes_payment()
    {
        $this->useGateways([
            'fake_one' => ['label' => 'Fake One'],
            'fake_two' => ['label' => 'Fake Two'],
        ]);

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
        $reservation = Reservation::find($this->reservation->id);
        $this->assertNotNull($reservation->payment_id);
        $this->assertNotSame('', $reservation->payment_id);
    }

    public function test_resubmitting_second_step_clears_stale_payment_state()
    {
        $this->useGateways([
            'fake_one' => ['label' => 'Fake One'],
            'fake_two' => ['label' => 'Fake Two'],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // First pass: select a gateway so payment state gets populated
        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->dispatch('gateway-selected', gateway: 'fake_one')
            ->assertSet('selectedGateway', 'fake_one')
            ->assertNotSet('clientSecret', '')
            ->assertNotSet('paymentView', '');

        // Simulate navigating back and resubmitting the checkout form
        $component
            ->call('goToStep', 2)
            ->call('handleSecondStep')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '')
            ->assertSet('clientSecret', '')
            ->assertSet('paymentView', '');
    }

    public function test_webhook_without_gateway_param_uses_default()
    {
        $response = $this->get('/resrv/api/webhook');
        $response->assertOk();
    }

    public function test_webhook_with_gateway_param_routes_correctly()
    {
        $this->useGateways(['fake' => ['label' => 'Fake']]);

        $response = $this->get('/resrv/api/webhook/fake');
        $response->assertOk();
    }

    // With multiple gateways configured, each provider posts to its own /webhook/{gateway} URL, so a
    // bare URL can't be routed to a specific gateway and must be rejected rather than handed to the
    // default — which would otherwise process (here, confirm) the reservation as the wrong gateway.
    public function test_bare_webhook_url_is_rejected_in_multi_gateway_mode()
    {
        $this->withExceptionHandling();

        $this->useGateways([
            'fake_one' => ['label' => 'Fake One'],
            'fake_two' => ['label' => 'Fake Two'],
        ]);

        $this->post('/resrv/api/webhook', [
            'reservation_id' => $this->reservation->id,
            'status' => 'success',
        ])->assertNotFound();

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);
    }

    // A single payment_gateways entry is single-gateway mode, so the bare URL must reach that
    // gateway — not the legacy payment_gateway binding (Stripe by default, the fake gateway here).
    public function test_bare_webhook_url_reaches_the_only_configured_gateway()
    {
        $this->useGateways(['offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank transfer']]);

        $this->post('/resrv/api/webhook', [
            'reservation_id' => $this->reservation->id,
            'status' => 'success',
        ])->assertOk();

        // The fake gateway would confirm this reservation; the offline gateway ignores webhooks.
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);
    }

    public function test_payment_view_is_set_on_checkout_payment()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
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

    public function test_selecting_invalid_gateway_returns_error()
    {
        $this->useGateways(['fake_one' => ['label' => 'Fake One']]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->dispatch('gateway-selected', gateway: 'nonexistent')
            ->assertHasErrors('reservation');
    }

    public function test_webhook_post_with_invalid_gateway_returns_404()
    {
        $this->withExceptionHandling();

        $response = $this->post('/resrv/api/webhook/nonexistent');
        $response->assertNotFound();
    }

    public function test_refund_uses_reservation_payment_gateway()
    {
        $this->signInAdmin();

        $this->useGateways([
            'fake_one' => ['label' => 'Fake One'],
            'fake_two' => ['label' => 'Fake Two'],
        ]);

        $this->reservation->update([
            'payment_gateway' => 'fake_two',
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
}
