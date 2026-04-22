<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;

class CheckoutTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    public $reservation;

    public $extra;

    public $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
        // Override the price here because we are getting it from availability
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

        $this->extra = ResrvExtra::factory()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($this->extra->id);

        $this->options = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create([
                'item_id' => $this->entries->first()->id(),
            ]);
    }

    public function test_renders_successfully()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout')
            ->assertStatus(200);
    }

    public function test_loads_reservation_and_entry()
    {
        session(['resrv_reservation' => $this->reservation->id]);
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $component = Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout');

        $this->assertEquals($this->reservation->id, $component->reservation->id);
        $this->assertEquals($this->reservation->date_start, $component->reservation->date_start);
        $this->assertEquals($this->reservation->quantity, $component->reservation->quantity);
        $this->assertEquals($this->reservation->date_start, $component->reservation->date_start);

        $this->assertEquals($this->entries->first(), $component->entry);
    }

    public function test_it_handles_first_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extras->first()->id => [
                'id' => $extras->first()->id,
                'quantity' => 1,
                'price' => $extras->first()->price->format(),
                'name' => $extras->first()->name,
            ]])
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'price' => '100',
            'total' => '109.30',
        ]);
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $this->extra->id,
            'quantity' => 1,
            'price' => '9.30',
        ]);
    }

    public function test_it_handles_second_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertSet('step', 3);

        $this->assertDatabaseMissing('resrv_reservations', [
            'id' => $this->reservation->id,
            'payment_id' => '',
        ]);
    }

    public function test_it_can_skip_first_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertSet('step', 2)
            ->call('handleSecondStep')
            ->assertSet('step', 3);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'price' => '100',
            'total' => '100.0',
        ]);
    }

    public function test_it_redirects_to_the_checkout_complete_page_if_the_reservation_payment_is_zero()
    {
        Event::fake();

        $reservation = Reservation::factory()->create([
            'price' => '0',
            'payment' => '0',
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleSecondStep')
            ->assertRedirect(Entry::find(Config::get('resrv-config.checkout_completed_entry'))->absoluteUrl().'?payment_pending='.$reservation->id);
    }

    public function test_it_shows_an_arror_if_the_reservation_is_expired()
    {
        $reservation = Reservation::factory()->expired()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired');
    }

    public function test_it_throws_an_error_if_a_user_takes_too_long_in_the_extras_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->call('handleFirstStep')
            ->assertHasErrors('reservation');
    }

    public function test_handle_first_step_time_expired_shows_terminal_error_not_inline()
    {
        // Regression guard: confirmReservationIsValid() touches $this->reservation, which
        // re-fires getReservation() and throws "This reservation has expired" — the exact
        // same message confirmReservationHasNotExpired() throws. If the expiration check
        // runs AFTER validation, the recoverable ReservationException catch swallows the
        // throw and demotes it to an inline banner, leaving $reservationError untouched.
        // The fix reorders so expiration is checked first — this test fails on the old
        // ordering and passes after the fix.
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class, ['enableExtrasStep' => true]);

        $this->travel(30)->minutes();

        $component->call('handleFirstStep')
            ->assertSet('reservationError', 'This reservation has expired. Please start over.')
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired');
    }

    public function test_time_expired_pending_reservation_with_extras_step_disabled_shows_error_and_persists_after_roundtrip()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $this->travel(30)->minutes();

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired')
            ->assertSet('reservationError', 'This reservation has expired. Please start over.')
            ->dispatch('options-updated', [])
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired');
    }

    public function test_time_expired_pending_reservation_cancels_dangling_payment_intent_on_mount()
    {
        // Simulate a reservation that reached step 3 (intent + gateway persisted) and then
        // exceeded minutes_to_hold before the user reopened the checkout.
        $this->reservation->update([
            'payment_id' => 'stale_intent_expired',
            'payment_gateway' => 'fake',
        ]);

        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
            ],
        ]);

        FakePaymentGateway::$cancelledIntents = [];

        session(['resrv_reservation' => $this->reservation->id]);

        $this->travel(30)->minutes();

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired');

        // The dangling intent must be cleared so a late webhook cannot confirm the reservation.
        $this->assertEquals('', Reservation::find($this->reservation->id)->payment_id);

        // Gateway cancel must have actually been invoked — clearing payment_id alone would
        // leave the intent live on Stripe's side and a late .succeeded webhook could still
        // reconcile via metadata.reservation_id.
        $this->assertCount(1, FakePaymentGateway::$cancelledIntents);
        $this->assertEquals('stale_intent_expired', FakePaymentGateway::$cancelledIntents[0]['payment_id']);
        $this->assertEquals($this->reservation->id, FakePaymentGateway::$cancelledIntents[0]['reservation_id']);
    }

    public function test_confirmed_reservation_preserves_payment_id_on_mount()
    {
        // Customer completed payment (status = confirmed, payment_id persisted) and then
        // re-landed on the checkout URL — either via back button or a stale bookmark. The
        // component surfaces the "already confirmed" error, but must NOT clobber payment_id,
        // which downstream refund / reconciliation paths (StripePaymentGateway::refund) read.
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
            ],
        ]);

        $reservation = Reservation::factory()->create([
            'status' => 'confirmed',
            'payment_id' => 'pi_settled_confirmed',
            'payment_gateway' => 'fake',
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation is already confirmed');

        // payment_id must remain intact so later refunds can locate the Stripe intent.
        $this->assertEquals('pi_settled_confirmed', Reservation::find($reservation->id)->payment_id);
        $this->assertEquals('fake', Reservation::find($reservation->id)->payment_gateway);
    }

    public function test_handle_first_step_price_mismatch_shows_inline_error_not_terminal_page()
    {
        // Force validateTotal() to throw a recoverable ReservationException by desyncing
        // the reservation's stored price from what Availability::getPricing() returns for
        // the same dates. This simulates the "price has changed between search and checkout"
        // scenario — a recoverable validation error, not a terminal expiration.
        $this->reservation->update(['price' => '999999.00']);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors('reservation')
            ->assertSet('reservationError', false)
            ->assertViewIs('statamic-resrv::livewire.checkout');
    }

    public function test_it_throws_an_error_if_a_user_takes_too_long_in_the_customer_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->dispatch('checkout-form-submitted')
            ->assertHasErrors('reservation');
    }

    public function test_it_successfully_applies_a_coupon()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20OFF')
            ->assertSet('coupon', '20OFF')
            ->assertSessionHas('resrv_coupon', '20OFF')
            ->assertDispatched('coupon-applied');
    }

    public function test_it_dispatches_coupon_updated_event_on_add()
    {
        Event::fake();

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->dispatch('coupon-applied', $dynamic->coupon);

        Event::assertDispatched(CouponUpdated::class, function ($event) use ($dynamic) {
            return $event->coupon === $dynamic->coupon;
        });
    }

    public function test_it_dispatches_coupon_updated_event_on_remove()
    {
        Event::fake();

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->dispatch('coupon-removed', 'something', true);

        Event::assertDispatched(CouponUpdated::class, function ($event) {
            return $event->remove === true;
        });
    }

    public function test_it_adds_an_error_if_coupon_does_not_exist()
    {
        DynamicPricing::factory()->withCoupon()->create();

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '10OFF')
            ->assertHasErrors(['coupon'])
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertSee('This coupon does not exist');
    }

    public function test_it_adds_an_error_if_coupon_does_not_apply_to_the_product()
    {
        DynamicPricing::factory()->withCoupon()->create();

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20OFF')
            ->assertHasErrors(['coupon'])
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertSee('This coupon does not apply to this product');
    }

    public function test_it_adds_an_error_if_the_coupon_is_invalid()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20%OFF')
            ->assertHasErrors(['coupon'])
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertSee('The coupon code is invalid');
    }

    public function test_it_removes_a_coupon_from_the_session()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20OFF')
            ->assertSet('coupon', '20OFF')
            ->assertSessionHas('resrv_coupon', '20OFF')
            ->assertDispatched('coupon-applied')
            ->call('removeCoupon')
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertDispatched('coupon-removed');
    }

    public function test_it_charges_only_the_reservation_price_when_payment_is_set_to_full()
    {
        Config::set('resrv-config.payment', 'full');

        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extras->first()->id => [
                'id' => $extras->first()->id,
                'quantity' => 1,
                'price' => $extras->first()->price->format(),
                'name' => $extras->first()->name,
            ]])
            ->dispatch('options-updated', [$this->options->first()->id => [
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
                'optionName' => $this->options->first()->name,
                'valueName' => $this->options->first()->values->first()->name,
            ]])
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'price' => '100',
            'payment' => '100',
            'total' => '139.30',
        ]);
    }

    public function test_it_charges_everything_when_payment_is_set_to_everything()
    {
        Config::set('resrv-config.payment', 'everything');

        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extras->first()->id => [
                'id' => $extras->first()->id,
                'quantity' => 1,
                'price' => $extras->first()->price->format(),
                'name' => $extras->first()->name,
            ]])
            ->dispatch('options-updated', [$this->options->first()->id => [
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
                'optionName' => $this->options->first()->name,
                'valueName' => $this->options->first()->values->first()->name,
            ]])
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'price' => '100',
            'payment' => '139.30',
            'total' => '139.30',
        ]);
    }

    public function test_it_charges_everything_when_after_free_cancellation_period()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        Config::set('resrv-config.free_cancellation_period', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', true);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $extras = ResrvExtra::getPriceForDates($reservation);

        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extras->first()->id => [
                'id' => $extras->first()->id,
                'quantity' => 1,
                'price' => $extras->first()->price->format(),
                'name' => $extras->first()->name,
            ]])
            ->dispatch('options-updated', [$this->options->first()->id => [
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
                'optionName' => $this->options->first()->name,
                'valueName' => $this->options->first()->values->first()->name,
            ]])
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'price' => '100',
            'payment' => '139.30',
            'total' => '139.30',
        ]);
    }

    public function test_it_successfully_applies_a_wildcard_coupon()
    {
        $dynamic = DynamicPricing::factory()->withWildcardCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', 'YHCOSAECZC')
            ->assertSet('coupon', 'YHCOSAECZC')
            ->assertSessionHas('resrv_coupon', 'YHCOSAECZC')
            ->assertDispatched('coupon-applied');
    }

    public function test_it_successfully_applies_a_different_wildcard_coupon()
    {
        $dynamic = DynamicPricing::factory()->withWildcardCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', 'YHCOS2UHEQ')
            ->assertSet('coupon', 'YHCOS2UHEQ')
            ->assertSessionHas('resrv_coupon', 'YHCOS2UHEQ')
            ->assertDispatched('coupon-applied');
    }

    public function test_it_rejects_coupon_that_does_not_match_wildcard_prefix()
    {
        $dynamic = DynamicPricing::factory()->withWildcardCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', 'DIFFERENT123')
            ->assertHasErrors(['coupon'])
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertSee('This coupon does not exist');
    }

    public function test_it_prefers_exact_match_over_wildcard_match()
    {
        // Create a wildcard coupon
        $wildcardDynamic = DynamicPricing::factory()->withWildcardCoupon()->create();

        // Create an exact match coupon with the same code that would match the wildcard
        $exactDynamic = DynamicPricing::factory()->withCoupon()->create([
            'coupon' => 'YHCOSTEST',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            [
                'dynamic_pricing_id' => $wildcardDynamic->id,
                'dynamic_pricing_assignment_id' => $this->entries->first()->id,
                'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
            ],
            [
                'dynamic_pricing_id' => $exactDynamic->id,
                'dynamic_pricing_assignment_id' => $this->entries->first()->id,
                'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', 'YHCOSTEST')
            ->assertSet('coupon', 'YHCOSTEST')
            ->assertSessionHas('resrv_coupon', 'YHCOSTEST')
            ->assertDispatched('coupon-applied');
    }

    public function test_wildcard_coupon_works_with_just_prefix()
    {
        $dynamic = DynamicPricing::factory()->withWildcardCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        // Test with just the prefix (no additional characters)
        $component->call('addCoupon', 'YHCOS')
            ->assertSet('coupon', 'YHCOS')
            ->assertSessionHas('resrv_coupon', 'YHCOS')
            ->assertDispatched('coupon-applied');
    }

    public function test_surcharge_is_applied_when_gateway_is_selected()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->dispatch('gateway-selected', gateway: 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());
    }

    public function test_surcharge_is_removed_on_reset()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->dispatch('gateway-selected', gateway: 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());

        $component->call('resetPaymentState');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
    }

    public function test_switching_gateways_updates_surcharge()
    {
        Config::set('resrv-config.payment_gateways', [
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->dispatch('gateway-selected', gateway: 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());

        $component->dispatch('gateway-selected', gateway: 'stripe');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('100.00', $reservation->totalToCharge());
    }

    public function test_single_gateway_auto_applies_surcharge()
    {
        Config::set('resrv-config.payment_gateways', [
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());
    }

    public function test_fixed_surcharge_amount()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'fixed', 'amount' => 5],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->dispatch('gateway-selected', gateway: 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('5.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('105.00', $reservation->totalToCharge());
    }

    public function test_mount_clears_stale_surcharge_before_sidebar_renders()
    {
        Config::set('resrv-config.payment', 'full');
        Config::set('resrv-config.payment_gateways', [
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        // A prior step-3 pass left a non-zero surcharge on the reservation. After a refresh,
        // Livewire's step/selectedGateway are back to defaults, but the sidebar reads straight
        // from the reservation — the stale surcharge must be gone before render so the user
        // doesn't see a gateway fee they haven't re-picked yet.
        $this->reservation->update([
            'payment_surcharge' => '4.00',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class);

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
    }

    public function test_refresh_after_gateway_selection_recovers_from_stale_surcharge_in_full_mode()
    {
        Config::set('resrv-config.payment', 'full');
        Config::set('resrv-config.payment_gateways', [
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        // Simulate a prior step-3 pass: surcharge was applied, then the user refreshed
        // (Livewire state is gone, but payment_surcharge persists in the DB).
        $this->reservation->update([
            'payment_surcharge' => '4.00',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());
    }

    public function test_refresh_after_gateway_selection_recovers_from_stale_surcharge_in_everything_mode()
    {
        Config::set('resrv-config.payment', 'everything');
        Config::set('resrv-config.payment_gateways', [
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        $this->reservation->update([
            'payment_surcharge' => '4.00',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());
    }

    public function test_coupon_applied_after_gateway_selection_recalculates_surcharge()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'percent', 'amount' => 4],
            ],
        ]);

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->dispatch('gateway-selected', gateway: 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('4.00', $reservation->payment_surcharge->format());
        $this->assertEquals('100.00', $reservation->payment->format());
        $this->assertEquals('104.00', $reservation->totalToCharge());

        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('80.00', $reservation->price->format());
        $this->assertEquals('3.20', $reservation->payment_surcharge->format());
        $this->assertEquals('80.00', $reservation->payment->format());
        $this->assertEquals('83.20', $reservation->totalToCharge());
    }

    public function test_coupon_applied_without_gateway_leaves_surcharge_zero()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep');

        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('80.00', $reservation->payment->format());
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
    }

    public function test_it_updates_reservation_total_when_coupon_is_applied()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // First, set initial total via handleFirstStep
        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        // Verify initial total
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'total' => '100.0',
        ]);

        // Add coupon to session (this is what addCoupon() does)
        session(['resrv_coupon' => '20OFF']);

        // Apply coupon
        $component->dispatch('coupon-applied', '20OFF');

        // Refresh reservation from database
        $reservation = Reservation::find($this->reservation->id);

        // The total should now be updated to reflect the coupon discount (80.00)
        $this->assertEquals('80.00', $reservation->total->format());
    }

    public function test_it_updates_reservation_total_when_coupon_is_removed()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // First, set initial total via handleFirstStep
        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        // Add coupon and apply it
        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        // Verify the discounted total
        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('80.00', $reservation->total->format());

        // Now remove the coupon
        session()->forget('resrv_coupon');
        $component->dispatch('coupon-removed', '20OFF', true);

        // Refresh reservation from database
        $reservation = Reservation::find($this->reservation->id);

        // The total should now be updated back to original price (100.00)
        $this->assertEquals('100.00', $reservation->total->format());
    }

    public function test_gateway_is_hidden_when_payment_below_min()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['min' => 200],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $this->assertCount(1, $component->get('availableGateways'));
        $this->assertEquals('paypal', $component->get('availableGateways')[0]['name']);
    }

    public function test_gateway_is_hidden_when_payment_above_max()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['max' => 50],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $this->assertCount(1, $component->get('availableGateways'));
        $this->assertEquals('paypal', $component->get('availableGateways')[0]['name']);
    }

    public function test_single_surviving_gateway_after_filter_auto_selects()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['min' => 500],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'surcharge' => ['type' => 'fixed', 'amount' => 5],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $component->assertSet('selectedGateway', 'paypal');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('5.00', $reservation->payment_surcharge->format());
    }

    public function test_error_when_all_gateways_filtered_out()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['min' => 500],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'amount_limits' => ['min' => 1000],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $component->assertHasErrors('reservation')
            ->assertSet('step', 2)
            ->assertSet('selectedGateway', '');
    }

    public function test_select_gateway_bounces_to_step_2_when_no_gateway_remains()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['min' => 50, 'max' => 200],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'amount_limits' => ['min' => 50, 'max' => 200],
                'surcharge' => ['type' => 'fixed', 'amount' => 5],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->assertSet('step', 3);

        // Simulate the reservation payment changing between render and click so
        // every gateway now exceeds its limits.
        $this->reservation->update(['payment' => '500.00']);

        $component->dispatch('gateway-selected', gateway: 'paypal')
            ->assertHasErrors('reservation')
            ->assertSet('selectedGateway', '')
            ->assertSet('step', 2);

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
        $this->assertEquals('', $reservation->payment_id);
    }

    public function test_select_gateway_auto_selects_when_only_one_remains()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'amount_limits' => ['min' => 50, 'max' => 200],
                'surcharge' => ['type' => 'fixed', 'amount' => 5],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->assertSet('step', 3);

        // Stale change: paypal no longer qualifies, but stripe (no limits) still does.
        $this->reservation->update(['payment' => '500.00']);

        $component->dispatch('gateway-selected', gateway: 'paypal')
            ->assertSet('selectedGateway', 'stripe')
            ->assertSet('step', 3);
    }

    public function test_coupon_component_is_hidden_on_gateway_selection_step()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $couponLabel = __('statamic-resrv::frontend.addCoupon');

        $component = Livewire::test(Checkout::class)
            ->assertSet('step', 1)
            ->assertSee($couponLabel);

        $component->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertSee($couponLabel);

        $component->dispatch('checkout-form-submitted')
            ->assertSet('step', 3)
            ->assertDontSee($couponLabel);
    }

    public function test_select_gateway_cancels_stale_intent_when_bouncing_to_step_2()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['min' => 50, 'max' => 200],
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
                'amount_limits' => ['min' => 50, 'max' => 200],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->assertSet('step', 3);

        // Simulate a stale intent + surcharge carried over from another tab or a browser-back copy
        // of step 3, plus an amount change that disqualifies every gateway.
        $this->reservation->update([
            'payment_id' => 'stale_intent_abc',
            'payment_gateway' => 'stripe',
            'payment_surcharge' => '25.00',
            'payment' => '500.00',
        ]);

        $component->dispatch('gateway-selected', gateway: 'paypal')
            ->assertSet('step', 2);

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('', $reservation->payment_id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
    }

    public function test_select_gateway_cancels_stale_intent_when_keeping_picker_open()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
            'offline' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Offline',
                'amount_limits' => ['max' => 200],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted')
            ->assertSet('step', 3);

        // Stale intent + surcharge + amount change that only disqualifies offline.
        $this->reservation->update([
            'payment_id' => 'stale_intent_xyz',
            'payment_gateway' => 'stripe',
            'payment_surcharge' => '25.00',
            'payment' => '500.00',
        ]);

        $component->dispatch('gateway-selected', gateway: 'offline')
            ->assertHasErrors('reservation')
            ->assertSet('step', 3)
            ->assertSet('selectedGateway', '');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('', $reservation->payment_id);
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
    }

    public function test_amount_limits_compare_payment_not_including_surcharge()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
                'amount_limits' => ['max' => 100],
                'surcharge' => ['type' => 'percent', 'amount' => 10],
            ],
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->dispatch('checkout-form-submitted');

        $component->assertSet('selectedGateway', 'stripe');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('10.00', $reservation->payment_surcharge->format());
    }
}
