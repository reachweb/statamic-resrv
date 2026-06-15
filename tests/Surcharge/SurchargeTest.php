<?php

namespace Reach\StatamicResrv\Tests\Surcharge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\DynamicPricing;
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

    public function test_total_to_charge_and_remaining_split_the_surcharge_to_now_in_deposit_mode()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['item_id' => $this->entry->id()]);
        // total 150 = base 100 + surcharge 50; deposit 50; no gateway surcharge.
        $reservation->forceFill(['payment' => '50.00', 'total' => '150.00', 'payment_surcharge' => '0.00'])->saveQuietly();
        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        $reservation = $reservation->fresh();

        // Charged now: deposit 50 + surcharge 50 (+ 0 gateway) = 100.
        $this->assertEquals('100.00', $reservation->totalToCharge());
        // Remaining balance excludes the now-paid surcharge: 150 - 100 = 50 (the rest of the base).
        $this->assertEquals('50.00', $reservation->amountRemaining());
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

    // Regression: applying or removing a coupon must keep the booking surcharge in the stored total.
    // The surcharge is a flat fee that coupons never discount; dropping it here under-charges the card.
    public function test_applying_and_removing_a_coupon_keeps_the_surcharge_in_the_total()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        // A 20%-off coupon ("20OFF") assigned to the entry's availability.
        $coupon = DynamicPricing::factory()->withCoupon()->create();
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $this->entry->id(),
            'dynamic_pricing_assignment_type' => Availability::class,
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entry->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
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

        // Base 100 + one-way fee 50.
        $this->assertEquals('150.00', $reservation->fresh()->total->format());

        // Apply the coupon: base 100 -> 80, surcharge stays 50 (never discounted) => total 130.
        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        $reservation->refresh();
        $this->assertEquals('80.00', $reservation->price->format());
        $this->assertEquals('130.00', $reservation->total->format());
        $this->assertDatabaseHas('resrv_reservation_surcharge', [
            'reservation_id' => $reservation->id,
            'price' => '50.00',
        ]);

        // Remove the coupon: base back to 100, surcharge stays 50 => total 150.
        session()->forget('resrv_coupon');
        $component->dispatch('coupon-removed', '20OFF', true);

        $this->assertEquals('150.00', $reservation->fresh()->total->format());
    }

    // Regression: the flat surcharge is added ONCE per reservation, even for a parent (multi) booking
    // with several children — never once per child.
    public function test_parent_reservation_counts_the_flat_surcharge_once_not_per_child()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $this->entry->id(),
        ]);

        ChildReservation::factory()->count(2)->create(['reservation_id' => $reservation->id]);

        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        // No options/extras attached: extraCharges() routes to parentExtraCharges() and must add the
        // flat surcharge exactly once (50.00), not once per child (which would give 100.00).
        $this->assertEquals('50.00', $reservation->fresh()->extraCharges()->format());
    }

    // Regression: a coupon change after the component re-mounts with empty selections (e.g. a page
    // reload) must keep the snapshotted surcharge in the total, so total stays consistent with
    // payableNow()/the gateway charge — which read the snapshot, not the live selections.
    public function test_coupon_change_after_a_reload_keeps_the_snapshotted_surcharge_in_the_total()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $coupon = DynamicPricing::factory()->withCoupon()->create();
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $this->entry->id(),
            'dynamic_pricing_assignment_type' => Availability::class,
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entry->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        // Step 1 in one component instance: snapshot the 50.00 surcharge onto the reservation.
        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                    'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id, 'value' => $this->returnDowntown->id, 'price' => '0.00',
                    'optionName' => 'Return location', 'valueName' => 'Downtown', 'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $this->assertDatabaseHas('resrv_reservation_surcharge', ['reservation_id' => $reservation->id, 'price' => '50.00']);

        // Simulate a page reload: a FRESH component re-mounts at step 1 with empty enabledOptions.
        $reloaded = Livewire::test(Checkout::class)->assertSet('step', 1);

        // Apply the coupon on the reloaded component.
        session(['resrv_coupon' => '20OFF']);
        $reloaded->dispatch('coupon-applied', '20OFF');

        // Base 100 -> 80 with the coupon; the snapshotted 50 surcharge is retained => total 130.
        $reservation->refresh();
        $this->assertEquals('80.00', $reservation->price->format());
        $this->assertEquals('130.00', $reservation->total->format());
        $this->assertDatabaseHas('resrv_reservation_surcharge', ['reservation_id' => $reservation->id, 'price' => '50.00']);
    }

    // Regression: changing selections so the surcharge would no longer apply, WITHOUT re-committing via
    // handleFirstStep, then applying a coupon must keep total consistent with the committed snapshot.
    // The snapshot pivot is only re-synced on the next handleFirstStep, so until then total tracks it.
    public function test_coupon_after_an_uncommitted_selection_change_stays_consistent_with_the_snapshot()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $coupon = DynamicPricing::factory()->withCoupon()->create();
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $this->entry->id(),
            'dynamic_pricing_assignment_type' => Availability::class,
        ]);

        $reservation = Reservation::factory()->create([
            'price' => '100.00',
            'payment' => '100.00',
            'item_id' => $this->entry->id(),
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                    'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id, 'value' => $this->returnDowntown->id, 'price' => '0.00',
                    'optionName' => 'Return location', 'valueName' => 'Downtown', 'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertSet('step', 2);

        $this->assertEquals('150.00', $reservation->fresh()->total->format());

        // Uncommitted change: make both locations the same (live surcharge would be 0) but do NOT re-run
        // handleFirstStep, so the committed snapshot pivot stays at 50.
        $component->dispatch('options-updated', [
            $this->pickup->id => [
                'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
            ],
            $this->return->id => [
                'id' => $this->return->id, 'value' => $this->returnAirport->id, 'price' => '0.00',
                'optionName' => 'Return location', 'valueName' => 'Airport', 'priceType' => 'free',
            ],
        ]);

        session(['resrv_coupon' => '20OFF']);
        $component->dispatch('coupon-applied', '20OFF');

        // total uses the committed snapshot (still 50): 80 + 50 = 130, matching payableNow — no divergence.
        $reservation->refresh();
        $this->assertEquals('130.00', $reservation->total->format());
        $this->assertDatabaseHas('resrv_reservation_surcharge', ['reservation_id' => $reservation->id, 'price' => '50.00']);
    }

    // Regression (TC-2): the deposit confirmation/made emails must report the amount actually charged
    // now (deposit + the always-now booking surcharge = payableNow()), not the bare deposit — otherwise
    // "Already paid" + "Remaining" would not reconcile to the Total.
    public function test_deposit_email_reports_payable_now_including_the_surcharge()
    {
        Config::set('resrv-config.payment', 'deposit');

        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->withCustomer()->create(['item_id' => $this->entry->id()]);
        // Distinct figures so the paid line (payableNow 110) cannot be confused with the deposit (60)
        // or the remaining balance (90): total 200 = base 150 + surcharge 50; deposit 60; no gateway fee.
        $reservation->forceFill(['price' => '150.00', 'payment' => '60.00', 'total' => '200.00', 'payment_surcharge' => '0.00'])->saveQuietly();
        $reservation->surcharges()->attach($surcharge->id, ['name' => 'One-way fee', 'price' => '50.00']);

        $reservation = $reservation->fresh();

        // Sanity: charged now = deposit 60 + surcharge 50 = 110; remaining = 200 - 110 = 90.
        $this->assertEquals('110.00', $reservation->payableNow()->format());
        $this->assertEquals('90.00', $reservation->amountRemaining());

        foreach ([new ReservationMade($reservation), new ReservationConfirmed($reservation)] as $mailable) {
            $rendered = $mailable->render();

            $this->assertStringContainsString('110.00', $rendered); // amount actually charged now
            $this->assertStringContainsString('90.00', $rendered);  // remaining balance
            $this->assertStringContainsString('200.00', $rendered); // total
            // The bare deposit must NOT be presented as the paid amount anymore.
            $this->assertStringNotContainsString('60.00', $rendered);
        }
    }

    // Regression (PRICE-2): the checkout payment table shows an itemized surcharge line when a surcharge
    // applies, so the visible lines reconcile to the total.
    public function test_checkout_payment_table_shows_the_surcharge_line_when_a_surcharge_applies()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['price' => '100.00', 'payment' => '100.00', 'item_id' => $this->entry->id()]);
        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                    'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id, 'value' => $this->returnDowntown->id, 'price' => '0.00',
                    'optionName' => 'Return location', 'valueName' => 'Downtown', 'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertSee('Surcharges');
    }

    public function test_checkout_payment_table_hides_the_surcharge_line_for_the_same_location()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['price' => '100.00', 'payment' => '100.00', 'item_id' => $this->entry->id()]);
        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                    'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id, 'value' => $this->returnAirport->id, 'price' => '0.00',
                    'optionName' => 'Return location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
            ])
            ->call('handleFirstStep')
            ->assertSet('step', 2)
            ->assertDontSee('Surcharges');
    }

    // Regression (PRICE-3): payableNowWithGatewayFee must be a SEPARATE Price from payableNow, so the
    // view's gateway-fee addition can never mutate payableNow in place (PriceClass::add mutates).
    // (At step 1 the gateway fee is always 0 — mount resets it — so the guarantee is tested via object
    // independence rather than a non-zero fee.)
    public function test_totals_expose_a_non_mutating_payable_now_with_gateway_fee()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['price' => '50.00']);

        $reservation = Reservation::factory()->create(['item_id' => $this->entry->id()]);
        $reservation->forceFill(['price' => '100.00', 'payment' => '50.00'])->saveQuietly();

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class)
            ->dispatch('options-updated', [
                $this->pickup->id => [
                    'id' => $this->pickup->id, 'value' => $this->pickupAirport->id, 'price' => '0.00',
                    'optionName' => 'Pickup location', 'valueName' => 'Airport', 'priceType' => 'free',
                ],
                $this->return->id => [
                    'id' => $this->return->id, 'value' => $this->returnDowntown->id, 'price' => '0.00',
                    'optionName' => 'Return location', 'valueName' => 'Downtown', 'priceType' => 'free',
                ],
            ]);

        $totals = $component->instance()->calculateReservationTotals();

        // Deposit 50 + live surcharge 50 = 100 payable now.
        $this->assertEquals('100.00', $totals->get('payableNow')->format());
        // The view reads this dedicated key (instead of mutating payableNow with the gateway fee).
        $this->assertEquals('100.00', $totals->get('payableNowWithGatewayFee')->format());
        $this->assertNotSame($totals->get('payableNow'), $totals->get('payableNowWithGatewayFee'));

        // Mutating the gateway-inclusive Price must not leak back into payableNow (the PRICE-3 footgun).
        $totals->get('payableNowWithGatewayFee')->add(Price::create('25.00'));
        $this->assertEquals('100.00', $totals->get('payableNow')->format());
    }
}
