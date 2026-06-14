<?php

namespace Reach\StatamicResrv\Tests\Surcharge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Models\Surcharge;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class SurchargeTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected $entry;

    protected $pickup;

    protected $return;

    protected $pickupAirport;

    protected $pickupDowntown;

    protected $returnAirport;

    protected $returnDowntown;

    protected function setUp(): void
    {
        parent::setUp();
        Option::resetEntryCollectionCache();
        $this->travelTo(today()->setHour(12));

        $this->entry = $this->makeStatamicItemWithAvailability(collection: 'pages');

        // Two independent single-select pickers sharing the same location NAMES.
        $this->pickup = Option::factory()->forEntry($this->entry->id())->create(['name' => 'Pickup location', 'slug' => 'pickup-location']);
        $this->pickupAirport = OptionValue::factory()->create(['option_id' => $this->pickup->id, 'name' => 'Airport', 'price' => '0', 'price_type' => 'free']);
        $this->pickupDowntown = OptionValue::factory()->create(['option_id' => $this->pickup->id, 'name' => 'Downtown', 'price' => '0', 'price_type' => 'free']);

        $this->return = Option::factory()->forEntry($this->entry->id())->create(['name' => 'Return location', 'slug' => 'return-location']);
        $this->returnAirport = OptionValue::factory()->create(['option_id' => $this->return->id, 'name' => 'Airport', 'price' => '0', 'price_type' => 'free']);
        $this->returnDowntown = OptionValue::factory()->create(['option_id' => $this->return->id, 'name' => 'Downtown', 'price' => '0', 'price_type' => 'free']);
    }

    public function test_surcharge_applies_when_the_two_locations_differ()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $total = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
            $this->return->id => $this->returnDowntown->id,
        ]);

        $this->assertEquals('50.00', $total->format());
    }

    // The core correctness case: same location name on both ends => NO one-way fee, even though
    // the two values are distinct rows on distinct options.
    public function test_surcharge_does_not_apply_when_both_locations_are_the_same()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $total = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
            $this->return->id => $this->returnAirport->id,
        ]);

        $this->assertEquals('0.00', $total->format());
    }

    public function test_surcharge_does_not_apply_when_only_one_side_is_selected()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $total = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
        ]);

        $this->assertEquals('0.00', $total->format());
    }

    public function test_matches_comparison_fires_when_the_locations_are_the_same()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->matches()->create(['price' => '30.00']);

        $same = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
            $this->return->id => $this->returnAirport->id,
        ]);
        $different = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
            $this->return->id => $this->returnDowntown->id,
        ]);

        $this->assertEquals('30.00', $same->format());
        $this->assertEquals('0.00', $different->format());
    }

    public function test_unpublished_surcharges_are_ignored()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00', 'published' => false]);

        $total = Surcharge::totalForSelections([
            $this->pickup->id => $this->pickupAirport->id,
            $this->return->id => $this->returnDowntown->id,
        ]);

        $this->assertEquals('0.00', $total->format());
    }

    // The snapshot freezes the charged amount: editing the rule later must not change a past booking.
    public function test_reservation_uses_the_snapshotted_surcharge_price()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['item_id' => $this->entry->id(), 'price' => '100.00', 'payment' => '100.00']);
        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        $surcharge->update(['price' => '999.00']);

        $this->assertEquals('50.00', $reservation->fresh()->bookingSurchargeTotal()->format());
        // extraCharges (read-back) reads the snapshot, not the live rule.
        $this->assertEquals('50.00', $reservation->fresh()->extraCharges()->format());
    }

    public function test_payable_now_adds_the_surcharge_on_top_of_a_partial_deposit()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['item_id' => $this->entry->id()]);
        $reservation->forceFill(['payment' => '50.00', 'total' => '150.00'])->saveQuietly();
        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        // Deposit (payment 50 != total 150): surcharge is always payable now => 50 + 50.
        $this->assertEquals('100.00', $reservation->fresh()->payableNow()->format());
    }

    public function test_payable_now_does_not_double_count_the_surcharge_in_full_payment()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['item_id' => $this->entry->id()]);
        // Full payment: payment already equals total (surcharge folded in).
        $reservation->forceFill(['payment' => '150.00', 'total' => '150.00'])->saveQuietly();
        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        $this->assertEquals('150.00', $reservation->fresh()->payableNow()->format());
    }

    // End-to-end: selecting two different locations at checkout adds the surcharge to the stored
    // total, passes the drift gate (the three-point coupling), and snapshots the applied surcharge.
    public function test_checkout_applies_and_snapshots_the_surcharge_without_drift()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        // 2-day booking at 50/day == 100, matching the default reservation/availability price.
        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entry->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id,
                    'value' => $this->pickupAirport->id,
                    'price' => '0.00',
                    'optionName' => 'Pickup location',
                    'valueName' => 'Airport',
                    'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id,
                    'value' => $this->returnDowntown->id,
                    'price' => '0.00',
                    'optionName' => 'Return location',
                    'valueName' => 'Downtown',
                    'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        // Base 100 + free options + 50 one-way fee.
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'total' => '150.00',
        ]);

        $this->assertDatabaseHas('resrv_reservation_surcharge', [
            'reservation_id' => $reservation->id,
            'surcharge_id' => $surcharge->id,
            'price' => '50.00',
        ]);
    }

    // Same location at both ends => no surcharge applied or snapshotted.
    public function test_checkout_does_not_apply_the_surcharge_for_the_same_location()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entry->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id,
                    'value' => $this->pickupAirport->id,
                    'price' => '0.00',
                    'optionName' => 'Pickup location',
                    'valueName' => 'Airport',
                    'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id,
                    'value' => $this->returnAirport->id,
                    'price' => '0.00',
                    'optionName' => 'Return location',
                    'valueName' => 'Airport',
                    'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'total' => '100.00',
        ]);

        $this->assertDatabaseMissing('resrv_reservation_surcharge', [
            'reservation_id' => $reservation->id,
        ]);
    }
}
