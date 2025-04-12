<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Models\Customer;
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
}
