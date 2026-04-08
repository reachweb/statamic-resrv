<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Data\ReservationData;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\ExtraCategory;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;

class CheckoutExtrasTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    public $extras;

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

        $extra = ResrvExtra::factory()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($extra->id);

        $this->extras = ResrvExtra::getPriceForDates($this->reservation);
    }

    // Test that extras are correctly loaded for the Reservation
    public function test_it_loads_the_extras_for_the_entry_and_reservation()
    {
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        $component->assertSee('This is an extra');

        $this->assertEquals('This is an extra', $component->extras->first()->name);
        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    // Test that extra categories are correctly loaded for the Reservation
    public function test_it_loads_the_extra_categories_for_the_entry_and_reservation()
    {
        $extraCategory = ExtraCategory::factory()->create();
        $extra = ResrvExtra::factory()->withCategory()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($extra->id);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        $this->assertCount(2, $component->frontendExtras);

        // Test categorized extra
        $this->assertEquals('This is an extra category', $component->frontendExtras[0]->name);

        // Test categorized extra's child extra
        $this->assertEquals('This extra belongs to a category', $component->frontendExtras[0]->extras[0]->name);
        $this->assertEquals('9.30', $component->frontendExtras[0]->extras[0]->price);

        // Test uncategorized section
        $this->assertNull($component->frontendExtras[1]->id);
        $this->assertEquals('Uncategorized', $component->frontendExtras[1]->name);
        $this->assertEquals(9999, $component->frontendExtras[1]->order);
    }

    // Test that extras prices are correctly calculated when Reservation quantity is greater than 1
    public function test_loads_extras_for_the_reservation_with_extra_quantity()
    {
        $extraQuantityReservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'quantity' => 2,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $extraQuantityReservation]);

        $this->assertEquals('18.60', $component->extras->first()->price);
    }

    // Test that extras prices remain the same when ignore_quantity_for_prices config is set to true
    public function test_loads_extras_for_the_reservation_with_extra_quantity_but_same_price_if_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        $extraQuantityReservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'quantity' => 2,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $extraQuantityReservation]);

        $this->assertEquals('9.30', $component->extras->first()->price);
    }

    // Test that selected extras are correctly displayed in the pricing table and final price
    public function test_loads_if_extra_is_selected_we_see_it_in_the_pricing_table_and_the_final_price()
    {
        session(['resrv_reservation' => $this->reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$this->extras->first()->id => [
                'id' => $this->extras->first()->id,
                'quantity' => 1,
                'price' => $this->extras->first()->price->format(),
                'name' => $this->extras->first()->name,
            ]])
            ->assertSee('€ 9.3')
            ->assertSee('109.30');
    }

    // Test that extras relative to a checkout form item are correctly calculated
    public function test_gets_correct_price_for_custom_price_extra()
    {
        $reservation = Reservation::factory()->for(Customer::factory()->withGuests(3))->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $extra = ResrvExtra::factory()->custom()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($extra->id);

        $component = Livewire::test(Extras::class, ['reservation' => $reservation]);

        $this->assertEquals('30.00', $component->extras[1]->price);
    }

    // Test that relative price extras are correctly calculated based on the Reservation price
    public function test_gets_correct_price_for_relative_price_extra()
    {
        $reservation = Reservation::factory()->for(Customer::factory()->withGuests(3))->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $extra = ResrvExtra::factory()->relative()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);

        $entry->extras()->attach($extra->id);

        $this->travelTo(today()->subDay()->setHour(12));

        $component = Livewire::test(Extras::class, ['reservation' => $reservation]);

        $this->assertEquals('50.00', $component->extras[1]->price);
    }

    // Test that it handles required extras
    public function test_it_handles_required_extra()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $extra = $this->extras->first();

        ExtraCondition::factory()->requiredAlways()->create([
            'extra_id' => $extra->id,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Test with no extras (should fail)
        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors('extras');

        // Test with the required extra
        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extra->id => [
                'id' => $extra->id,
                'quantity' => 1,
                'price' => $extra->price->format(),
                'name' => $extra->name,
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['extras'])
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra->id,
        ]);
    }

    // Test that it handles extras that are required when a specific other extra is selected
    public function test_it_handles_extra_that_is_required_when_another_is_selected()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $item = $this->entries->first();

        $extra = $this->extras->first();
        $extra2 = ResrvExtra::factory()->fixed()->create();

        $entry = ResrvEntry::whereItemId($item->id());

        $entry->extras()->attach($extra2->id);

        ExtraCondition::factory()->requiredExtraSelected()->create([
            'extra_id' => $extra2->id,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Test with only the first extra (should fail because extra2 is required when extra1 is selected)
        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extra->id => [
                'id' => $extra->id,
                'quantity' => 1,
                'price' => $extra->price->format(),
                'name' => $extra->name,
            ]])
            ->call('handleFirstStep')
            ->assertHasErrors(['extras']);

        // Test with required extra (should pass)
        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [
                $extra->id => [
                    'id' => $extra->id,
                    'quantity' => 1,
                    'price' => $extra->price->format(),
                    'name' => $extra->name,
                ],
                $extra2->id => [
                    'id' => $extra2->id,
                    'quantity' => 1,
                    'price' => $extra2->price->format(),
                    'name' => $extra2->name,
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['extras'])
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra->id,
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra2->id,
        ]);
    }

    // Test that it handles extras that are required when a specific other extra is selected
    public function test_it_handles_extra_that_is_required_when_another_is_not_selected()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $item = $this->entries->first();

        $extra = $this->extras->first();
        $extra2 = ResrvExtra::factory()->fixed()->create();

        $entry = ResrvEntry::whereItemId($item->id());

        $entry->extras()->attach($extra2->id);

        ExtraCondition::factory()->requiredExtraNotSelected()->create([
            'extra_id' => $extra2->id,
        ]);

        session(['resrv_reservation' => $this->reservation->id]);

        // Test without enabled extras (should fail because extra2 is required when extra1 is NOT selected)
        $component = Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors(['extras']);

        // Test with required extra since extra 1 is not selected (should pass)
        $component = Livewire::test(Checkout::class)
            ->dispatch('extras-updated', [$extra2->id => [
                'id' => $extra2->id,
                'quantity' => 1,
                'price' => $extra2->price->format(),
                'name' => $extra2->name,
            ]])
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation'])
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $this->reservation->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $this->reservation->id,
            'extra_id' => $extra2->id,
        ]);
    }

    // Test that it generates extras conditions correctly
    public function test_it_loads_extras_conditions_collection()
    {
        $extra = $this->extras->first();

        ExtraCondition::factory()->hideReservationDates()->create([
            'extra_id' => $extra->id,
        ]);

        ExtraCondition::factory()->requiredReservationDates()->create([
            'extra_id' => $extra->id,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        $this->assertTrue($component->extraConditions->get('hide')->contains($extra->id));
        $this->assertTrue($component->extraConditions->get('required')->contains($extra->id));
    }

    // Test that it changes the extra conditions array when an extra is selected
    public function test_it_changes_extras_conditions_on_selected_extra()
    {
        $item = $this->entries->first();

        $extra = $this->extras->first();

        $extra2 = ResrvExtra::factory()->fixed()->create();

        $entry = ResrvEntry::whereItemId($item->id());

        $entry->extras()->attach($extra2->id);

        ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra2->id,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        $this->assertTrue($component->extraConditions->get('hide')->contains($extra2->id));

        $component->call('toggleExtra', $extra->id)->assertDispatched('extra-conditions-changed');

        $this->assertFalse($component->extraConditions->get('hide')->contains($extra2->id));
    }

    // Test when an extra becomes required when any extra from a category is selected
    public function test_it_handles_extra_that_is_required_when_category_extra_is_selected()
    {
        $item = $this->entries->first();

        // Create a category
        $extraCategory = ExtraCategory::factory()->create([
            'name' => 'Test Category',
        ]);

        // Create extras in the category
        $categoryExtra = ResrvExtra::factory()->create([
            'id' => 10,
            'name' => 'Category Extra',
            'category_id' => $extraCategory->id,
        ]);

        // Create an extra with conditions
        $conditionalExtra = ResrvExtra::factory()->create([
            'id' => 11,
            'name' => 'Conditional Extra',
        ]);

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($categoryExtra->id);
        $entry->extras()->attach($conditionalExtra->id);

        // Create condition: conditional_extra is required when any extra from category is selected
        ExtraCondition::factory()->create([
            'extra_id' => $conditionalExtra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'extra_in_category_selected',
                'value' => $extraCategory->id,
            ]],
        ]);

        // Test with no extras selected
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        // Conditional extra should not be required initially
        $this->assertFalse($component->extraConditions->get('required')->contains($conditionalExtra->id));

        // Now select a category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The conditional extra should now be required
        $this->assertTrue($component->extraConditions->get('required')->contains($conditionalExtra->id));
    }

    // Test when an extra becomes required when no extra from a category is selected
    public function test_it_handles_extra_that_is_required_when_no_category_extra_is_selected()
    {
        $item = $this->entries->first();

        // Create a category
        $extraCategory = ExtraCategory::factory()->create([
            'name' => 'Test Category',
        ]);

        // Create extras in the category
        $categoryExtra = ResrvExtra::factory()->create([
            'id' => 10,
            'name' => 'Category Extra',
            'category_id' => $extraCategory->id,
        ]);

        // Create an extra with conditions
        $conditionalExtra = ResrvExtra::factory()->create([
            'id' => 11,
            'name' => 'Conditional Extra',
        ]);

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($categoryExtra->id);
        $entry->extras()->attach($conditionalExtra->id);

        // Create condition: conditional_extra is required when no extra from category is selected
        ExtraCondition::factory()->create([
            'extra_id' => $conditionalExtra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'no_extra_in_category_selected',
                'value' => $extraCategory->id,
            ]],
        ]);

        // Test with no selected extras
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        // Check that conditional extra is in required extras
        $this->assertTrue($component->extraConditions->get('required')->contains($conditionalExtra->id));

        // Now select a category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The conditional extra should no longer be required
        $this->assertFalse($component->extraConditions->get('required')->contains($conditionalExtra->id));
    }

    // Test that an extra can be hidden when an extra from a category is selected
    public function test_it_handles_hidden_extra_when_category_extra_is_selected()
    {
        $item = $this->entries->first();

        // Create a category
        $extraCategory = ExtraCategory::factory()->create([
            'name' => 'Test Category',
        ]);

        // Create extras in the category
        $categoryExtra = ResrvExtra::factory()->create([
            'id' => 10,
            'name' => 'Category Extra',
            'category_id' => $extraCategory->id,
        ]);

        // Create an extra that will be hidden
        $hiddenExtra = ResrvExtra::factory()->create([
            'id' => 11,
            'name' => 'Hidden Extra',
        ]);

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($categoryExtra->id);
        $entry->extras()->attach($hiddenExtra->id);

        // Create condition: hidden_extra is hidden when any extra from category is selected
        ExtraCondition::factory()->create([
            'extra_id' => $hiddenExtra->id,
            'conditions' => [[
                'operation' => 'hidden',
                'type' => 'extra_in_category_selected',
                'value' => $extraCategory->id,
            ]],
        ]);

        // Test with no extras selected
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        // Hidden extra should not be in hidden extras initially
        $this->assertFalse($component->extraConditions->get('hide')->contains($hiddenExtra->id));

        // Now select a category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The hidden extra should now be hidden
        $this->assertTrue($component->extraConditions->get('hide')->contains($hiddenExtra->id));
    }

    // Test that an extra is shown when an extra from a category is selected
    public function test_it_handles_extra_that_is_shown_when_category_extra_is_selected()
    {
        $item = $this->entries->first();

        // Create a category
        $extraCategory = ExtraCategory::factory()->create([
            'name' => 'Test Category',
        ]);

        // Create extras in the category
        $categoryExtra = ResrvExtra::factory()->create([
            'id' => 10,
            'name' => 'Category Extra',
            'category_id' => $extraCategory->id,
        ]);

        // Create an extra that will be shown conditionally
        $conditionalExtra = ResrvExtra::factory()->create([
            'id' => 11,
            'name' => 'Conditional Extra',
        ]);

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($categoryExtra->id);
        $entry->extras()->attach($conditionalExtra->id);

        // Create condition: conditional_extra is shown when any extra from category is selected
        ExtraCondition::factory()->create([
            'extra_id' => $conditionalExtra->id,
            'conditions' => [[
                'operation' => 'show',
                'type' => 'extra_in_category_selected',
                'value' => $extraCategory->id,
            ]],
        ]);

        // Test with no extras selected
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        // Conditional extra should be hidden initially
        $this->assertTrue($component->extraConditions->get('hide')->contains($conditionalExtra->id));

        // Now select a category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The conditional extra should now be shown (no longer in hidden extras)
        $this->assertFalse($component->extraConditions->get('hide')->contains($conditionalExtra->id));
    }

    // Test that an extra can be hidden when NO extra from a category is selected
    public function test_it_handles_hidden_extra_when_no_category_extra_is_selected()
    {
        $item = $this->entries->first();

        // Create a category
        $extraCategory = ExtraCategory::factory()->create([
            'name' => 'Test Category',
        ]);

        // Create extras in the category
        $categoryExtra = ResrvExtra::factory()->create([
            'id' => 10,
            'name' => 'Category Extra',
            'category_id' => $extraCategory->id,
        ]);

        // Create an extra that will be conditionally hidden
        $conditionalExtra = ResrvExtra::factory()->create([
            'id' => 11,
            'name' => 'Conditional Extra',
        ]);

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($categoryExtra->id);
        $entry->extras()->attach($conditionalExtra->id);

        // Create condition: conditional_extra is hidden when NO extra from category is selected
        ExtraCondition::factory()->create([
            'extra_id' => $conditionalExtra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'no_extra_in_category_selected',
                'value' => $extraCategory->id,
            ]],
        ]);

        // Test with no extras selected
        $component = Livewire::test(Extras::class, ['reservation' => $this->reservation]);

        // Conditional extra should be required initially when no category extra is selected
        $this->assertTrue($component->extraConditions->get('required')->contains($conditionalExtra->id));

        // Now select a category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The conditional extra should no longer be required
        $this->assertFalse($component->extraConditions->get('required')->contains($conditionalExtra->id));

        // Now unselect the category extra
        $component->call('toggleExtra', $categoryExtra->id);

        // The conditional extra should be required again
        $this->assertTrue($component->extraConditions->get('required')->contains($conditionalExtra->id));
    }

    public function test_parent_extra_charges_sum_per_child_dates()
    {
        $item = $this->entries->first();

        // Per-day extra at 4.65/day (default factory)
        $extra = $this->extras->first();

        // Parent spanning day 0 - day 5 (5 days), but children are non-contiguous
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: 2 days, qty 1
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: 1 day, qty 1
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(4)->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Attach extra (qty 1) to the reservation
        $reservation->extras()->sync([$extra->id => ['quantity' => 1, 'price' => '0']]);

        // Per-child calculation:
        // Child 1: 4.65 * 1 qty * 2 days = 9.30
        // Child 2: 4.65 * 1 qty * 1 day  = 4.65
        // Total: 13.95
        // NOT: 4.65 * 2 qty * 5 days = 46.50 (parent collapsed dates)
        $this->assertEquals('13.95', $reservation->extraCharges()->format());
    }

    public function test_parent_extra_charges_handles_soft_deleted_extras()
    {
        $item = $this->entries->first();

        // Per-day extra at 4.65/day (default factory)
        $extra = $this->extras->first();

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
            'price' => '100.00',
            'payment' => '100.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        $reservation->extras()->sync([$extra->id => ['quantity' => 1, 'price' => '0']]);

        // Soft-delete the extra after the reservation is created — historical
        // reservations must still be priceable.
        $extra->delete();

        // Should not throw — Extra::withTrashed() finds the deleted extra.
        // 4.65/day * 1 qty * 2 days = 9.30
        $this->assertEquals('9.30', $reservation->extraCharges()->format());
    }

    public function test_parent_extra_charges_with_fixed_type()
    {
        $item = $this->entries->first();

        $extra = ResrvExtra::factory()->fixed()->create();

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($extra->id);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 3,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 2,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(4)->toIso8601String(),
            'date_end' => today()->addDays(5)->toIso8601String(),
            'quantity' => 1,
        ]);

        $reservation->extras()->sync([$extra->id => ['quantity' => 1, 'price' => '0']]);

        // Fixed extra at 25.00, qty 1 selected:
        // Child 1: 25 * 1 * qty_mult(2) = 50
        // Child 2: 25 * 1 * qty_mult(1) = 25
        // Total: 75
        // NOT: 25 * 1 * qty_mult(3) = 75 (coincidentally same for fixed, but via correct path)
        $this->assertEquals('75.00', $reservation->extraCharges()->format());
    }

    public function test_coupon_valid_for_child_dates_applies_to_parent()
    {
        $item = $this->entries->first();

        // Parent spans day 0 - day 10, but children are non-contiguous
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: day 0 - day 2
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: day 8 - day 10
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(8)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Coupon valid only for days 0-3 (covers child 1)
        $coupon = DynamicPricing::factory()->withCoupon()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(3)->toIso8601String(),
            'date_include' => 'all',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $item->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();
        session(['resrv_coupon' => '20OFF']);

        // Should succeed: child 1 (day 0-2) is within coupon range (day 0-3)
        $result = DynamicPricing::searchForCoupon('20OFF', $reservation->id);
        $this->assertNotNull($result);
    }

    public function test_coupon_outside_all_child_dates_is_rejected()
    {
        $item = $this->entries->first();

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: day 0 - day 2
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: day 8 - day 10
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(8)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Coupon valid only for days 4-6 (gap between children — no child covers this)
        $coupon = DynamicPricing::factory()->withCoupon()->create([
            'date_start' => today()->addDays(4)->toIso8601String(),
            'date_end' => today()->addDays(6)->toIso8601String(),
            'date_include' => 'all',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $item->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();
        session(['resrv_coupon' => '20OFF']);

        $this->expectException(CouponNotFoundException::class);
        DynamicPricing::searchForCoupon('20OFF', $reservation->id);
    }

    public function test_coupon_removal_does_not_crash_when_pivot_row_missing()
    {
        $item = $this->entries->first();

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(8)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        $coupon = DynamicPricing::factory()->withCoupon()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(3)->toIso8601String(),
            'date_include' => 'all',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $item->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();
        session(['resrv_coupon' => '20OFF']);

        // Coupon is NOT in the pivot table — removal should not crash
        CouponUpdated::dispatch($reservation, '20OFF', true);

        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', [
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_dynamic_pricing_attached_per_child_for_parent_reservation()
    {
        $item = $this->makeStatamicItemWithAvailability(
            available: 2,
            price: 50,
            customAvailability: [
                'dates' => collect(range(0, 11))->map(fn ($i) => today()->addDays($i))->all(),
            ],
        );

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(8)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Coupon valid only for days 0-3 (covers child 1 but not parent envelope 0-10)
        $coupon = DynamicPricing::factory()->withCoupon()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(3)->toIso8601String(),
            'date_include' => 'all',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $item->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::flush();
        session(['resrv_coupon' => '20OFF']);

        // Per-child evaluation should find the coupon via child 1's dates
        ReservationCreated::dispatch($reservation, new ReservationData(coupon: '20OFF'));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing', [
            'reservation_id' => $reservation->id,
            'dynamic_pricing_id' => $coupon->id,
        ]);
    }

    public function test_parent_extra_duration_condition_does_not_use_envelope()
    {
        $item = $this->entries->first();

        $extra = $this->extras->first();

        // Condition: required when duration >= 7 days
        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'reservation_duration',
                'comparison' => '>=',
                'value' => '7',
            ]],
        ]);

        // Parent envelope spans 20 days, but each child is only 2 days
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(20)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(18)->toIso8601String(),
            'date_end' => today()->addDays(20)->toIso8601String(),
            'quantity' => 1,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $reservation]);

        // Neither child is >= 7 days, so the extra should NOT be required
        $this->assertFalse($component->extraConditions->get('required')->contains($extra->id));
    }

    public function test_parent_extra_required_when_any_child_matches_duration()
    {
        $item = $this->entries->first();

        $extra = $this->extras->first();

        // Condition: required when duration >= 3 days
        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'reservation_duration',
                'comparison' => '>=',
                'value' => '3',
            ]],
        ]);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: 4 days (matches >= 3)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(4)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: 1 day (does not match >= 3)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(9)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $reservation]);

        // Child 1 matches, so the extra should be required
        $this->assertTrue($component->extraConditions->get('required')->contains($extra->id));
    }

    public function test_parent_extra_hidden_only_when_all_children_match()
    {
        $item = $this->entries->first();

        $extra = $this->extras->first();

        // Condition: hidden when dates in range (covers only child 1's dates)
        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [[
                'operation' => 'hidden',
                'type' => 'reservation_dates',
                'date_start' => today()->toIso8601String(),
                'date_end' => today()->addDays(5)->toIso8601String(),
            ]],
        ]);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(20)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Child 1: day 0-2 (within hide range day 0-5)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: day 18-20 (outside hide range)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(18)->toIso8601String(),
            'date_end' => today()->addDays(20)->toIso8601String(),
            'quantity' => 1,
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $reservation]);

        // Child 2 is outside the hide range, so the extra should NOT be hidden
        $this->assertFalse($component->extraConditions->get('hide')->contains($extra->id));
    }

    public function test_parent_validates_required_extras_per_child_not_envelope()
    {
        // Create entry with 20 days of availability to cover all child dates
        $item = $this->makeStatamicItemWithAvailability(
            available: 2,
            price: 50,
            customAvailability: [
                'dates' => collect(range(0, 19))->map(fn ($i) => today()->addDays($i))->all(),
            ],
        );

        $extra = $this->extras->first();
        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($extra->id);

        // Condition: required when duration >= 7
        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'reservation_duration',
                'comparison' => '>=',
                'value' => '7',
            ]],
        ]);

        // Parent envelope is 10 days, but each child is only 2 days
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(8)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        $data = [
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
            'payment' => $reservation->payment,
            'price' => $reservation->price,
            'total' => $reservation->price,
            'extras' => collect(),
            'options' => collect(),
            'customer' => collect(),
        ];

        // Neither child is >= 7 days, so validation should pass without the extra
        $result = $reservation->validateReservation($data, $item->id(), checkOptions: false);
        $this->assertTrue($result);
    }

    public function test_parent_validation_requires_extra_when_any_child_triggers()
    {
        $item = $this->makeStatamicItemWithAvailability(
            available: 2,
            price: 50,
            customAvailability: [
                'dates' => collect(range(0, 19))->map(fn ($i) => today()->addDays($i))->all(),
            ],
        );

        $extra = $this->extras->first();
        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($extra->id);

        // Condition: required when duration >= 3
        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [[
                'operation' => 'required',
                'type' => 'reservation_duration',
                'comparison' => '>=',
                'value' => '3',
            ]],
        ]);

        // Child 1: 4 days @ 50/day = 200, Child 2: 1 day @ 50/day = 50, Total = 250
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 2,
            'price' => '250.00',
            'payment' => '250.00',
        ]);

        // Child 1: 4 days (triggers >= 3)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(4)->toIso8601String(),
            'quantity' => 1,
        ]);

        // Child 2: 1 day (does not trigger)
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(9)->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'quantity' => 1,
        ]);

        $data = [
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
            'payment' => $reservation->payment,
            'price' => $reservation->price,
            'total' => $reservation->price,
            'extras' => collect(),
            'options' => collect(),
            'customer' => collect(),
        ];

        // Child 1 triggers it — validation should fail without the extra
        $this->expectException(ExtrasException::class);
        $reservation->validateReservation($data, $item->id(), checkOptions: false);
    }

    public function test_parent_custom_extra_uses_customer_data_not_session()
    {
        $item = $this->entries->first();

        $customer = Customer::factory()->withGuests(3)->create();

        $extra = ResrvExtra::factory()->custom()->create();

        $entry = ResrvEntry::whereItemId($item->id());
        $entry->extras()->attach($extra->id);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $item->id(),
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(4)->toIso8601String(),
            'quantity' => 2,
            'price' => '200.00',
            'payment' => '200.00',
            'customer_id' => $customer->id,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'quantity' => 1,
        ]);

        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'date_start' => today()->addDays(2)->toIso8601String(),
            'date_end' => today()->addDays(4)->toIso8601String(),
            'quantity' => 1,
        ]);

        $reservation->extras()->sync([$extra->id => ['quantity' => 1, 'price' => '0']]);

        // Custom extra with price=10, customer guests=3
        // Per child: 10 * 3 = 30, two children → 60
        // NOT: 10 * 1 (session fallback) * 2 = 20
        $charges = $reservation->extraCharges();
        $this->assertEquals('60.00', $charges->format());
    }
}
