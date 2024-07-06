<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\DynamicPricing;
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

    public function setUp(): void
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

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $this->extra->id,
        ]);

        $this->options = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create([
                'item_id' => $this->entries->first()->id(),
            ]);
    }

    /** @test */
    public function renders_successfully()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout')
            ->assertStatus(200);
    }

    /** @test */
    public function loads_reservation_and_entry()
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

    /** @test */
    public function it_handles_first_step()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', collect([0 => [
                'id' => $this->extra->id,
                'price' => $extras->first()->price,
                'quantity' => 1,
            ]]))
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

    /** @test */
    public function it_handles_second_step()
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

    /** @test */
    public function it_shows_an_arror_if_the_reservation_is_expired()
    {
        $reservation = Reservation::factory()->expired()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->assertViewIs('statamic-resrv::livewire.checkout-error')
            ->assertSee('This reservation has expired');
    }

    /** @test */
    public function it_throws_an_error_if_a_user_takes_too_long_in_the_extras_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->call('handleFirstStep')
            ->assertHasErrors('reservation');
    }

    /** @test */
    public function it_throws_an_error_if_a_user_takes_too_long_in_the_customer_form()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $this->travel(30)->minutes();

        $component->dispatch('checkout-form-submitted')
            ->assertHasErrors('reservation');
    }

    /** @test */
    public function it_successfully_applies_a_coupon()
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

    /** @test */
    public function it_adds_an_error_if_coupon_does_not_exist()
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

    /** @test */
    public function it_adds_an_error_if_coupon_does_not_apply_to_the_product()
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

    /** @test */
    public function it_adds_an_error_if_the_coupon_is_invalid()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        $component = Livewire::test(Checkout::class);

        $component->call('addCoupon', '20%OFF')
            ->assertHasErrors(['coupon'])
            ->assertSet('coupon', null)
            ->assertSessionMissing('resrv_coupon')
            ->assertSee('The coupon code is invalid');
    }

    /** @test */
    public function it_removes_a_coupon_from_the_session()
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

    /** @test */
    public function it_charges_only_the_reservation_price_when_payment_is_set_to_full()
    {
        Config::set('resrv-config.payment', 'full');

        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', collect([0 => [
                'id' => $this->extra->id,
                'price' => $extras->first()->price,
                'quantity' => 1,
            ]]))
            ->set('enabledOptions.options', [[
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
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

    /** @test */
    public function it_charges_everything_when_payment_is_set_to_everything()
    {
        Config::set('resrv-config.payment', 'everything');

        session(['resrv_reservation' => $this->reservation->id]);

        $extras = ResrvExtra::getPriceForDates($this->reservation);

        $component = Livewire::test(Checkout::class)
            ->set('enabledExtras.extras', collect([0 => [
                'id' => $this->extra->id,
                'price' => $extras->first()->price,
                'quantity' => 1,
            ]]))
            ->set('enabledOptions.options', [[
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
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

    /** @test */
    public function it_charges_everything_when_after_free_cancellation_period()
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
            ->set('enabledExtras.extras', collect([0 => [
                'id' => $this->extra->id,
                'price' => $extras->first()->price,
                'quantity' => 1,
            ]]))
            ->set('enabledOptions.options', [[
                'id' => $this->options->first()->id,
                'value' => $this->options->first()->values->first()->id,
                'price' => $this->options->first()->values->first()->price->format(),
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
}
