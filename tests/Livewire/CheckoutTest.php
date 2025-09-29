<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\CouponUpdated;
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
}
