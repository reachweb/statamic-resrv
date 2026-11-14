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
use Reach\StatamicResrv\Tests\Support\QueryReadingPendingGateway;
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
            'no resrv_gateway parameter' => [''],
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

        $victimUrl = $this->entry->absoluteUrl().'?payment_pending='.$victim->id.$gatewayQuery;

        // Anonymous visitor: a fresh session and nothing but a guessed sequential id.
        $this->get($victimUrl)
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('VICTIM');

        // An array id would make Reservation::find() return every listed row.
        $this->get($this->entry->absoluteUrl().'?payment_pending[]='.$victim->id.'&payment_pending[]='.$own->id.$gatewayQuery)
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false);

        // A visitor in the middle of their own checkout must not unlock other ids either.
        session(['resrv_reservation' => $own->id]);

        $this->get($victimUrl)
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('VICTIM');

        // Nor does having completed a checkout: that render moved the session's own reservation
        // to the completed list, which must stay scoped to that one id.
        $this->assertNull(session('resrv_reservation'));

        $this->get($victimUrl)
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('VICTIM');
    }

    #[DataProvider('gateways')]
    public function test_payment_pending_returns_the_sessions_own_reservation_and_survives_a_refresh(string $gatewayQuery)
    {
        $this->configureAllGateways();

        $own = $this->reservation('MYOWN1');

        session(['resrv_reservation' => $own->id]);

        $this->fakeCheckoutCompleteView();

        $url = $this->entry->absoluteUrl().'?payment_pending='.$own->id.$gatewayQuery;

        $this->get($url)->assertOk()->assertSee('[pending][ref:MYOWN1][pi_MYOWN1]', false);

        // The tag ended the checkout session on the first render.
        $this->assertNull(session('resrv_reservation'));

        $this->get($url)->assertOk()->assertSee('[pending][ref:MYOWN1][pi_MYOWN1]', false);
    }

    public static function bundledGateways(): array
    {
        return [
            'stripe' => [StripePaymentGateway::class],
            'offline' => [OfflinePaymentGateway::class],
            'fake' => [FakePaymentGateway::class],
        ];
    }

    // The tag filters the gateway's answer too, so only a direct call shows what the gateway itself returns.
    #[DataProvider('bundledGateways')]
    public function test_a_bundled_gateway_only_returns_the_sessions_own_reservation(string $gatewayClass)
    {
        $victim = $this->reservation('VICTIM');
        $own = $this->reservation('MYOWN1');

        session(['resrv_reservation' => $own->id]);

        $gateway = new $gatewayClass;

        $this->assertFalse($gateway->handlePaymentPending());

        request()->query->set('payment_pending', (string) $victim->id);
        $this->assertSame(['status' => 'pending', 'reservation' => []], $gateway->handlePaymentPending());
        $this->assertSame(['status' => 'pending', 'reservation' => []], $gateway->handleRedirectBack());

        request()->query->set('payment_pending', (string) $own->id);
        $pending = $gateway->handlePaymentPending();
        $this->assertSame('pending', $pending['status']);
        $this->assertSame('MYOWN1', $pending['reservation']['reference']);
    }

    public function test_a_request_body_cannot_hide_the_query_id_from_the_tag()
    {
        Config::set('resrv-config.payment_gateways', [
            'queryreader' => ['class' => QueryReadingPendingGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $victim = $this->reservation('VICTIM');
        $own = $this->reservation('MYOWN1');

        $this->fakeCheckoutCompleteView();

        $url = $this->entry->absoluteUrl().'?payment_pending='.$victim->id.'&resrv_gateway=queryreader';

        // The body names the visitor's own reservation while the gateway reads the query string:
        // the tag must judge the row that came back, not the id it was shown.
        session(['resrv_reservation' => $own->id]);
        $this->json('GET', $url, ['payment_pending' => $own->id])
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('VICTIM');

        session(['resrv_reservation' => $own->id]);
        $this->post($url, ['payment_pending' => $own->id])
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('VICTIM');
    }

    public function test_a_provider_return_keeps_its_reservation_unless_the_request_names_a_pending_payment()
    {
        $paid = $this->reservation('PAID01');

        // Stands in for a redirect gateway that found the booking through the provider's own id.
        $gateway = Mockery::mock(FakePaymentGateway::class)->makePartial();
        $gateway->shouldReceive('handleRedirectBack')->andReturn(['status' => true, 'reservation' => $paid->toArray()]);

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('gateway')->andReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->fakeCheckoutCompleteView();

        $this->get($this->entry->absoluteUrl().'?token=provider-issued')
            ->assertOk()
            ->assertSee('[success][ref:PAID01][pi_PAID01]', false);

        // The same return with a `payment_pending` bolted on is held to the session rule.
        $this->get($this->entry->absoluteUrl().'?token=provider-issued&payment_pending='.$paid->id)
            ->assertOk()
            ->assertSee('[success][ref:][][n:0]', false)
            ->assertDontSee('PAID01');
    }

    public function test_every_reservation_the_session_completed_stays_readable_for_an_hour()
    {
        $this->travelTo(today()->setTime(12, 0));

        $first = $this->reservation('FIRST1');
        $second = $this->reservation('SECND2');

        $this->fakeCheckoutCompleteView();

        $firstUrl = $this->entry->absoluteUrl().'?payment_pending='.$first->id;
        $secondUrl = $this->entry->absoluteUrl().'?payment_pending='.$second->id;

        session(['resrv_reservation' => $first->id]);
        $this->get($firstUrl)->assertSee('[ref:FIRST1]', false);

        $this->travel(30)->minutes();

        session(['resrv_reservation' => $second->id]);
        $this->get($secondUrl)->assertSee('[ref:SECND2]', false);

        // A second booking does not take the first one's confirmation page away.
        $this->get($firstUrl)->assertSee('[ref:FIRST1]', false);

        // 61 minutes after the first booking completed, 31 after the second.
        $this->travel(31)->minutes();

        $this->get($firstUrl)->assertSee('[pending][ref:][][n:0]', false)->assertDontSee('FIRST1');
        $this->get($secondUrl)->assertSee('[ref:SECND2]', false);
    }

    public function test_the_checkout_complete_page_is_never_served_from_the_static_cache()
    {
        Config::set('statamic.static_caching.strategy', 'half');

        $own = $this->reservation('MYOWN1');

        $this->fakeCheckoutCompleteView();

        $url = $this->entry->absoluteUrl().'?payment_pending='.$own->id;

        session(['resrv_reservation' => $own->id]);
        $this->get($url)
            ->assertOk()
            ->assertSee('[ref:MYOWN1]', false)
            ->assertHeader('X-Statamic-Uncacheable');

        // Another visitor asks for the same URL: a cached copy would answer without running the tag.
        session()->flush();
        $this->get($url)
            ->assertOk()
            ->assertSee('[pending][ref:][][n:0]', false)
            ->assertDontSee('MYOWN1');
    }

    protected function configureAllGateways(): void
    {
        Config::set('resrv-config.payment_gateways', [
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
        // The count shows data the two named fields would miss, such as a list of rows.
        $this->viewShouldReturnRaw('default', '{{ resrv_checkout_redirect }}[{{ status }}][ref:{{ reservation:reference }}][{{ reservation:payment_id }}][n:{{ reservation | length }}]{{ /resrv_checkout_redirect }}');
    }
}
