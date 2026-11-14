<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\Support\UncheckedPendingGateway;
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

    public static function gateways(): array
    {
        return [
            'default gateway' => [''],
            'stripe' => ['&resrv_gateway=stripe'],
            'offline' => ['&resrv_gateway=offline'],
            'fake' => ['&resrv_gateway=fake'],
            'third-party gateway with an unchecked lookup' => ['&resrv_gateway=unchecked'],
        ];
    }

    #[DataProvider('gateways')]
    public function test_payment_pending_does_not_expose_a_reservation_the_session_does_not_own(string $gatewayQuery)
    {
        $this->configureAllGateways();

        $victim = $this->reservation('VICTIM');
        $own = $this->reservation('MYOWN1');

        $this->fakeCheckoutCompleteView();

        // Anonymous visitor: a fresh session and nothing but a guessed sequential id.
        $this->get($this->entry->absoluteUrl().'?payment_pending='.$victim->id.$gatewayQuery)
            ->assertOk()
            ->assertSee('[pending]')
            ->assertSee('[ref:]', false)
            ->assertDontSee('VICTIM')
            ->assertDontSee('pi_VICTIM');

        // An array id would make Reservation::find() return every listed row.
        $this->get($this->entry->absoluteUrl().'?payment_pending[]='.$victim->id.'&payment_pending[]='.$own->id.$gatewayQuery)
            ->assertOk()
            ->assertDontSee('VICTIM')
            ->assertDontSee('MYOWN1');

        // A visitor in the middle of their own checkout must not unlock other ids either.
        session(['resrv_reservation' => $own->id]);

        $this->get($this->entry->absoluteUrl().'?payment_pending='.$victim->id.$gatewayQuery)
            ->assertOk()
            ->assertSee('[ref:]', false)
            ->assertDontSee('VICTIM')
            ->assertDontSee('pi_VICTIM');
    }

    #[DataProvider('gateways')]
    public function test_payment_pending_returns_the_sessions_own_reservation_and_survives_a_refresh(string $gatewayQuery)
    {
        $this->configureAllGateways();

        $own = $this->reservation('MYOWN1');

        session(['resrv_reservation' => $own->id]);

        $this->fakeCheckoutCompleteView();

        $url = $this->entry->absoluteUrl().'?payment_pending='.$own->id.$gatewayQuery;

        $this->get($url)->assertOk()->assertSee('[pending]')->assertSee('[ref:MYOWN1]', false);

        // The tag ended the checkout session on the first render.
        $this->assertNull(session('resrv_reservation'));

        $this->get($url)->assertOk()->assertSee('[pending]')->assertSee('[ref:MYOWN1]', false);
    }

    protected function configureAllGateways(): void
    {
        Config::set('resrv-config.payment_gateways', [
            'default' => ['class' => FakePaymentGateway::class],
            'stripe' => ['class' => StripePaymentGateway::class],
            'offline' => ['class' => OfflinePaymentGateway::class],
            'fake' => ['class' => FakePaymentGateway::class],
            'unchecked' => ['class' => UncheckedPendingGateway::class],
        ]);

        app()->forgetInstance(PaymentGatewayManager::class);
    }

    protected function reservation(string $reference): Reservation
    {
        return Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'reference' => $reference,
            'payment_id' => 'pi_'.$reference,
        ]);
    }

    protected function fakeCheckoutCompleteView(): void
    {
        $this->withFakeViews();
        $this->viewShouldReturnRaw('layout', '{{ template_content }}');
        $this->viewShouldReturnRaw('default', '{{ resrv_checkout_redirect }}[{{ status }}][ref:{{ reservation:reference }}][{{ reservation:payment_id }}]{{ /resrv_checkout_redirect }}');
    }
}
