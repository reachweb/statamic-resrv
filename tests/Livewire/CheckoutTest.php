<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Reach\StatamicResrv\Enums\AffiliateAttributionSource;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Affiliate;
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

    public function test_get_updated_prices_is_memoised_until_the_reservation_reloads()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $checkout = Livewire::test(Checkout::class)->instance();

        // Mirrors the unset($this->reservation) that every price-changing action performs.
        unset($checkout->reservation);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $checkout->getUpdatedPrices();
        $firstCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $second = $checkout->getUpdatedPrices();
        $secondCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $firstCount, 'first call after a reservation reload must compute prices from the DB');
        $this->assertSame(0, $secondCount, 'subsequent calls within the request must reuse the memoised prices, not re-query');
        $this->assertEquals($first, $second);
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

    public function test_first_step_does_not_advance_when_assigning_extras_fails()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extras->first()->id => [
                'id' => $extras->first()->id,
                'quantity' => 1,
                'price' => $extras->first()->price->format(),
                'name' => $extras->first()->name,
            ]]);

        // Simulate a database failure during the extras sync (e.g. deadlock/constraint).
        // The flow must surface the error and stay on step 1 rather than advancing to
        // payment with a total that doesn't match the (unattached) extras.
        Schema::drop('resrv_reservation_extra');

        $component->call('handleFirstStep')
            ->assertSet('step', 1)
            ->assertHasErrors('extras');
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

    public function test_zero_payment_checkout_surfaces_error_when_reservation_expires_during_the_transition_race()
    {
        // Race: the row clears the PENDING pre-check, then expires before transitionTo() locks it —
        // the zero-payment path must surface the expired error, not redirect as if it went through.
        $reservation = Reservation::factory()->create([
            'price' => '0',
            'payment' => '0',
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $confirmedDispatched = false;
        Event::listen(ReservationConfirmed::class, function () use (&$confirmedDispatched) {
            $confirmedDispatched = true;
        });

        // Mount first so the component's initial load isn't what trips the hook below.
        $component = Livewire::test(Checkout::class);

        // Expire the row when transitionTo() opens its transaction (after the component's pre-checks).
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use ($reservation, &$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            DB::table('resrv_reservations')
                ->where('id', $reservation->id)
                ->update(['status' => ReservationStatus::EXPIRED->value]);
        });

        $component->call('handleSecondStep')
            ->assertNoRedirect()
            ->assertSet('reservationError', fn ($value) => is_string($value) && $value !== '');

        $this->assertEquals('expired', $reservation->fresh()->status);
        $this->assertFalse($confirmedDispatched, 'ReservationConfirmed must not fire when the confirm transition lost the race.');
    }

    public function test_without_payment_affiliate_checkout_surfaces_error_when_reservation_expires_during_the_transition_race()
    {
        // Race parity for the affiliate skip-payment (PARTNER) path: must surface the expired error
        // rather than redirecting as if the affiliate booking was confirmed.
        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $affiliate = Affiliate::factory()->create(['allow_skipping_payment' => true]);
        $reservation->affiliate()->attach($affiliate->id, ['fee' => 0]);

        session(['resrv_reservation' => $reservation->id]);

        $confirmedDispatched = false;
        Event::listen(ReservationConfirmed::class, function () use (&$confirmedDispatched) {
            $confirmedDispatched = true;
        });

        // Mount first so the component's initial load isn't what trips the hook below.
        $component = Livewire::test(Checkout::class);

        // Expire the row when transitionTo() opens its transaction (after the component's pre-checks).
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use ($reservation, &$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            DB::table('resrv_reservations')
                ->where('id', $reservation->id)
                ->update(['status' => ReservationStatus::EXPIRED->value]);
        });

        $component->dispatch('checkout-form-submitted-without-payment')
            ->assertNoRedirect()
            ->assertSet('reservationError', fn ($value) => is_string($value) && $value !== '');

        $this->assertEquals('expired', $reservation->fresh()->status);
        $this->assertFalse($confirmedDispatched, 'ReservationConfirmed must not fire when the PARTNER transition lost the race.');
    }

    public function test_affiliate_attributed_by_cookie_can_complete_checkout_without_payment()
    {
        Event::fake();

        $affiliate = Affiliate::factory()->create(['allow_skipping_payment' => true]);
        $this->reservation->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertSee(trans('statamic-resrv::frontend.completeWithoutPayment'));

        $component->dispatch('checkout-form-submitted-without-payment')
            ->assertRedirect(Entry::find(Config::get('resrv-config.checkout_completed_entry'))->absoluteUrl().'?payment_pending='.$this->reservation->id);

        $this->assertEquals(ReservationStatus::PARTNER->value, $this->reservation->fresh()->status);
    }

    public function test_affiliate_cannot_skip_payment_when_affiliates_are_disabled()
    {
        // The attribution predates the toggle flip: history is kept, but skip-payment is off.
        Config::set('resrv-config.enable_affiliates', false);

        $affiliate = Affiliate::factory()->create(['allow_skipping_payment' => true]);
        $this->reservation->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // The skip-payment button must not render on the checkout form
        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertDontSee(trans('statamic-resrv::frontend.completeWithoutPayment'));

        // A forged Livewire dispatch must be rejected server-side, not just hidden in the UI
        $component->dispatch('checkout-form-submitted-without-payment')
            ->assertNoRedirect()
            ->assertHasErrors('reservation');

        $this->assertEquals(ReservationStatus::PENDING->value, $this->reservation->fresh()->status);
    }

    public function test_entering_an_affiliate_coupon_does_not_unlock_skip_payment()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $affiliate = Affiliate::factory()->create(['allow_skipping_payment' => true]);
        $affiliate->coupons()->sync([$dynamic->id]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20OFF')
            ->assertSessionHas('resrv_coupon', '20OFF')
            ->dispatch('coupon-applied', '20OFF');

        // The coupon attributed the affiliate for commission, marked as coupon-sourced
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $this->reservation->id,
            'affiliate_id' => $affiliate->id,
            'source' => AffiliateAttributionSource::Coupon->value,
        ]);

        // The skip-payment button must not render on the checkout form
        $component->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertDontSee(trans('statamic-resrv::frontend.completeWithoutPayment'));

        // A forged Livewire dispatch must be rejected server-side, not just hidden in the UI
        $component->dispatch('checkout-form-submitted-without-payment')
            ->assertNoRedirect()
            ->assertHasErrors('reservation');

        $this->assertEquals(ReservationStatus::PENDING->value, $this->reservation->fresh()->status);
    }

    public function test_an_unpublished_affiliates_coupon_still_discounts_but_earns_no_attribution()
    {
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $affiliate = Affiliate::factory()->create(['published' => false]);
        $affiliate->coupons()->sync([$dynamic->id]);

        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20OFF')
            ->assertHasNoErrors('coupon')
            ->assertSessionHas('resrv_coupon', '20OFF')
            ->dispatch('coupon-applied', '20OFF');

        // The discount applied even though the affiliate is disabled
        $this->assertEquals('80.00', $this->reservation->fresh()->price->format());

        // But the disabled affiliate earned no commission attribution
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $this->reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);
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

        // Time-based expiration is a terminal state, not a recoverable drift — the component
        // surfaces it via $reservationError (full-page view) rather than addError() banner.
        $component->call('handleFirstStep')
            ->assertSet('reservationError', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_it_throws_an_error_if_a_user_takes_too_long_in_the_customer_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->dispatch('checkout-form-submitted')
            ->assertSet('reservationError', fn ($value) => is_string($value) && $value !== '');
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

    public function test_non_refundable_reservation_charges_full_even_without_the_full_payment_toggle()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        Config::set('resrv-config.free_cancellation_period', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'price' => '100',
            'payment' => '100',
            'total' => '100',
        ]);
    }

    public function test_snapshot_free_cancellation_period_charges_full_within_the_window()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        // The global period would allow a deposit — the snapshot (7 days) must win.
        Config::set('resrv-config.free_cancellation_period', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', true);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'price' => '100',
            'payment' => '100',
            'total' => '100',
        ]);
    }

    public function test_snapshot_free_cancellation_period_keeps_the_deposit_outside_the_window()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        // The global period (10 days) would force full payment — the snapshot (1 day) must win.
        Config::set('resrv-config.free_cancellation_period', 10);
        Config::set('resrv-config.full_payment_after_free_cancellation', true);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
            'date_start' => today()->addDays(2)->toIso8601String(),
            'date_end' => today()->addDays(4)->toIso8601String(),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 1,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'price' => '100',
            'payment' => '20',
            'total' => '100',
        ]);
    }

    public function test_non_refundable_full_payment_survives_coupon_application_and_removal()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        Config::set('resrv-config.free_cancellation_period', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'payment' => '100',
        ]);

        // Applying a coupon on step 2 recalculates prices — the full-payment decision must survive
        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $fresh = Reservation::find($reservation->id);
        $this->assertEquals('80.00', $fresh->price->format());
        $this->assertEquals('80.00', $fresh->payment->format());
        $this->assertEquals('80.00', $fresh->total->format());

        // Removing it must restore the full un-discounted payment, not the deposit
        session()->forget('resrv_coupon');
        $component->dispatch('coupon-removed', '20OFF', true);

        $fresh = Reservation::find($reservation->id);
        $this->assertEquals('100.00', $fresh->price->format());
        $this->assertEquals('100.00', $fresh->payment->format());
        $this->assertEquals('100.00', $fresh->total->format());
    }

    public function test_full_payment_window_survives_coupon_recalculation()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        Config::set('resrv-config.free_cancellation_period', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', true);

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '20.00',
            'item_id' => $this->entries->first()->id(),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'payment' => '100',
        ]);

        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $fresh = Reservation::find($reservation->id);
        $this->assertEquals('80.00', $fresh->price->format());
        $this->assertEquals('80.00', $fresh->payment->format());
    }

    public function test_everything_mode_payment_keeps_extras_when_coupon_is_applied()
    {
        Config::set('resrv-config.payment', 'everything');

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

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
            'payment' => '139.30',
        ]);

        // In everything mode the payment must keep covering extras and options after the coupon
        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $fresh = Reservation::find($this->reservation->id);
        $this->assertEquals('80.00', $fresh->price->format());
        $this->assertEquals('119.30', $fresh->payment->format());
        $this->assertEquals('119.30', $fresh->total->format());
    }

    public function test_coupon_recalculation_persists_payment_in_a_single_write_without_a_deposit_window()
    {
        // Non-refundable owes the full amount: the 20% deposit must never be persisted, even
        // transiently. The observer records every committed payment so we can prove that.
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', '20');
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $observedPayments = [];
        Reservation::updated(function (Reservation $model) use ($reservation, &$observedPayments) {
            if ($model->id === $reservation->id) {
                $observedPayments[] = $model->payment->format();
            }
        });

        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        // The 20% deposit (16.00) must never be written.
        $this->assertNotContains('16.00', $observedPayments, 'the configured deposit must never be persisted, even transiently');
        $this->assertContains('80.00', $observedPayments);

        $fresh = Reservation::find($reservation->id);
        $this->assertEquals('80.00', $fresh->payment->format());
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

    public function test_coupon_applied_after_gateway_selection_cancels_intent_and_returns_to_step_two()
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
        $this->assertNotEquals('', $reservation->payment_id);

        // Changing the coupon while an intent exists must abandon it and bounce back to step 2.
        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF')
            ->assertSet('step', 2)
            ->assertSet('selectedGateway', '');

        $reservation = Reservation::find($this->reservation->id);
        $this->assertEquals('80.00', $reservation->price->format());
        $this->assertEquals('80.00', $reservation->payment->format());
        $this->assertEquals('0.00', $reservation->payment_surcharge->format());
        $this->assertEquals('80.00', $reservation->totalToCharge());
        $this->assertEquals('', $reservation->payment_id);
        $this->assertEquals('', $reservation->payment_gateway);
    }

    public function test_coupon_cancels_a_persisted_intent_even_when_selected_gateway_is_empty()
    {
        // Simulates a stale/second tab: the reservation row holds a live intent from another
        // tab, but this component never selected a gateway (selectedGateway === ''). The cancel
        // must key off the persisted payment_id, not the component-local property.
        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $gateway->cancelledIntents = [];

        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $this->entries->first()->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entries->first()->id(),
            'payment_id' => 'pi_from_other_tab',
            'payment_gateway' => 'fake',
        ]);

        session(['resrv_reservation' => $reservation->id, 'resrv_coupon' => '20OFF']);

        Livewire::test(Checkout::class)
            ->assertSet('selectedGateway', '')
            ->dispatch('coupon-applied', '20OFF');

        $fresh = Reservation::find($reservation->id);
        $this->assertEquals('80.00', $fresh->payment->format());
        $this->assertEquals('', $fresh->payment_id, 'the stale intent must be cleared off persisted state');
        $this->assertEquals('', $fresh->payment_gateway);

        $this->assertCount(1, $gateway->cancelledIntents);
        $this->assertEquals('pi_from_other_tab', $gateway->cancelledIntents[0]['payment_id']);
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
