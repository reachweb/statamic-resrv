<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Livewire\AvailabilityMultiResults;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Livewire\Options;
use Reach\StatamicResrv\Livewire\Traits\HandlesExtrasQueries;
use Reach\StatamicResrv\Livewire\Traits\HandlesOptionsQueries;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class AvailabilityMultiResultsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $advancedEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
    }

    protected function createCheckoutEntry(): Entry
    {
        $this->findOrCreateCollection('pages');

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        return $entry;
    }

    protected function getEntryAndRates(): array
    {
        $entryId = $this->advancedEntries->first()->id();
        $rates = Rate::forEntry($entryId)->get();

        return [$entryId, $rates];
    }

    protected function createMultiRateEntry(int $available = 5): array
    {
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'multi',
            available: $available,
            price: 50,
            rateSlug: 'adults',
        );

        $childrenRate = Rate::factory()->create([
            'collection' => 'multi',
            'slug' => 'children',
            'title' => 'Children',
        ]);

        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'available' => $available,
                'price' => 30,
                'statamic_id' => $entry->id(),
                'rate_id' => $childrenRate->id,
            ]);

        $adultsRate = Rate::forEntry($entry->id())->where('slug', 'adults')->first();

        return [$entry->id(), $adultsRate, $childrenRate];
    }

    protected function searchPayload(?string $dateStart = null, ?string $dateEnd = null): array
    {
        return [
            'dates' => [
                'date_start' => $dateStart ?? $this->date->toISOString(),
                'date_end' => $dateEnd ?? $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'rate' => 'any',
        ];
    }

    public function test_renders_successfully()
    {
        [$entryId] = $this->getEntryAndRates();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->assertViewIs('statamic-resrv::livewire.availability-multi-results')
            ->assertStatus(200);
    }

    public function test_shows_rates_with_quantity_pickers()
    {
        [$entryId, $rates] = $this->getEntryAndRates();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload());

        $availability = $component->viewData('availability');

        $this->assertTrue($availability->isNotEmpty());
        $this->assertTrue($availability->has($rates->first()->id));
    }

    public function test_can_update_rate_quantity()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 2)
            ->assertSet("rateQuantities.{$rateId}", 2);
    }

    public function test_quantity_cannot_go_below_zero()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, -5)
            ->assertSet("rateQuantities.{$rateId}", 0);
    }

    public function test_add_selections_creates_cart_items()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 2)
            ->call('addSelections');

        $selections = $component->get('selections');
        $this->assertCount(1, $selections);
        $this->assertEquals($rateId, $selections[0]['rate_id']);
        $this->assertEquals(2, $selections[0]['quantity']);
        $this->assertNotEmpty($selections[0]['price']);
    }

    public function test_add_selections_resets_rate_quantities()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 2)
            ->call('addSelections')
            ->assertSet('rateQuantities', []);
    }

    public function test_add_selections_errors_without_quantities()
    {
        [$entryId] = $this->getEntryAndRates();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('addSelections')
            ->assertHasErrors('availability');
    }

    public function test_remove_selection()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 1)
            ->call('addSelections');

        $this->assertCount(1, $component->get('selections'));

        $component->call('removeSelection', 0);

        $this->assertCount(0, $component->get('selections'));
    }

    public function test_clear_selections()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 1)
            ->call('addSelections')
            ->call('clearSelections')
            ->assertSet('selections', []);
    }

    public function test_total_price_computed_correctly()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 2)
            ->call('addSelections');

        $selections = $component->get('selections');
        $unitPrice = $selections[0]['price'];

        // Price per rate for 2 days at 50/day = 100, quantity 2 = total should be 200
        $this->assertEquals('200.00', $component->totalPrice);
    }

    public function test_line_total_multiplies_per_unit_price_by_quantity()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 3)
            ->call('addSelections');

        $selection = $component->get('selections')[0];

        // Per-unit price is captured at quantity=1 (e.g. 2 days at 50/day = 100.00),
        // so the displayed line should show 100.00 * 3 = 300.00 to match totalPrice.
        $this->assertEquals('300.00', $component->instance()->lineTotal($selection));
        $this->assertEquals('300.00', $component->totalPrice);
    }

    public function test_line_total_ignores_quantity_when_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 3)
            ->call('addSelections');

        $selection = $component->get('selections')[0];

        // With ignore_quantity_for_prices, the line should equal the per-unit price.
        $this->assertEquals($selection['price'], $component->instance()->lineTotal($selection));
    }

    public function test_multi_rate_same_dates_checkout()
    {
        $this->createCheckoutEntry();

        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload());

        $component
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('updateRateQuantity', $childrenRate->id, 2)
            ->call('addSelections');

        $selections = $component->get('selections');
        $this->assertCount(2, $selections);

        $component->call('checkout');

        // Parent reservation should exist
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
            'quantity' => 4,
        ]);

        $this->assertDatabaseCount('resrv_child_reservations', 2);

        $this->assertDatabaseHas('resrv_child_reservations', [
            'rate_id' => $adultsRate->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('resrv_child_reservations', [
            'rate_id' => $childrenRate->id,
            'quantity' => 2,
        ]);
    }

    public function test_multi_date_checkout()
    {
        $this->createCheckoutEntry();

        // Create an entry with enough availability for multiple date ranges
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'multidate',
            available: 5,
            rateSlug: 'standard',
            customAvailability: [
                'dates' => [
                    today(),
                    today()->addDay(),
                    today()->addDays(2),
                    today()->addDays(3),
                    today()->addDays(4),
                    today()->addDays(5),
                    today()->addDays(6),
                    today()->addDays(7),
                ],
                'price' => 50,
                'available' => 5,
            ]
        );

        $entryId = $entry->id();
        $rateId = Rate::forEntry($entryId)->first()->id;

        // First date range: day 1-3
        $dateStart1 = $this->date->toISOString();
        $dateEnd1 = $this->date->copy()->add(2, 'day')->toISOString();

        // Second date range: day 5-7
        $dateStart2 = $this->date->copy()->add(4, 'day')->toISOString();
        $dateEnd2 = $this->date->copy()->add(6, 'day')->toISOString();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Search first dates and add
        $component
            ->dispatch('availability-search-updated', $this->searchPayload($dateStart1, $dateEnd1))
            ->call('updateRateQuantity', $rateId, 1)
            ->call('addSelections');

        $this->assertCount(1, $component->get('selections'));

        // Search second dates and add
        $component
            ->dispatch('availability-search-updated', $this->searchPayload($dateStart2, $dateEnd2))
            ->call('updateRateQuantity', $rateId, 1)
            ->call('addSelections');

        $this->assertCount(2, $component->get('selections'));

        $component->call('checkout');

        // Parent reservation with overall date span
        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
            'quantity' => 2,
        ]);

        // Two child reservations with different date ranges
        $this->assertDatabaseCount('resrv_child_reservations', 2);
    }

    public function test_availability_decremented_per_child()
    {
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry(available: 5);

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 3)
            ->call('addSelections')
            ->call('checkout');

        // Availability should be decremented by 3
        $availabilities = Availability::where('statamic_id', $entryId)
            ->where('rate_id', $adultsRate->id)
            ->whereBetween('date', [today()->addDay(), today()->addDays(2)])
            ->get();

        foreach ($availabilities as $avail) {
            $this->assertEquals(2, $avail->available);
        }
    }

    public function test_checkout_errors_without_selections()
    {
        [$entryId] = $this->getEntryAndRates();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('checkout')
            ->assertHasErrors('availability');
    }

    public function test_checkout_clears_selections_on_success()
    {
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections')
            ->call('checkout');

        $this->assertEmpty($component->get('selections'));
    }

    public function test_checkout_redirects_to_checkout_page()
    {
        $checkoutPage = $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections')
            ->call('checkout')
            ->assertRedirect($checkoutPage->url());
    }

    public function test_child_reservations_store_price()
    {
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('checkout');

        $child = ChildReservation::where('rate_id', $adultsRate->id)->first();
        $this->assertNotNull($child->price);
        // 2 days at 50/day * quantity 2 = 200.00
        $this->assertEquals('200.00', $child->price);
    }

    public function test_parent_reservation_price_is_total_of_children()
    {
        $this->createCheckoutEntry();

        // Adults: 50/day, Children: 30/day
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload());

        // Adults: 2 days * 50/day * qty 1 = 100
        // Children: 2 days * 30/day * qty 2 = 120
        $component
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 2)
            ->call('addSelections')
            ->call('checkout');

        $reservation = Reservation::where('type', 'parent')->first();
        $this->assertEquals('220.00', $reservation->price->format());
    }

    public function test_search_resets_rate_quantities()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 3)
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->assertSet('rateQuantities', []);
    }

    public function test_multiple_add_selections_accumulate()
    {
        [$entryId, $rates] = $this->getEntryAndRates();
        $rateId = $rates->first()->id;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $rateId, 1)
            ->call('addSelections')
            ->call('updateRateQuantity', $rateId, 2)
            ->call('addSelections');

        $this->assertCount(2, $component->get('selections'));
    }

    public function test_overlapping_cart_lines_aggregate_for_availability_check()
    {
        $this->createCheckoutEntry();

        // Create entry with availability of 4
        [$entryId, $adultsRate] = $this->createMultiRateEntry(available: 4);

        // Add two selections of 3 each for the same rate+dates.
        // Each passes individually (3 <= 4) but combined (6 > 4) should fail.
        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 3)
            ->call('addSelections')
            ->call('updateRateQuantity', $adultsRate->id, 3)
            ->call('addSelections');

        $this->assertCount(2, $component->get('selections'));

        // Checkout should fail because aggregated quantity (6) > available (4)
        $component->call('checkout')
            ->assertHasErrors('availability');

        $this->assertDatabaseMissing('resrv_reservations', ['item_id' => $entryId]);
    }

    public function test_overlapping_cart_lines_pass_when_within_availability()
    {
        $this->createCheckoutEntry();

        // Create entry with availability of 5
        [$entryId, $adultsRate] = $this->createMultiRateEntry(available: 5);

        // Add two selections of 2 each (combined = 4, within limit of 5)
        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    public function test_search_quantity_does_not_inflate_per_rate_prices()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        // Simulate a search with quantity=3 (from shared search state)
        $payload = $this->searchPayload();
        $payload['quantity'] = 3;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $payload);

        // The availability prices should be per-unit (quantity=1),
        // not multiplied by the search quantity
        $availability = $component->get('availability');
        $adultsData = $availability->get($adultsRate->id);

        // 2 days at 50/day = 100.00 per unit (NOT 300.00 for qty 3)
        $this->assertEquals('100.00', data_get($adultsData, 'data.price'));
    }

    public function test_search_does_not_mutate_session_quantity()
    {
        [$entryId] = $this->createMultiRateEntry();

        $payload = $this->searchPayload();
        $payload['quantity'] = 3;

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $payload);

        // After getAvailability, the session data.quantity must still be 3
        $this->assertEquals(3, $component->get('data.quantity'));
    }

    public function test_max_quantity_enforced_on_parent_total()
    {
        Config::set('resrv-config.maximum_quantity', 3);

        [$entryId] = $this->createMultiRateEntry(available: 10);

        // Create parent reservation directly with total qty 4
        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'item_id' => $entryId,
            'quantity' => 4,
            'price' => '200.00',
            'payment' => '200.00',
        ]);

        // Two children: qty 2 each = total 4, exceeds max of 3
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'quantity' => 2,
        ]);
        ChildReservation::factory()->create([
            'reservation_id' => $reservation->id,
            'quantity' => 2,
        ]);

        $this->expectException(ReservationException::class);
        $reservation->validateReservation(
            array_merge(
                ['date_start' => $reservation->date_start, 'date_end' => $reservation->date_end, 'quantity' => 4, 'rate_id' => null],
                ['payment' => $reservation->payment, 'price' => $reservation->price, 'total' => $reservation->price]
            ),
            $reservation->item_id,
            checkExtras: false,
            checkOptions: false,
        );
    }

    // --- Bug 1: ignore_quantity_for_prices ---

    public function test_total_price_ignores_quantity_when_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        // 2 days at 50/day = 100.00 per unit; with ignore_quantity, total stays 100 (not 200)
        $this->assertEquals('100.00', $component->totalPrice);
    }

    public function test_child_reservation_price_ignores_quantity_when_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('checkout');

        $child = ChildReservation::where('rate_id', $adultsRate->id)->first();
        // Per-unit price preserved (not multiplied by quantity)
        $this->assertEquals('100.00', $child->price);
    }

    public function test_parent_price_ignores_quantity_when_configured()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);
        $this->createCheckoutEntry();

        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('updateRateQuantity', $childrenRate->id, 3)
            ->call('addSelections')
            ->call('checkout');

        $reservation = Reservation::where('type', 'parent')->first();
        // Adults: 2 days * 50/day = 100, Children: 2 days * 30/day = 60 (quantities ignored for pricing)
        $this->assertEquals('160.00', $reservation->price->format());
    }

    // --- Bug 2: extras/options aggregated across selections ---

    public function test_extras_child_component_displays_aggregated_prices_for_selections()
    {
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        // Build the multi-results cart, which persists selections to session.
        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 1)
            ->call('addSelections');

        // Now mount Extras the same way the multi-results blade does — with
        // useMultiSelections=true so the component opts into the cart pricing
        // path. Since selections exist in session, the displayed available
        // extras should reflect aggregated pricing across the whole cart, not
        // just the most recent search.
        $extrasComponent = Livewire::test(Extras::class, [
            'entryId' => $entryId,
            'useMultiSelections' => true,
        ]);

        // Per-day extra at 4.65/day. 2 selections × 2 days = 4 days × 4.65 = 18.60
        $this->assertEquals('18.60', $extrasComponent->get('extras')->first()->price);
    }

    public function test_options_child_component_displays_aggregated_prices_for_selections()
    {
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entryId]);

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 1)
            ->call('addSelections');

        // Mount Options as the multi-results blade does — with
        // useMultiSelections=true so the component opts into the cart pricing
        // path. Selections in session should drive aggregated value pricing
        // for available options.
        $optionsComponent = Livewire::test(Options::class, [
            'entryId' => $entryId,
            'useMultiSelections' => true,
        ]);

        $option = $optionsComponent->get('options')->first();
        // OptionValue at 22.75/day perday. 2 selections × 2 days each × 22.75 = 91.00
        $this->assertEquals('91.00', $option->values->first()->price->format());
    }

    public function test_extras_price_aggregated_across_selections()
    {
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 1)
            ->call('addSelections');

        // Dispatch extras-updated (simulating the Extras child component)
        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30', // initial single-search price (will be recalculated)
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        $extras = $component->get('enabledExtras.extras');
        // Extra is 4.65/day perday. 2 selections × 2 days each × 4.65 = 18.60
        $this->assertEquals('18.60', $extras->first()['price']);
    }

    public function test_extras_price_recalculated_on_remove_selection()
    {
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 1)
            ->call('addSelections');

        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30',
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        $this->assertEquals('18.60', $component->get('enabledExtras.extras')->first()['price']);

        // Remove one selection — price should drop to one selection's worth
        $component->call('removeSelection', 0);

        $this->assertEquals('9.30', $component->get('enabledExtras.extras')->first()['price']);
    }

    public function test_options_price_aggregated_across_selections()
    {
        [$entryId, $adultsRate, $childrenRate] = $this->createMultiRateEntry();

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entryId]);

        $value = $option->values->first();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('updateRateQuantity', $childrenRate->id, 1)
            ->call('addSelections');

        // Dispatch options-updated (simulating the Options child component)
        $component->dispatch('options-updated', [[
            'id' => $option->id,
            'value' => $value->id,
            'price' => '45.50', // initial single-search price (will be recalculated)
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]]);

        $options = $component->get('enabledOptions.options');
        // OptionValue is 22.75/day perday. 2 selections × 2 days each × 22.75 = 91.00
        $this->assertEquals('91.00', $options->first()['price']);
    }

    // --- Bug 3: totalPrice includes extras/options ---

    public function test_total_price_includes_extras()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections');

        // Reservation subtotal: 2 days * 50/day * qty 1 = 100.00
        $this->assertEquals('100.00', $component->totalPrice);

        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30',
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        // Extra: 4.65/day * 2 days = 9.30 for 1 selection
        // Total: 100.00 + 9.30 = 109.30
        $this->assertEquals('109.30', $component->totalPrice);
    }

    public function test_total_price_includes_options()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entryId]);

        $value = $option->values->first();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections');

        $this->assertEquals('100.00', $component->totalPrice);

        $component->dispatch('options-updated', [[
            'id' => $option->id,
            'value' => $value->id,
            'price' => '45.50',
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]]);

        // Option: 22.75/day * 2 days = 45.50 for 1 selection
        // Total: 100.00 + 45.50 = 145.50
        $this->assertEquals('145.50', $component->totalPrice);
    }

    // --- Fix 1: Clear addon state when selections are empty ---

    public function test_clear_selections_resets_extras_and_options()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entryId]);

        $value = $option->values->first();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections');

        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30',
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        $component->dispatch('options-updated', [[
            'id' => $option->id,
            'value' => $value->id,
            'price' => '45.50',
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]]);

        $this->assertTrue($component->get('enabledExtras.extras')->isNotEmpty());
        $this->assertTrue($component->get('enabledOptions.options')->isNotEmpty());

        $component->call('clearSelections');

        $this->assertTrue($component->get('enabledExtras.extras')->isEmpty());
        $this->assertTrue($component->get('enabledOptions.options')->isEmpty());
    }

    public function test_remove_last_selection_resets_extras_and_options()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections');

        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30',
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        $this->assertTrue($component->get('enabledExtras.extras')->isNotEmpty());

        $component->call('removeSelection', 0);

        $this->assertTrue($component->get('enabledExtras.extras')->isEmpty());
    }

    // --- Fix 2: Reject total quantity exceeding maximum_quantity ---

    public function test_checkout_rejects_total_quantity_exceeding_maximum()
    {
        Config::set('resrv-config.maximum_quantity', 3);
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry(available: 10);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        // Total qty = 4, exceeds max of 3
        $component->call('checkout')
            ->assertHasErrors('availability');

        $this->assertDatabaseMissing('resrv_reservations', ['item_id' => $entryId]);
    }

    public function test_checkout_allows_total_quantity_at_maximum()
    {
        Config::set('resrv-config.maximum_quantity', 4);
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry(available: 10);

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections')
            ->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    // --- Fix 3: Cross-validate overlapping date ranges ---

    protected function createMultiRateEntryWithDays(int $days, int $available = 5): array
    {
        $dates = collect(range(0, $days - 1))->map(fn ($i) => today()->addDays($i))->all();

        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'multi-extended',
            available: $available,
            price: 50,
            rateSlug: 'adults',
            customAvailability: ['dates' => $dates],
        );

        $adultsRate = Rate::forEntry($entry->id())->where('slug', 'adults')->first();

        return [$entry->id(), $adultsRate];
    }

    public function test_overlapping_date_ranges_reject_when_cumulative_exceeds_availability()
    {
        $this->createCheckoutEntry();

        // 8 days of availability, 3 units per day
        [$entryId, $adultsRate] = $this->createMultiRateEntryWithDays(days: 8, available: 3);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Line A: day 0-4, qty 2 (covers days 0,1,2,3)
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->toISOString(),
            dateEnd: today()->addDays(4)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        // Line B: day 2-6, qty 2 (covers days 2,3,4,5)
        // Overlap on days 2,3 → cumulative demand = 4, exceeds available = 3
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->addDays(2)->toISOString(),
            dateEnd: today()->addDays(6)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        $this->assertCount(2, $component->get('selections'));

        $component->call('checkout')
            ->assertHasErrors('availability');

        $this->assertDatabaseMissing('resrv_reservations', ['item_id' => $entryId]);
    }

    public function test_overlapping_date_ranges_pass_when_cumulative_within_availability()
    {
        $this->createCheckoutEntry();

        // 8 days of availability, 5 units per day
        [$entryId, $adultsRate] = $this->createMultiRateEntryWithDays(days: 8, available: 5);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Line A: day 0-4, qty 2
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->toISOString(),
            dateEnd: today()->addDays(4)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        // Line B: day 2-6, qty 2
        // Overlap days 2,3 → cumulative = 4, within available = 5
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->addDays(2)->toISOString(),
            dateEnd: today()->addDays(6)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    public function test_non_overlapping_date_ranges_pass_independently()
    {
        $this->createCheckoutEntry();

        // 8 days, 3 units per day
        [$entryId, $adultsRate] = $this->createMultiRateEntryWithDays(days: 8, available: 3);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Line A: day 0-2, qty 3 (uses full capacity)
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->toISOString(),
            dateEnd: today()->addDays(2)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 3)
            ->call('addSelections');

        // Line B: day 4-6, qty 3 (no overlap with A)
        $component->dispatch('availability-search-updated', $this->searchPayload(
            dateStart: today()->addDays(4)->toISOString(),
            dateEnd: today()->addDays(6)->toISOString(),
        ))
            ->call('updateRateQuantity', $adultsRate->id, 3)
            ->call('addSelections');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    protected function createSharedRateEntry(int $poolSize = 3): array
    {
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'shared-pool',
            available: $poolSize,
            price: 50,
            rateSlug: 'base-rate',
        );

        $baseRate = Rate::forEntry($entry->id())->where('slug', 'base-rate')->first();

        $sharedAdults = Rate::factory()->shared()->create([
            'collection' => 'shared-pool',
            'slug' => 'shared-adults',
            'title' => 'Shared Adults',
            'base_rate_id' => $baseRate->id,
            'max_available' => $poolSize,
        ]);

        $sharedChildren = Rate::factory()->shared()->create([
            'collection' => 'shared-pool',
            'slug' => 'shared-children',
            'title' => 'Shared Children',
            'base_rate_id' => $baseRate->id,
            'max_available' => $poolSize,
        ]);

        return [$entry->id(), $baseRate, $sharedAdults, $sharedChildren];
    }

    public function test_shared_rates_aggregate_demand_across_base_pool()
    {
        $this->createCheckoutEntry();
        [$entryId, $baseRate, $sharedAdults, $sharedChildren] = $this->createSharedRateEntry(3);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Add adults=2 for days 1-3
        $component->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $sharedAdults->id, 2)
            ->call('addSelections');

        // Add children=2 for same days — combined demand (4) exceeds pool (3)
        $component->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $sharedChildren->id, 2)
            ->call('addSelections');

        // Checkout should fail because combined demand (4) > pool (3)
        $component->call('checkout')
            ->assertHasErrors('availability');
    }

    public function test_shared_rates_pass_when_combined_demand_within_pool()
    {
        $this->createCheckoutEntry();
        [$entryId, $baseRate, $sharedAdults, $sharedChildren] = $this->createSharedRateEntry(5);

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        // Add adults=2 for days 1-3
        $component->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $sharedAdults->id, 2)
            ->call('addSelections');

        // Add children=2 for same days — combined demand (4) within pool (5)
        $component->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $sharedChildren->id, 2)
            ->call('addSelections');

        $component->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    public function test_selections_cleared_when_mounting_new_entry()
    {
        [$entryIdA, $adultsRateA, $childrenRateA] = $this->createMultiRateEntry();

        // Mount on entry A and add a selection
        $componentA = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryIdA])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRateA->id, 1)
            ->call('addSelections');

        $this->assertNotEmpty($componentA->get('selections'));

        // Use a different entry (from advancedEntries, different collection) to avoid slug conflicts
        $entryIdB = $this->advancedEntries->first()->id();

        // Mount on entry B — selections should be cleared
        $componentB = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryIdB]);

        $this->assertEmpty($componentB->get('selections'));
    }

    /**
     * Regression: refreshing the same entry must NOT wipe the in-progress cart.
     * The selections (and any selected extras/options) are session-backed and
     * should be restored when re-mounting the same entry.
     */
    public function test_selections_preserved_when_remounting_same_entry()
    {
        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        // Mount, add a selection — this persists the cart in the session and
        // marks the cart owner.
        $componentA = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 2)
            ->call('addSelections');

        $this->assertCount(1, $componentA->get('selections'));
        $this->assertEquals(2, $componentA->get('selections.0.quantity'));

        // Re-mount the SAME entry (simulates a page refresh). The cart should
        // be restored from session, not wiped.
        $componentB = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        $this->assertCount(1, $componentB->get('selections'));
        $this->assertEquals($adultsRate->id, $componentB->get('selections.0.rate_id'));
        $this->assertEquals(2, $componentB->get('selections.0.quantity'));
    }

    public function test_checkout_preserves_session_addons_for_checkout_to_consume()
    {
        // When AvailabilityMultiResults converts the cart into a reservation,
        // it must NOT immediately wipe resrv-extras / resrv-options from the
        // session. Checkout (specifically when enableExtrasStep === false)
        // hydrates its own enabledExtras/enabledOptions from those keys and
        // copies them onto the reservation; clearing them here would silently
        // drop the user's add-ons before they are charged for them.
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entryId]);
        $value = $option->values->first();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections');

        $component->dispatch('extras-updated', [[
            'id' => $extra->id,
            'price' => '9.30',
            'name' => $extra->name,
            'quantity' => 1,
        ]]);

        $component->dispatch('options-updated', [[
            'id' => $option->id,
            'value' => $value->id,
            'price' => '45.50',
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]]);

        $this->assertTrue($component->get('enabledExtras.extras')->isNotEmpty());
        $this->assertTrue($component->get('enabledOptions.options')->isNotEmpty());

        $component->call('checkout');

        // The cart itself is consumed: selections are emptied and the cart
        // owner is forgotten so the next visit starts a fresh cart.
        $this->assertEmpty($component->get('selections'));
        $this->assertNull(session(AvailabilityMultiResults::CART_OWNER_SESSION_KEY));

        // But the session-backed add-ons survive past checkout() so the
        // Checkout component can copy them onto the reservation.
        $this->assertNotEmpty(session('resrv-extras'));
        $this->assertNotEmpty(session('resrv-options'));
    }

    public function test_next_multi_results_visit_clears_stale_session_addons()
    {
        // After checkout() preserves the session add-ons for Checkout to
        // consume, the next time the user visits a multi-results page the
        // mount() should sweep the stale state away — the cart owner is gone,
        // so its add-ons no longer apply to the new visit.
        $this->createCheckoutEntry();

        [$entryId, $adultsRate] = $this->createMultiRateEntry();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryId)->extras()->attach($extra->id);

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRate->id, 1)
            ->call('addSelections')
            ->dispatch('extras-updated', [[
                'id' => $extra->id,
                'price' => '9.30',
                'name' => $extra->name,
                'quantity' => 1,
            ]])
            ->call('checkout');

        // Sanity: session add-ons survived checkout() (covered by the previous test).
        $this->assertNotEmpty(session('resrv-extras'));

        // Now simulate the user revisiting the multi-results page. mount()
        // sees no cart owner and wipes the stale session state.
        $next = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId]);

        $this->assertEmpty($next->get('selections'));
        $this->assertTrue($next->get('enabledExtras.extras')->isEmpty());
        $this->assertTrue($next->get('enabledOptions.options')->isEmpty());
    }

    public function test_extras_ignore_multi_selections_for_unrelated_entry()
    {
        // Build an in-progress multi cart on entry A. This stamps the cart
        // owner session key with A's id. Entry B is a *different* entry
        // (different collection) that the user might visit afterwards via a
        // regular AvailabilityResults page.
        [$entryIdA, $adultsRateA] = $this->createMultiRateEntry();
        $entryIdB = $this->advancedEntries->first()->id();

        $extra = ResrvExtra::factory()->create();
        ResrvEntry::whereItemId($entryIdB)->extras()->attach($extra->id);

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryIdA])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRateA->id, 1)
            ->call('addSelections');

        // Sanity check: the multi cart and its owner are still in session.
        $this->assertEquals($entryIdA, session(AvailabilityMultiResults::CART_OWNER_SESSION_KEY));
        $this->assertNotEmpty(session('resrv-multi-selections'));

        // Spin up an instance of the trait via an anonymous component to call
        // getMultiSelectionsFromSession directly. The integration path
        // (mounting Extras with a typed AvailabilityData prop) is awkward to
        // exercise from a Livewire test, but the guard itself is a thin
        // session-key check whose behaviour is fully captured here.
        // The anonymous component opts into the multi-cart pricing path
        // ($useMultiSelections = true), mirroring the way the multi-results
        // blade renders the real Extras component.
        $component = new class
        {
            use HandlesExtrasQueries;

            public ?string $entryId = null;

            public bool $useMultiSelections = true;

            public function callGetMultiSelections(): ?array
            {
                return $this->getMultiSelectionsFromSession();
            }
        };

        $component->entryId = $entryIdB;
        $this->assertNull($component->callGetMultiSelections(), 'Stale multi cart for entry A must not leak into entry B');

        $component->entryId = $entryIdA;
        $this->assertNotNull($component->callGetMultiSelections(), 'Cart owner entry A must still see its own selections');

        // Standard availability-results flow: $useMultiSelections defaults to
        // false on the Extras component. Even when the cart owner matches,
        // the Extras component must NOT consult the multi cart, otherwise an
        // in-progress cart for entry A would hijack the regular search
        // pricing on the same entry.
        $component->useMultiSelections = false;
        $component->entryId = $entryIdA;
        $this->assertNull($component->callGetMultiSelections(), 'Standard search flow must opt out of cart selections');
    }

    public function test_options_ignore_multi_selections_for_unrelated_entry()
    {
        [$entryIdA, $adultsRateA] = $this->createMultiRateEntry();
        $entryIdB = $this->advancedEntries->first()->id();

        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryIdA])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $adultsRateA->id, 1)
            ->call('addSelections');

        $this->assertEquals($entryIdA, session(AvailabilityMultiResults::CART_OWNER_SESSION_KEY));

        $component = new class
        {
            use HandlesOptionsQueries;

            public ?string $entryId = null;

            public bool $useMultiSelections = true;

            public function callGetMultiSelections(): ?array
            {
                return $this->getMultiSelectionsFromSessionForOptions();
            }
        };

        $component->entryId = $entryIdB;
        $this->assertNull($component->callGetMultiSelections(), 'Stale multi cart for entry A must not leak into entry B');

        $component->entryId = $entryIdA;
        $this->assertNotNull($component->callGetMultiSelections(), 'Cart owner entry A must still see its own selections');

        // Standard availability-results flow: $useMultiSelections defaults to
        // false on the Options component. Even when the cart owner matches,
        // it must NOT consult the multi cart, otherwise an in-progress cart
        // for entry A would hijack the regular search pricing on the same
        // entry.
        $component->useMultiSelections = false;
        $component->entryId = $entryIdA;
        $this->assertNull($component->callGetMultiSelections(), 'Standard search flow must opt out of cart selections');
    }

    public function test_shared_rates_validate_each_rate_against_its_own_max_available()
    {
        $this->createCheckoutEntry();

        // Two shared siblings on the same base, with different max_available
        // values. Without per-rate validation, the cart's outcome would depend
        // on which sibling Eloquent returns first.
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'mixed-caps-pool',
            available: 100,
            price: 50,
            rateSlug: 'base-mixed',
        );
        $entryId = $entry->id();
        $baseRate = Rate::forEntry($entryId)->where('slug', 'base-mixed')->first();

        $smallCapRate = Rate::factory()->shared()->create([
            'collection' => 'mixed-caps-pool',
            'slug' => 'small-cap',
            'title' => 'Small Cap',
            'base_rate_id' => $baseRate->id,
            'max_available' => 2,
        ]);

        $largeCapRate = Rate::factory()->shared()->create([
            'collection' => 'mixed-caps-pool',
            'slug' => 'large-cap',
            'title' => 'Large Cap',
            'base_rate_id' => $baseRate->id,
            'max_available' => 10,
        ]);

        // Booking 4 of the LARGE-cap rate should succeed (4 ≤ 10) even though
        // the small sibling caps at 2. The previous arbitrary-sibling code
        // could reject this depending on iteration order.
        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $largeCapRate->id, 4)
            ->call('addSelections')
            ->call('checkout');

        $this->assertDatabaseHas('resrv_reservations', [
            'item_id' => $entryId,
            'type' => 'parent',
        ]);
    }

    public function test_shared_rates_reject_when_single_rate_exceeds_its_own_cap()
    {
        $this->createCheckoutEntry();

        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'over-cap-pool',
            available: 100,
            price: 50,
            rateSlug: 'base-overcap',
        );
        $entryId = $entry->id();
        $baseRate = Rate::forEntry($entryId)->where('slug', 'base-overcap')->first();

        $cappedRate = Rate::factory()->shared()->create([
            'collection' => 'over-cap-pool',
            'slug' => 'capped',
            'title' => 'Capped',
            'base_rate_id' => $baseRate->id,
            'max_available' => 2,
        ]);

        // Demand 3 of a rate capped at 2 — must fail regardless of any sibling.
        Livewire::test(AvailabilityMultiResults::class, ['entry' => $entryId])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $cappedRate->id, 3)
            ->call('addSelections')
            ->call('checkout')
            ->assertHasErrors('availability');

        $this->assertDatabaseMissing('resrv_reservations', ['item_id' => $entryId]);
    }
}
