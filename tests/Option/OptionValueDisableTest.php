<?php

namespace Reach\StatamicResrv\Tests\Option;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Options;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class OptionValueDisableTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        Option::resetEntryCollectionCache();
        $this->travelTo(today()->setHour(12));
        $this->entries = $this->createEntries();
    }

    public function test_a_value_disabled_for_an_entry_is_hidden_from_that_entry()
    {
        $entry = $this->entries->get('normal');

        $option = Option::factory()
            ->has(OptionValue::factory()->count(2), 'values')
            ->forEntry($entry->id())
            ->create();

        $disabled = $option->values->first();
        $enabled = $option->values->last();
        $disabled->disabledEntries()->attach($entry->id());

        $reservation = Reservation::factory()->create(['item_id' => $entry->id()]);

        $component = Livewire::test(Options::class, ['reservation' => $reservation]);

        $shownValues = $component->options->first()->values;
        $this->assertCount(1, $shownValues);
        $this->assertEquals($enabled->id, $shownValues->first()->id);
    }

    public function test_an_option_with_all_values_disabled_is_not_shown_for_the_entry()
    {
        $entry = $this->entries->get('normal');

        $option = Option::factory()
            ->has(OptionValue::factory()->count(2), 'values')
            ->forEntry($entry->id())
            ->create();

        $option->values->each(fn ($value) => $value->disabledEntries()->attach($entry->id()));

        $reservation = Reservation::factory()->create(['item_id' => $entry->id()]);

        $component = Livewire::test(Options::class, ['reservation' => $reservation]);

        $this->assertCount(0, $component->options);
    }

    public function test_disabling_a_value_for_one_entry_does_not_affect_another()
    {
        // An apply_to_all option in the 'pages' collection appears on both entries.
        $entryA = $this->entries->get('normal');
        $entryB = $this->entries->get('two-available');

        $option = Option::factory()
            ->has(OptionValue::factory()->count(2), 'values')
            ->create(['collection' => 'pages', 'apply_to_all' => true]);

        $option->values->first()->disabledEntries()->attach($entryA->id());

        $reservationA = Reservation::factory()->create(['item_id' => $entryA->id()]);
        $reservationB = Reservation::factory()->create(['item_id' => $entryB->id()]);

        $componentA = Livewire::test(Options::class, ['reservation' => $reservationA]);
        $componentB = Livewire::test(Options::class, ['reservation' => $reservationB]);

        $this->assertCount(1, $componentA->options->first()->values);
        $this->assertCount(2, $componentB->options->first()->values);
    }

    // The deadlock guard: a REQUIRED option whose every value is disabled for the entry must not be
    // treated as required (the UI renders no value to satisfy it), so checkout is not blocked.
    public function test_required_option_with_all_values_disabled_does_not_block_checkout()
    {
        $entry = $this->entries->get('normal');

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $entry->id(),
        ]);

        // Required option (factory default) with a single value, disabled for this entry.
        $option = Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->forEntry($entry->id())
            ->create();
        $option->values->first()->disabledEntries()->attach($entry->id());

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasNoErrors('options')
            ->assertSet('step', 2);
    }

    // Sanity check the inverse still holds: a required option WITH an enabled value still blocks
    // checkout when nothing is selected (guards against the filter over-excluding).
    public function test_required_option_with_an_enabled_value_still_blocks_checkout()
    {
        $entry = $this->entries->get('normal');

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $entry->id(),
        ]);

        Option::factory()
            ->has(OptionValue::factory(), 'values')
            ->forEntry($entry->id())
            ->create();

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->call('handleFirstStep')
            ->assertHasErrors('options');
    }

    // Server-side enforcement: the frontend hides disabled values, but a stale session or forged
    // options payload could still submit one. Checkout must reject it instead of pricing/snapshotting.
    public function test_checkout_rejects_an_option_value_disabled_for_the_entry()
    {
        $entry = $this->entries->get('normal');

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $entry->id(),
        ]);

        $option = Option::factory()
            ->has(OptionValue::factory()->count(2)->state(['price' => '0', 'price_type' => 'free']), 'values')
            ->forEntry($entry->id())
            ->create();

        $disabled = $option->values->first();
        $disabled->disabledEntries()->attach($entry->id());

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $option->id => [
                    'id' => $option->id,
                    'value' => $disabled->id,
                    'price' => '0.00',
                    'optionName' => $option->name,
                    'valueName' => $disabled->name,
                    'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasErrors('options')
            ->assertSet('step', 1);

        // The disabled selection must never be snapshotted onto the reservation.
        $this->assertDatabaseMissing('resrv_reservation_option', [
            'reservation_id' => $reservation->id,
        ]);
    }

    // Inverse guard: submitting an ENABLED value of the same option still passes (no over-rejection).
    public function test_checkout_accepts_an_enabled_option_value()
    {
        $entry = $this->entries->get('normal');

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $entry->id(),
        ]);

        $option = Option::factory()
            ->has(OptionValue::factory()->count(2)->state(['price' => '0', 'price_type' => 'free']), 'values')
            ->forEntry($entry->id())
            ->create();

        $disabled = $option->values->first();
        $enabled = $option->values->last();
        $disabled->disabledEntries()->attach($entry->id());

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $option->id => [
                    'id' => $option->id,
                    'value' => $enabled->id,
                    'price' => '0.00',
                    'optionName' => $option->name,
                    'valueName' => $enabled->name,
                    'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => $reservation->id,
            'value' => $enabled->id,
        ]);
    }
}
