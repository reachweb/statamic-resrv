<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Mockery;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class ResrvCheckoutRedirectTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    public $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));

        $this->entry = Entry::make()
            ->collection('pages')
            ->slug('checkout-completed')
            ->data(['title' => 'Checkout Completed']);

        $this->entry->save();

        Config::set('resrv-config.checkout_completed_entry', $this->entry->id());
    }

    public function test_resolves_gateway_from_reservation_session()
    {
        Config::set('resrv-config.payment_gateways', [
            'gateway_a' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Gateway A',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'payment_gateway' => 'gateway_a',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $this->withFakeViews();
        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ resrv_checkout_redirect }}{{ status }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl())
            ->assertOk()
            ->assertSee('failed');
    }

    public function test_falls_back_to_default_gateway()
    {
        $this->withFakeViews();
        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ resrv_checkout_redirect }}{{ status }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl())
            ->assertOk()
            ->assertSee('failed');
    }

    public function test_reservation_gateway_takes_priority_over_query_param()
    {
        Config::set('resrv-config.payment_gateways', [
            'gateway_a' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Gateway A',
            ],
            'gateway_b' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Gateway B',
            ],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'payment_gateway' => 'gateway_a',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $fakeGateway = new FakePaymentGateway;

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')
            ->once()
            ->with(Mockery::on(fn ($r) => $r->id === $reservation->id))
            ->andReturn($fakeGateway);
        // gateway() with 'gateway_b' should not be called because forReservation succeeds first
        $manager->shouldNotReceive('gateway')->with('gateway_b');

        app()->instance(PaymentGatewayManager::class, $manager);

        $this->withFakeViews();
        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ resrv_checkout_redirect }}{{ status }}{{ /resrv_checkout_redirect }}');

        $this->get($this->entry->absoluteUrl().'?resrv_gateway=gateway_b')
            ->assertOk()
            ->assertSee('failed');
    }
}
