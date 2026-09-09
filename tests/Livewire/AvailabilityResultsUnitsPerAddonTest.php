<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityMultiResults;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Livewire\Options;
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
 * Pre-checkout add-ons (Extras/Options rendered inside AvailabilityResults) under
 * Rate::units_per_addon. A results page with rates disabled (the default) searches without a
 * rate, but the availability engine still books a concrete rate; the add-on components must
 * price against THAT rate, otherwise a divisor rate shows/stores the undivided price and the
 * checkout rejects the booking as price drift.
 *
 * Fixture: a 'cabins' entry whose only rate, 'single', has units_per_addon = 2 with its own
 * 100/night rows (today..today+3). Port taxes are a fixed 27.50 extra; the option value is a
 * fixed 30. Every search spans 2 nights (tomorrow → +2) at quantity 2, so the divided add-on
 * price is 27.50 / 30.00 and the undivided one 55.00 / 60.00. A 'double' rate without a divisor
 * (own 100/night rows) is added only by the rate-switch tests.
 */
class AvailabilityResultsUnitsPerAddonTest extends TestCase
{
    use CreatesEntries;
    use RefreshDatabase;

    public $date;

    public $entry;

    public $single;

    public $portTaxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setHour(12));
        $this->date = now()->addDay()->setTime(12, 0, 0);

        $this->entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'single',
            title: 'Cruise cabin',
        );

        $this->single = Rate::where('collection', 'cabins')->where('slug', 'single')->firstOrFail();
        $this->single->update(['units_per_addon' => 2]);

        $this->portTaxes = ResrvExtra::factory()->fixed()->create([
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '27.50',
        ]);

        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($this->portTaxes->id);
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

    protected function addDoubleRate(): Rate
    {
        $double = Rate::factory()->create([
            'collection' => 'cabins',
            'slug' => 'double',
            'title' => 'Double',
        ]);

        $this->createAvailabilityForEntry($this->entry, 100, 5, $double->id, 4);

        return $double;
    }

    protected function createWifiExtra(): ResrvExtra
    {
        $wifi = ResrvExtra::factory()->create([
            'id' => 9,
            'name' => 'Wifi',
            'slug' => 'wifi',
            'price' => '10',
            'price_type' => 'perday',
        ]);

        ResrvEntry::whereItemId($this->entry->id())->extras()->attach($wifi->id);

        return $wifi;
    }

    /**
     * What the stock calendar's clear button does: AvailabilitySearch::clearDates() resets the
     * form, persists the now dateless search to the shared session and broadcasts it.
     */
    protected function clearSearch(): void
    {
        Livewire::test(AvailabilitySearch::class, ['entry' => $this->entry->id()])
            ->call('clearDates')
            ->assertSet('data.dates', []);

        $this->assertFalse(session('resrv-search')->hasDates());
    }

    protected function createFixedOption(): array
    {
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $this->entry->id()]);

        return [$option, $option->values->first()];
    }

    protected function createPerdayOption(): array
    {
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory(), 'values')
            ->create(['item_id' => $this->entry->id()]);

        return [$option, $option->values->first()];
    }

    protected function searchPayload(int $quantity = 2, int $nights = 2, $rate = null): array
    {
        return [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add($nights, 'day')->toISOString(),
            ],
            'quantity' => $quantity,
            'rate' => $rate,
        ];
    }

    /**
     * Run a search through the results component. Its dehydrate persists the reconciled search
     * to the shared 'resrv-search' session key, exactly what the child components read.
     */
    protected function search(array $payload, array $params = []): Testable
    {
        return Livewire::test(AvailabilityResults::class, array_merge(['entry' => $this->entry->id()], $params))
            ->dispatch('availability-search-updated', $payload)
            ->assertHasNoErrors();
    }

    protected function extrasChild(array $params = []): Testable
    {
        return Livewire::test(Extras::class, array_merge([
            'entryId' => $this->entry->id(),
            'data' => session('resrv-search'),
        ], $params));
    }

    protected function optionsChild(array $params = []): Testable
    {
        return Livewire::test(Options::class, array_merge([
            'entryId' => $this->entry->id(),
            'data' => session('resrv-search'),
        ], $params));
    }

    protected function portTaxesPrice(Testable $extras): string
    {
        return $extras->extras->firstWhere('id', $this->portTaxes->id)->price->format();
    }

    protected function valuePrice(Testable $options, Option $option, OptionValue $value): string
    {
        return $options->options->firstWhere('id', $option->id)->values->firstWhere('id', $value->id)->price->format();
    }

    // --- Results page ---

    /**
     * Rates disabled, quantity 2: the engine books the 'single' rate, so the add-ons rendered
     * next to the results are divided (27.50 / 30.00), not per berth (55.00 / 60.00), and the
     * results event tells the children which rate was booked.
     */
    public function test_results_page_prices_pre_checkout_add_ons_under_the_rate_it_will_book()
    {
        $this->createFixedOption();

        $this->search($this->searchPayload(), ['showExtras' => true, 'showOptions' => true])
            ->assertViewHas('availability.data.rate_id', $this->single->id)
            ->assertDispatched('availability-results-updated', rateId: (int) $this->single->id)
            ->assertSee('27.50')
            ->assertDontSee('55.00')
            ->assertSee('30.00')
            ->assertDontSee('60.00');
    }

    // --- Extras child ---

    public function test_extras_child_prices_under_the_resolved_rate_it_is_mounted_with()
    {
        $this->search($this->searchPayload());

        $extras = $this->extrasChild(['effectiveRateId' => $this->single->id]);

        $this->assertEquals('27.50', $this->portTaxesPrice($extras));

        $extras->call('toggleExtra', $this->portTaxes->id)
            ->assertDispatched('extras-updated');

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->first()['price']);
    }

    /**
     * A customised results view that does not pass the resolved rate still converges: the
     * results event carries it, and the child re-prices its list and its selection.
     */
    public function test_extras_child_without_the_resolved_rate_is_corrected_by_the_results_event()
    {
        $this->search($this->searchPayload());

        $extras = $this->extrasChild();

        $this->assertEquals('55.00', $this->portTaxesPrice($extras));

        $extras->call('toggleExtra', $this->portTaxes->id);

        $this->assertEquals('55.00', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);

        $extras->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertDispatched('extras-updated');

        $this->assertEquals('27.50', $this->portTaxesPrice($extras));
        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->first()['price']);
    }

    public function test_extras_child_ignores_results_events_that_do_not_change_its_rate()
    {
        $this->search($this->searchPayload());

        $extras = $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->call('toggleExtra', $this->portTaxes->id);

        $extras->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertNotDispatched('extras-updated');

        $extras->dispatch('availability-results-updated')
            ->assertNotDispatched('extras-updated');

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
    }

    /**
     * A new search (dates change) re-prices under the rate the child already resolved, so a
     * per-day extra at 10/day goes 20 → 30 for 2 → 3 nights instead of 40 → 60.
     */
    public function test_extras_child_keeps_the_resolved_rate_when_the_search_changes()
    {
        $wifi = $this->createWifiExtra();

        $this->search($this->searchPayload());

        $extras = $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->call('toggleExtra', $wifi->id);

        $this->assertEquals('20.00', $extras->get('enabledExtras.extras')->get($wifi->id)['price']);

        $this->search($this->searchPayload(nights: 3));

        $extras->dispatch('availability-search-updated')
            ->assertDispatched('extras-updated');

        $this->assertEquals('30.00', $extras->extras->firstWhere('id', $wifi->id)->price->format());
        $this->assertEquals('30.00', $extras->get('enabledExtras.extras')->get($wifi->id)['price']);
    }

    /**
     * Clearing the calendar broadcasts a dateless search. Pricing against it would store a
     * per-day extra at 0.00 (duration 0) in the session; the selection must keep its last price
     * until a real search arrives.
     */
    public function test_extras_child_keeps_its_prices_when_the_search_is_cleared()
    {
        $wifi = $this->createWifiExtra();

        $this->search($this->searchPayload());

        $extras = $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->call('toggleExtra', $wifi->id);

        $this->assertEquals('20.00', $extras->get('enabledExtras.extras')->get($wifi->id)['price']);

        $this->clearSearch();

        $extras->dispatch('availability-search-updated')
            ->assertNotDispatched('extras-updated');

        $this->assertEquals('20.00', $extras->get('enabledExtras.extras')->get($wifi->id)['price']);
        $this->assertEquals('20.00', collect(session('resrv-extras')->extras)->get($wifi->id)['price']);
    }

    /**
     * A selection carried in the session but absent from this instance's (filtered) list keeps
     * its stored price instead of fataling on a missing extra; the checkout re-validates it.
     */
    public function test_extras_child_keeps_the_stored_price_of_a_selection_outside_its_list()
    {
        $wifi = $this->createWifiExtra();

        $this->search($this->searchPayload());

        $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->call('toggleExtra', $wifi->id);

        // A second instance that only lists port taxes inherits the wifi selection from session.
        $filtered = $this->extrasChild(['effectiveRateId' => $this->single->id, 'filter' => (string) $this->portTaxes->id]);

        $this->assertNull($filtered->extras->firstWhere('id', $wifi->id));

        $filtered->dispatch('availability-search-updated')
            ->assertHasNoErrors()
            ->assertDispatched('extras-updated');

        $this->assertEquals('20.00', $filtered->get('enabledExtras.extras')->get($wifi->id)['price']);
    }

    public function test_extras_child_ignores_the_results_event_when_pricing_a_reservation()
    {
        $reservation = Reservation::factory()->withRate($this->single->id)->create([
            'item_id' => $this->entry->id(),
            'quantity' => 2,
            'price' => '400.00',
            'payment' => '400.00',
        ]);

        $extras = Livewire::test(Extras::class, ['reservation' => $reservation])
            ->call('toggleExtra', $this->portTaxes->id);

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);

        $extras->dispatch('availability-results-updated', rateId: 999)
            ->assertNotDispatched('extras-updated')
            ->assertSet('effectiveRateId', null);

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
    }

    public function test_extras_child_ignores_the_results_event_when_pricing_a_cart()
    {
        session([
            AvailabilityMultiResults::CART_OWNER_SESSION_KEY => $this->entry->id(),
            'resrv-multi-selections' => [[
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                'rate_id' => $this->single->id,
                'quantity' => 2,
                'price' => '200.00',
                'rate_label' => 'Single',
            ]],
        ]);

        $extras = Livewire::test(Extras::class, ['entryId' => $this->entry->id(), 'useMultiSelections' => true])
            ->call('toggleExtra', $this->portTaxes->id);

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);

        $extras->dispatch('availability-results-updated', rateId: 999)
            ->assertNotDispatched('extras-updated')
            ->assertSet('effectiveRateId', null);
    }

    // --- Options child ---

    public function test_options_child_prices_under_the_resolved_rate_it_is_mounted_with()
    {
        [$option, $value] = $this->createFixedOption();

        $this->search($this->searchPayload());

        $options = $this->optionsChild(['effectiveRateId' => $this->single->id]);

        $this->assertEquals('30.00', $this->valuePrice($options, $option, $value));

        $options->call('selectOption', $option->id, $value->id)
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->first()['price']);
    }

    public function test_options_child_without_the_resolved_rate_is_corrected_by_the_results_event()
    {
        [$option, $value] = $this->createFixedOption();

        $this->search($this->searchPayload());

        $options = $this->optionsChild();

        $this->assertEquals('60.00', $this->valuePrice($options, $option, $value));

        $options->call('selectOption', $option->id, $value->id);

        $options->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $this->valuePrice($options, $option, $value));
        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->first()['price']);
    }

    /**
     * Rates enabled: a fixed 30 value selected under 'double' at quantity 2 (60.00) must follow
     * the customer to 'single' (divisor 2 → 30.00). Only the available list used to refresh;
     * the selection kept 60.00 and no updated selection reached the results component.
     */
    public function test_options_child_reprices_its_selection_when_the_rate_changes()
    {
        $double = $this->addDoubleRate();
        [$option, $value] = $this->createFixedOption();

        $this->search($this->searchPayload(rate: $double->id), ['rates' => true]);

        $options = $this->optionsChild(['effectiveRateId' => $double->id])
            ->call('selectOption', $option->id, $value->id);

        $this->assertEquals('60.00', $options->get('enabledOptions.options')->get($option->id)['price']);

        $this->search($this->searchPayload(rate: $this->single->id), ['rates' => true]);

        $options->dispatch('availability-search-updated')
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $this->valuePrice($options, $option, $value));
        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->first()['price']);
    }

    /**
     * The same staleness without any divisor involved: a per-day value (22.75/day) selected for
     * 2 nights at quantity 1 must re-price to 3 nights when the dates change.
     */
    public function test_options_child_reprices_its_selection_when_the_dates_change()
    {
        [$option, $value] = $this->createPerdayOption();

        $this->search($this->searchPayload(quantity: 1));

        $options = $this->optionsChild(['effectiveRateId' => $this->single->id])
            ->call('selectOption', $option->id, $value->id);

        $this->assertEquals('45.50', $options->get('enabledOptions.options')->get($option->id)['price']);

        $this->search($this->searchPayload(quantity: 1, nights: 3));

        $options->dispatch('availability-search-updated')
            ->assertDispatched('options-updated');

        $this->assertEquals('68.25', $options->get('enabledOptions.options')->get($option->id)['price']);
    }

    public function test_options_child_does_not_dispatch_when_nothing_is_selected()
    {
        $this->createFixedOption();

        $this->search($this->searchPayload());

        $options = $this->optionsChild(['effectiveRateId' => $this->single->id]);

        $options->dispatch('availability-search-updated')
            ->assertNotDispatched('options-updated');
    }

    /**
     * The Options twin of the cleared-search guard: a per-day value selected for 2 nights must
     * not be re-priced (to 0.00) against the dateless search the calendar's clear button sends.
     */
    public function test_options_child_keeps_its_prices_when_the_search_is_cleared()
    {
        [$option, $value] = $this->createPerdayOption();

        $this->search($this->searchPayload(quantity: 1));

        $options = $this->optionsChild(['effectiveRateId' => $this->single->id])
            ->call('selectOption', $option->id, $value->id);

        $this->assertEquals('45.50', $options->get('enabledOptions.options')->get($option->id)['price']);

        $this->clearSearch();

        $options->dispatch('availability-search-updated')
            ->assertNotDispatched('options-updated');

        $this->assertEquals('45.50', $options->get('enabledOptions.options')->get($option->id)['price']);
        $this->assertEquals('45.50', collect(session('resrv-options')->options)->get($option->id)['price']);
    }

    public function test_options_child_keeps_the_stored_price_of_a_selection_outside_its_list()
    {
        [$fixedOption, $fixedValue] = $this->createFixedOption();
        [$perdayOption] = $this->createPerdayOption();

        $this->search($this->searchPayload());

        $this->optionsChild(['effectiveRateId' => $this->single->id])
            ->call('selectOption', $fixedOption->id, $fixedValue->id);

        // A second instance that only lists the per-day option inherits the fixed selection.
        $filtered = $this->optionsChild(['effectiveRateId' => $this->single->id, 'filter' => (string) $perdayOption->id]);

        $this->assertNull($filtered->options->firstWhere('id', $fixedOption->id));

        $filtered->dispatch('availability-search-updated')
            ->assertHasNoErrors()
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $filtered->get('enabledOptions.options')->get($fixedOption->id)['price']);
    }

    // --- Checkout hand-off ---

    /**
     * The reviewer's scenario end to end: add-ons picked next to the results (rates disabled,
     * quantity 2) are carried into a checkout without an extras step. The reservation books the
     * 'single' rate, so the server re-prices port taxes at 27.50 and the value at 30.00; the
     * session must already hold those amounts or step 1 fails as price drift.
     */
    public function test_checkout_without_extras_step_accepts_add_ons_picked_next_to_the_results()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();
        [$option, $value] = $this->createFixedOption();

        $results = $this->search($this->searchPayload(), ['showExtras' => true, 'showOptions' => true]);

        $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->call('toggleExtra', $this->portTaxes->id);
        $this->optionsChild(['effectiveRateId' => $this->single->id])
            ->call('selectOption', $option->id, $value->id);

        // In the browser the children's events reach the results component; replay them so its
        // session-backed selection (the one the checkout reads) carries the children's prices.
        // Both payloads are read up front: every results request rewrites both session keys.
        $extrasPayload = session('resrv-extras')->extras->toArray();
        $optionsPayload = session('resrv-options')->options->toArray();

        $results->dispatch('extras-updated', $extrasPayload)
            ->dispatch('options-updated', $optionsPayload)
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->first()['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->first()['price']);

        $reservation = Reservation::where('item_id', $this->entry->id())->firstOrFail();

        $this->assertEquals($this->single->id, $reservation->rate_id);
        $this->assertEquals(2, $reservation->quantity);
        $this->assertEquals('400.00', $reservation->price->format());

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertHasNoErrors(['reservation', 'extras', 'options'])
            ->assertSet('step', 2);

        $fresh = $reservation->fresh();

        // 400 cabin + 27.50 port taxes + 30 option
        $this->assertEquals('457.50', $fresh->total->format());
        $this->assertEquals('57.50', $fresh->extraCharges()->format());

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $reservation->id,
            'extra_id' => $this->portTaxes->id,
            'quantity' => 1,
            'price' => '27.50',
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => $reservation->id,
            'option_id' => $option->id,
            'value' => $value->id,
        ]);
    }

    // --- Restored selections ---

    /**
     * A remount in the middle of a rate switch: add-ons picked under the 'double' rate (no
     * divisor: 55.00 / 60.00), then "Any rate" (the all-rates listing renders no children), then
     * the 'single' rate. The children are mounted afresh and restore the selection from the
     * session with the prices of the previous rate; they must re-derive them before broadcasting,
     * or the list says 27.50 while the selection (and the checkout) keeps 55.00.
     */
    public function test_extras_child_reprices_a_restored_selection_at_mount()
    {
        $double = $this->addDoubleRate();

        $results = $this->search($this->searchPayload(rate: $double->id), ['rates' => true]);
        $this->extrasChild(['effectiveRateId' => $double->id])->call('toggleExtra', $this->portTaxes->id);

        $extrasPayload = session('resrv-extras')->extras->toArray();

        $this->assertEquals('55.00', $extrasPayload[$this->portTaxes->id]['price']);

        // The results component holds the selection (replayed here as the browser would) and
        // keeps persisting it to the session across the two rate switches.
        $results->dispatch('extras-updated', $extrasPayload)
            ->dispatch('availability-search-updated', $this->searchPayload(rate: 'any'))
            ->dispatch('availability-search-updated', $this->searchPayload(rate: $this->single->id))
            ->assertHasNoErrors();

        $this->assertEquals('55.00', collect(session('resrv-extras')->extras)->get($this->portTaxes->id)['price']);

        $extras = $this->extrasChild(['effectiveRateId' => $this->single->id])
            ->assertDispatched('extras-updated');

        $this->assertEquals('27.50', $this->portTaxesPrice($extras));
        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
        // The child is the last writer here. In the page the results component rewrites the key
        // with its own copy until the broadcast lands one round trip later; checkout() re-prices
        // regardless (see the rate-card test).
        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->get($this->portTaxes->id)['price']);

        // Mounted with the rate it prices under, the results event has nothing left to correct.
        $extras->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertNotDispatched('extras-updated');

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
    }

    public function test_options_child_reprices_a_restored_selection_at_mount()
    {
        $double = $this->addDoubleRate();
        [$option, $value] = $this->createFixedOption();

        $results = $this->search($this->searchPayload(rate: $double->id), ['rates' => true]);
        $this->optionsChild(['effectiveRateId' => $double->id])->call('selectOption', $option->id, $value->id);

        $optionsPayload = session('resrv-options')->options->toArray();

        $this->assertEquals('60.00', $optionsPayload[$option->id]['price']);

        $results->dispatch('options-updated', $optionsPayload)
            ->dispatch('availability-search-updated', $this->searchPayload(rate: 'any'))
            ->dispatch('availability-search-updated', $this->searchPayload(rate: $this->single->id))
            ->assertHasNoErrors();

        $this->assertEquals('60.00', collect(session('resrv-options')->options)->get($option->id)['price']);

        $options = $this->optionsChild(['effectiveRateId' => $this->single->id])
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $this->valuePrice($options, $option, $value));
        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
        // Last writer here; in the page the parent's copy wins until the broadcast lands.
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->get($option->id)['price']);

        $options->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertNotDispatched('options-updated');

        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
    }

    /**
     * A customised results view that omits the resolved rate still converges after a remount:
     * mount re-prices under the search rate (none here, so per berth), and the results event
     * then corrects the list and the selection.
     */
    public function test_extras_child_mounted_without_the_resolved_rate_reprices_on_the_results_event()
    {
        $this->search($this->searchPayload());
        $this->extrasChild(['effectiveRateId' => $this->single->id])->call('toggleExtra', $this->portTaxes->id);

        $extras = $this->extrasChild()->assertDispatched('extras-updated');

        $this->assertEquals('55.00', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);

        $extras->dispatch('availability-results-updated', rateId: $this->single->id)
            ->assertDispatched('extras-updated');

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);
    }

    /**
     * A child mounted while the search has no dates (a page rendered before any search, the
     * calendar's clear button) restores the selection but cannot price it: it must broadcast the
     * stored amounts, not per-day add-ons at 0.00.
     */
    public function test_children_keep_a_restored_selection_price_when_mounted_without_dates()
    {
        $wifi = $this->createWifiExtra();
        [$option, $value] = $this->createPerdayOption();

        $this->search($this->searchPayload());
        $this->extrasChild(['effectiveRateId' => $this->single->id])->call('toggleExtra', $wifi->id);
        $this->optionsChild(['effectiveRateId' => $this->single->id])->call('selectOption', $option->id, $value->id);

        $this->clearSearch();

        $extras = $this->extrasChild()->assertDispatched('extras-updated');

        $this->assertEquals('20.00', $extras->get('enabledExtras.extras')->get($wifi->id)['price']);
        $this->assertEquals('20.00', collect(session('resrv-extras')->extras)->get($wifi->id)['price']);

        $options = $this->optionsChild()->assertDispatched('options-updated');

        $this->assertEquals('45.50', $options->get('enabledOptions.options')->get($option->id)['price']);
        $this->assertEquals('45.50', collect(session('resrv-options')->options)->get($option->id)['price']);
    }

    /**
     * The checkout page (extras step enabled) mounts the children with the reservation. A
     * selection restored from the results page is re-priced under the reservation, here booked
     * with the 'single' rate after being picked under 'double'.
     */
    public function test_children_reprice_a_restored_selection_under_the_reservation_at_checkout()
    {
        $double = $this->addDoubleRate();
        [$option, $value] = $this->createFixedOption();

        $this->search($this->searchPayload(rate: $double->id), ['rates' => true]);
        $this->extrasChild(['effectiveRateId' => $double->id])->call('toggleExtra', $this->portTaxes->id);
        $this->optionsChild(['effectiveRateId' => $double->id])->call('selectOption', $option->id, $value->id);

        $this->assertEquals('55.00', collect(session('resrv-extras')->extras)->get($this->portTaxes->id)['price']);
        $this->assertEquals('60.00', collect(session('resrv-options')->options)->get($option->id)['price']);

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entry->id(),
            'rate_id' => $this->single->id,
            'quantity' => 2,
            'date_start' => $this->date->toIso8601String(),
            'date_end' => $this->date->copy()->addDays(2)->toIso8601String(),
            'price' => '400.00',
            'payment' => '400.00',
        ]);

        $extras = Livewire::test(Extras::class, ['reservation' => $reservation])
            ->assertDispatched('extras-updated');

        $this->assertEquals('27.50', $extras->get('enabledExtras.extras')->get($this->portTaxes->id)['price']);

        $options = Livewire::test(Options::class, ['reservation' => $reservation])
            ->assertDispatched('options-updated');

        $this->assertEquals('30.00', $options->get('enabledOptions.options')->get($option->id)['price']);
    }

    // --- Booking from a rate card ---

    /**
     * Add-ons picked under the 'double' rate, then "Any rate" and the 'single' rate card's book
     * button. checkoutRate() books straight from the all-rates listing, where no child is
     * rendered to re-price the selection, so the results component re-prices it under the rate
     * being booked before the checkout reads it from the session.
     */
    public function test_rate_card_checkout_reprices_the_selected_add_ons_under_the_booked_rate()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();
        $double = $this->addDoubleRate();
        [$option, $value] = $this->createFixedOption();

        $results = $this->search($this->searchPayload(rate: $double->id), [
            'rates' => true,
            'showExtras' => true,
            'showOptions' => true,
        ]);

        $this->extrasChild(['effectiveRateId' => $double->id])->call('toggleExtra', $this->portTaxes->id);
        $this->optionsChild(['effectiveRateId' => $double->id])->call('selectOption', $option->id, $value->id);

        $extrasPayload = session('resrv-extras')->extras->toArray();
        $optionsPayload = session('resrv-options')->options->toArray();

        $this->assertEquals('55.00', $extrasPayload[$this->portTaxes->id]['price']);
        $this->assertEquals('60.00', $optionsPayload[$option->id]['price']);

        $results->dispatch('extras-updated', $extrasPayload)
            ->dispatch('options-updated', $optionsPayload)
            ->dispatch('availability-search-updated', $this->searchPayload(rate: 'any'))
            ->assertHasNoErrors();

        // The all-rates listing renders neither child (a previously rendered one would come back
        // as a stub carrying wire:name), so nothing but checkout() can re-price the selection.
        $this->assertEmpty(data_get($results->snapshot, 'memo.children'));
        $results->assertDontSeeHtml('wire:name="extras"')
            ->assertDontSeeHtml('wire:name="options"')
            ->call('checkoutRate', (string) $this->single->id)
            ->assertHasNoErrors('availability');

        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->get($this->portTaxes->id)['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->get($option->id)['price']);

        $reservation = Reservation::where('item_id', $this->entry->id())->firstOrFail();

        $this->assertEquals($this->single->id, $reservation->rate_id);
        $this->assertEquals('400.00', $reservation->price->format());

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertHasNoErrors(['reservation', 'extras', 'options'])
            ->assertSet('step', 2);

        $this->assertEquals('457.50', $reservation->fresh()->total->format());
    }

    /**
     * checkout() re-prices whatever selection it holds, so stored amounts that do not belong to
     * the booking (planted here as if priced per berth) are corrected before the reservation is
     * created and the session is handed to the checkout.
     */
    public function test_checkout_reprices_a_stale_selection_before_booking()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();
        [$option, $value] = $this->createFixedOption();

        $this->search($this->searchPayload())
            ->dispatch('extras-updated', [
                $this->portTaxes->id => ['id' => $this->portTaxes->id, 'price' => '55.00', 'name' => 'Port taxes', 'quantity' => 1],
            ])
            ->dispatch('options-updated', [
                $option->id => ['id' => $option->id, 'value' => $value->id, 'price' => '60.00', 'optionName' => $option->name, 'valueName' => $value->name],
            ])
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $this->assertEquals('27.50', collect(session('resrv-extras')->extras)->get($this->portTaxes->id)['price']);
        $this->assertEquals('30.00', collect(session('resrv-options')->options)->get($option->id)['price']);

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertHasNoErrors(['reservation', 'extras', 'options'])
            ->assertSet('step', 2);

        $this->assertEquals('457.50', Reservation::where('item_id', $this->entry->id())->firstOrFail()->total->format());
    }

    /**
     * A selection whose extra no longer exists (deleted in the CP after it was picked) keeps its
     * stored price through checkout(); the checkout page then rejects it with an extras error
     * instead of pricing it as free or failing on a missing model.
     */
    public function test_checkout_keeps_the_stored_price_of_a_selection_it_cannot_reprice()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();
        $gone = $this->createWifiExtra();

        $results = $this->search($this->searchPayload());

        $this->extrasChild(['effectiveRateId' => $this->single->id])->call('toggleExtra', $gone->id);

        $gone->delete();

        $results->dispatch('extras-updated', session('resrv-extras')->extras->toArray())
            ->call('checkout')
            ->assertHasNoErrors('availability');

        $this->assertEquals('20.00', collect(session('resrv-extras')->extras)->get($gone->id)['price']);

        Livewire::test(Checkout::class, ['enableExtrasStep' => false])
            ->assertHasErrors('extras')
            ->assertSet('step', 1);
    }

    /**
     * Re-pricing the add-ons validates the search (quantity bounds) like the availability check
     * does, so a tampered quantity still ends as the inline availability error, not an exception.
     */
    public function test_checkout_reports_an_out_of_range_quantity_as_an_availability_error()
    {
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');
        $this->createCheckoutEntry();

        $this->search($this->searchPayload())
            ->dispatch('extras-updated', [
                $this->portTaxes->id => ['id' => $this->portTaxes->id, 'price' => '27.50', 'name' => 'Port taxes', 'quantity' => 1],
            ])
            ->set('data.quantity', 0)
            ->call('checkout')
            ->assertHasErrors('availability');

        $this->assertDatabaseCount('resrv_reservations', 0);
    }
}
