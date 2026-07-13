<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Events\ReservationCreated as ReservationCreatedEvent;
use Reach\StatamicResrv\Listeners\AddAffiliateToReservation;
use Reach\StatamicResrv\Livewire\Traits\HandlesAffiliates;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateFrontTest extends TestCase
{
    use RefreshDatabase;

    public $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = $this->makeStatamicItem();
        $this->withStandardFakeViews();
    }

    public function test_that_it_sets_cookie_if_code_in_url()
    {
        $affiliate = Affiliate::factory()->create();

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $affiliate->code);
    }

    public function test_that_it_does_not_set_cookie_if_code_if_wrong()
    {
        Affiliate::factory()->create();

        $response = $this->get('/'.$this->item->slug.'/?afid=SOMETHINGWRONG');
        $response->assertStatus(200)->assertCookieMissing('resrv_afid');
    }

    public function test_that_it_does_overwrite_a_previously_set_cookie()
    {
        $affiliate = Affiliate::factory()->create();
        $newAffiliate = Affiliate::factory()->create(['code' => 'NEWCODE']);

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $affiliate->code);

        $response = $this->get('/'.$this->item->slug.'?afid='.$newAffiliate->code);
        $response->assertStatus(200)->assertCookie('resrv_afid', $newAffiliate->code);
    }

    // An unpublished affiliate is disabled, so the cookie must not be set for it.
    public function test_that_it_does_not_set_cookie_for_an_unpublished_affiliate()
    {
        $affiliate = Affiliate::factory()->create(['published' => false]);

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookieMissing('resrv_afid');
    }

    public function test_get_affiliate_if_cookie_exists_ignores_unpublished_affiliates()
    {
        Affiliate::factory()->create(['code' => 'DISABLED', 'published' => false]);
        request()->cookies->set('resrv_afid', 'DISABLED');

        $component = new class
        {
            use HandlesAffiliates;
        };

        $this->assertNull($component->getAffiliateIfCookieExists());
    }

    public function test_get_affiliate_if_cookie_exists_returns_a_published_affiliate()
    {
        $affiliate = Affiliate::factory()->create(['code' => 'ENABLED', 'published' => true]);
        request()->cookies->set('resrv_afid', 'ENABLED');

        $component = new class
        {
            use HandlesAffiliates;
        };

        $this->assertEquals($affiliate->id, $component->getAffiliateIfCookieExists()?->id);
    }

    // With the affiliate system disabled, the ?afid= parameter must be ignored entirely.
    public function test_it_does_not_set_cookie_when_affiliates_are_disabled()
    {
        Config::set('resrv-config.enable_affiliates', false);

        $affiliate = Affiliate::factory()->create();

        $response = $this->get('/'.$this->item->slug.'?afid='.$affiliate->code);
        $response->assertStatus(200)->assertCookieMissing('resrv_afid');
    }

    // A cookie set before the toggle was flipped off must be ignored at the read too,
    // so no attribution can happen from pre-existing cookies.
    public function test_get_affiliate_if_cookie_exists_returns_null_when_affiliates_are_disabled()
    {
        Config::set('resrv-config.enable_affiliates', false);

        Affiliate::factory()->create(['code' => 'ENABLED', 'published' => true]);
        request()->cookies->set('resrv_afid', 'ENABLED');

        $component = new class
        {
            use HandlesAffiliates;
        };

        $this->assertNull($component->getAffiliateIfCookieExists());
    }

    public function test_listener_does_not_attribute_affiliate_when_affiliates_are_disabled()
    {
        Config::set('resrv-config.enable_affiliates', false);

        $affiliate = Affiliate::factory()->create();
        $reservation = Reservation::factory()->create();

        (new AddAffiliateToReservation)->handle(new ReservationCreatedEvent($reservation, new ReservationData(
            affiliate: $affiliate,
        )));

        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_listener_listens_to_reservation_created_event()
    {
        Event::fake();

        $reservation = Reservation::factory()->create();

        event(new ReservationCreatedEvent($reservation));

        Event::assertListening(
            ReservationCreatedEvent::class,
            AddAffiliateToReservation::class,
        );
    }
}
