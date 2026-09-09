<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityMultiResults;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Livewire\Options;
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
use Statamic\Facades\Blueprint;

/**
 * Cart (AvailabilityMultiResults) coverage for Rate::units_per_addon, plus the hand-off into
 * Checkout::handleFirstStep. Cruise example throughout: a 'double' independent rate (100/night)
 * and a 'single' relative (-25%) shared rate with units_per_addon = 2 whose stock lives on the
 * base rate; port taxes are a fixed 350 extra. A party of three books double at quantity 2 and
 * single at quantity 2 over two nights: cabins 400 + 300 = 700, port taxes 350×2 + 350×1 = 1050.
 */
class AvailabilityMultiResultsUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setHour(12));
        $this->date = now()->addDay()->setTime(12, 0, 0);
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

    /**
     * @return array{0: Entry, 1: Rate, 2: Rate, 3: ResrvExtra} [$entry, $double, $single, $portTaxes]
     */
    protected function createCabinEntry(): array
    {
        // 4 availability rows (today..today+3) on the 'double' rate cover the searched nights today+1/today+2.
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'double',
        );

        $double = Rate::where('collection', 'cabins')->where('slug', 'double')->firstOrFail();

        $single = Rate::factory()->relative()->shared()->unitsPerAddon(2)->create([
            'collection' => 'cabins',
            'slug' => 'single',
            'title' => 'Single occupancy',
            'base_rate_id' => $double->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
        ]);

        $portTaxes = ResrvExtra::factory()->fixed()->create([
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '350',
        ]);

        ResrvEntry::whereItemId($entry->id())->extras()->attach($portTaxes->id);

        return [$entry, $double, $single, $portTaxes];
    }

    protected function searchPayload(): array
    {
        return [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'rate' => 'any',
        ];
    }

    /**
     * Party of three: double at quantity 2 + single at quantity 2, both over the same two nights.
     */
    protected function buildPartyOfThreeCart(Entry $entry, Rate $double, Rate $single): Testable
    {
        return Livewire::test(AvailabilityMultiResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $double->id, 2)
            ->call('updateRateQuantity', $single->id, 2)
            ->call('addSelections');
    }

    /**
     * The payload the Extras child dispatches to the cart: a plain list whose price the cart
     * overwrites with the across-selections aggregate.
     */
    protected function portTaxesCartPayload(ResrvExtra $portTaxes): array
    {
        return [[
            'id' => $portTaxes->id,
            'price' => '0',
            'name' => $portTaxes->name,
            'quantity' => 1,
        ]];
    }

    /**
     * The payload the Extras child dispatches to Checkout: id-keyed, with a PER-UNIT price.
     */
    protected function portTaxesCheckoutPayload(ResrvExtra $portTaxes, string $price): array
    {
        return [$portTaxes->id => [
            'id' => $portTaxes->id,
            'quantity' => 1,
            'price' => $price,
            'name' => $portTaxes->name,
        ]];
    }

    protected function createFixedOption(Entry $entry): array
    {
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);

        return [$option, $option->values->first()];
    }

    protected function optionCartPayload(Option $option, OptionValue $value): array
    {
        return [[
            'id' => $option->id,
            'value' => $value->id,
            'price' => '0',
            'optionName' => $option->name,
            'valueName' => $value->name,
        ]];
    }

    // --- Cart pricing ---

    public function test_cart_prices_cabins_per_unit_before_add_ons()
    {
        [$entry, $double, $single] = $this->createCabinEntry();

        $component = $this->buildPartyOfThreeCart($entry, $double, $single);

        $selections = collect($component->get('selections'));

        $this->assertCount(2, $selections);
        // Per-unit prices from the quantity=1 search: double 100×2 nights, single 75×2 nights.
        $this->assertEquals('200.00', $selections->firstWhere('rate_id', $double->id)['price']);
        $this->assertEquals('150.00', $selections->firstWhere('rate_id', $single->id)['price']);
        // 200×2 + 150×2 — cabin pricing is NOT affected by units_per_addon.
        $this->assertEquals('700.00', $component->totalPrice);
    }

    /**
     * Port taxes 350 fixed: double selection 350×2 (no divisor) + single selection 350×ceil(2/2)
     * = 1050. The pre-feature behaviour would have been 350×4 = 1400.
     */
    public function test_cart_extra_divides_the_single_rate_quantity_by_units_per_addon()
    {
        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $component = $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes));

        $this->assertEquals('1050.00', $component->get('enabledExtras.extras')->first()['price']);
        // 700 cabins + 1050 port taxes
        $this->assertEquals('1750.00', $component->totalPrice);
    }

    /**
     * Fixed option value 30: double 30×2 + single 30×ceil(2/2) = 90.
     */
    public function test_cart_fixed_option_value_divides_the_single_rate_quantity_by_units_per_addon()
    {
        [$entry, $double, $single] = $this->createCabinEntry();
        [$option, $value] = $this->createFixedOption($entry);

        $component = $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('options-updated', $this->optionCartPayload($option, $value));

        $this->assertEquals('90.00', $component->get('enabledOptions.options')->first()['price']);
        // 700 cabins + 90 option
        $this->assertEquals('790.00', $component->totalPrice);
    }

    /**
     * Per-day option value 22.75 over 2 nights: double 22.75×2×2 = 91 + single 22.75×2×ceil(2/2)
     * = 45.50 → 136.50.
     */
    public function test_cart_perday_option_value_divides_the_single_rate_quantity_by_units_per_addon()
    {
        [$entry, $double, $single] = $this->createCabinEntry();

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $entry->id()]);
        $value = $option->values->first();

        $component = $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('options-updated', $this->optionCartPayload($option, $value));

        $this->assertEquals('136.50', $component->get('enabledOptions.options')->first()['price']);
    }

    /**
     * The global flag wins over the divisor: neither selection multiplies, so port taxes are
     * 350 + 350 = 700 and the cabins are priced per unit (200 + 150).
     */
    public function test_ignore_quantity_for_prices_wins_over_units_per_addon_in_the_cart()
    {
        Config::set('resrv-config.ignore_quantity_for_prices', true);

        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $component = $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes));

        $this->assertEquals('700.00', $component->get('enabledExtras.extras')->first()['price']);
        $this->assertEquals('1050.00', $component->totalPrice);
    }

    // --- Child components read the cart from session ---

    public function test_extras_child_component_aggregates_the_cart_with_units_per_addon()
    {
        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $this->buildPartyOfThreeCart($entry, $double, $single);

        $extras = Livewire::test(Extras::class, [
            'entryId' => $entry->id(),
            'useMultiSelections' => true,
        ])->get('extras');

        $this->assertEquals('1050.00', $extras->firstWhere('id', $portTaxes->id)->price->format());
    }

    public function test_options_child_component_aggregates_the_cart_with_units_per_addon()
    {
        [$entry, $double, $single] = $this->createCabinEntry();
        [$option, $value] = $this->createFixedOption($entry);

        $this->buildPartyOfThreeCart($entry, $double, $single);

        $options = Livewire::test(Options::class, [
            'entryId' => $entry->id(),
            'useMultiSelections' => true,
        ])->get('options');

        $this->assertEquals('90.00', $options->firstWhere('id', $option->id)->values->firstWhere('id', $value->id)->price->format());
    }

    // --- Selection changes ---

    public function test_extra_price_recalculates_per_remaining_selection_when_one_is_removed()
    {
        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $component = $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes));

        $this->assertEquals('1050.00', $component->get('enabledExtras.extras')->first()['price']);

        // Drop the single selection (index 1): only double at quantity 2 remains → 350×2.
        $component->call('removeSelection', 1);

        $this->assertEquals('700.00', $component->get('enabledExtras.extras')->first()['price']);

        // Put the single selection back, then drop the double one (index 0): 350×ceil(2/2).
        $component
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $single->id, 2)
            ->call('addSelections');

        $this->assertEquals('1050.00', $component->get('enabledExtras.extras')->first()['price']);

        $component->call('removeSelection', 0);

        $this->assertEquals('350.00', $component->get('enabledExtras.extras')->first()['price']);
    }

    /**
     * Single at quantity 4 is two guests: 350×ceil(4/2) = 700.
     */
    public function test_single_rate_alone_at_quantity_four_charges_the_extra_for_two_guests()
    {
        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $single->id, 4)
            ->call('addSelections')
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes));

        $this->assertCount(1, $component->get('selections'));
        $this->assertEquals('700.00', $component->get('enabledExtras.extras')->first()['price']);
    }

    /**
     * Two separate single selections of quantity 2 are also two guests: 350×ceil(2/2) each = 700,
     * the same as one selection of quantity 4 — how the guest clicked the cart together is irrelevant.
     */
    public function test_two_single_selections_of_two_charge_the_extra_the_same_as_one_of_four()
    {
        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $component = Livewire::test(AvailabilityMultiResults::class, ['entry' => $entry->id()])
            ->dispatch('availability-search-updated', $this->searchPayload())
            ->call('updateRateQuantity', $single->id, 2)
            ->call('addSelections')
            ->call('updateRateQuantity', $single->id, 2)
            ->call('addSelections')
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes));

        $this->assertCount(2, $component->get('selections'));
        $this->assertEquals('700.00', $component->get('enabledExtras.extras')->first()['price']);
    }

    // --- Checkout ---

    public function test_checkout_stores_cabin_prices_unchanged_and_decrements_the_shared_base_pool()
    {
        $this->createCheckoutEntry();

        [$entry, $double, $single] = $this->createCabinEntry();

        $this->buildPartyOfThreeCart($entry, $double, $single)
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $parent = Reservation::where('type', 'parent')->where('item_id', $entry->id())->firstOrFail();

        // Cabins: units_per_addon only touches add-ons — 400 + 300 at the full booked quantity.
        $this->assertEquals('700.00', $parent->price->format());
        $this->assertEquals(4, $parent->quantity);
        $this->assertEquals('400.00', ChildReservation::where('rate_id', $double->id)->firstOrFail()->price);
        $this->assertEquals('300.00', ChildReservation::where('rate_id', $single->id)->firstOrFail()->price);

        // Stock decrements at the full booked quantity too: 5 - 2 (double) - 2 (single, shared pool).
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $entry->id(),
            'rate_id' => $double->id,
            'date' => $this->date->copy()->startOfDay(),
            'available' => 1,
        ]);
        $this->assertDatabaseMissing('resrv_availabilities', [
            'statamic_id' => $entry->id(),
            'rate_id' => $single->id,
        ]);
    }

    /**
     * End-to-end: the cart's 1050 (double 350×2 + single 350×ceil(2/2)) survives Checkout's
     * server-side recomputation, which prices each child under its own rate.
     */
    public function test_checkout_first_step_accepts_port_taxes_priced_with_units_per_addon()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();

        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $this->buildPartyOfThreeCart($entry, $double, $single)
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $parent = Reservation::where('type', 'parent')->where('item_id', $entry->id())->firstOrFail();

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', $this->portTaxesCheckoutPayload($portTaxes, '1050.00'))
            ->call('handleFirstStep')
            ->assertHasNoErrors(['reservation', 'extras'])
            ->assertSet('step', 2);

        $this->assertEquals('1750.00', $parent->fresh()->total->format());
        $this->assertEquals('1050.00', $parent->fresh()->extraCharges()->format());
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $parent->id,
            'extra_id' => $portTaxes->id,
            'quantity' => 1,
        ]);
    }

    /**
     * With the extras step disabled, Checkout consumes the resrv-extras the cart left in session
     * (already aggregated to 1050 by the cart) and must reach the same 1750 total.
     */
    public function test_checkout_without_extras_step_consumes_the_cart_extras_priced_with_units_per_addon()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();

        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $this->buildPartyOfThreeCart($entry, $double, $single)
            ->dispatch('extras-updated', $this->portTaxesCartPayload($portTaxes))
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $this->assertEquals('1050.00', collect(session('resrv-extras')->extras)->first()['price']);

        $parent = Reservation::where('type', 'parent')->where('item_id', $entry->id())->firstOrFail();

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertHasNoErrors(['reservation', 'extras'])
            ->assertSet('step', 2);

        $this->assertEquals('1750.00', $parent->fresh()->total->format());
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $parent->id,
            'extra_id' => $portTaxes->id,
            'quantity' => 1,
        ]);
    }

    /**
     * The pre-feature price (350×4 = 1400) no longer matches the server-side recomputation, so a
     * client still sending it is rejected as price drift instead of over-charging the guest.
     */
    public function test_checkout_first_step_rejects_port_taxes_priced_without_units_per_addon()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();

        [$entry, $double, $single, $portTaxes] = $this->createCabinEntry();

        $this->buildPartyOfThreeCart($entry, $double, $single)
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $parent = Reservation::where('type', 'parent')->where('item_id', $entry->id())->firstOrFail();

        Livewire::test(Checkout::class)
            ->dispatch('extras-updated', $this->portTaxesCheckoutPayload($portTaxes, '1400.00'))
            ->call('handleFirstStep')
            ->assertHasErrors(['reservation'])
            ->assertSet('step', 1);

        $this->assertDatabaseMissing('resrv_reservation_extra', [
            'reservation_id' => $parent->id,
        ]);
    }
}
