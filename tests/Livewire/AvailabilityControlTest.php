<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityControl;
use Reach\StatamicResrv\Livewire\AvailabilityList;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityControlTest extends TestCase
{
    use CreatesEntries;

    public $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->setTime(12, 0, 0);
        $this->travelTo(today()->setHour(12));
    }

    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityControl::class)
            ->assertStatus(200);
    }

    public function test_can_load_data_from_session()
    {
        // rates => true so the manually-set rate is not cleared by reconcileRate when the
        // context-less search bar dispatches the search.
        Livewire::test(AvailabilitySearch::class, ['rates' => true])
            ->set('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ]
            )
            ->set('data.quantity', 2)
            ->set('data.rate', 1)
            ->set('data.customer', ['adults' => 2]);

        Livewire::test(AvailabilityControl::class)
            ->assertSet('data.dates.date_start', $this->date)
            ->assertSet('data.dates.date_end', $this->date->copy()->add(1, 'day'))
            ->assertSet('data.quantity', 2)
            ->assertSet('data.rate', 1)
            ->assertSet('data.customer', ['adults' => 2]);
    }

    public function test_can_save_valid_data()
    {
        Livewire::test(AvailabilityControl::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.quantity', 2)
            ->set('data.rate', 1)
            ->set('data.customer', ['adults' => 2])
            ->call('save')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 2,
                'rate' => 1,
                'customer' => ['adults' => 2],
            ]);
    }

    public function test_validates_dates_before_save()
    {
        Livewire::test(AvailabilityControl::class)
            ->set('data.dates', [
                'date_start' => $this->date->copy()->add(1, 'day'),
                'date_end' => $this->date,
            ])
            ->call('save')
            ->assertHasErrors(['data.dates.date_start', 'data.dates.date_end'])
            ->assertNotDispatched('availability-search-updated');
    }

    public function test_a_downstream_consumer_heals_a_rate_relayed_by_control()
    {
        // Control is a passive relay: it re-dispatches a stale/foreign rate unchanged. The
        // receiving consumer reconciles it against its own context before querying.
        $entry = $this->makeStatamicItemWithAvailability(collection: 'multi', rateSlug: 'rate-a');
        $rateB = $this->createRateForEntry($entry, ['slug' => 'rate-b', 'title' => 'Rate B']);
        $this->createAvailabilityForEntry($entry, 50, 2, $rateB->id, 10);

        $foreign = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $foreignRateId = Rate::forEntry($foreign->id())->first()->id;

        Livewire::test(AvailabilityControl::class)
            ->set('data.dates', [
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(1, 'day'),
            ])
            ->set('data.rate', (string) $foreignRateId)
            ->call('save')
            // The relay does not heal — it forwards the foreign rate as-is.
            ->assertSet('data.rate', (string) $foreignRateId)
            ->assertDispatched('availability-search-updated');

        $list = Livewire::test(AvailabilityList::class, ['entry' => $entry->id(), 'rates' => true])
            ->dispatch('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 1,
                'rate' => (string) $foreignRateId,
            ])
            ->assertSet('data.rate', null);

        $this->assertNotEmpty($list->viewData('availableDates'));
    }
}
