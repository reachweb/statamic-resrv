<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityResultsTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-results')
            ->assertStatus(200);
    }

    /** @test */
    public function can_set_extra_days_parameter()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->assertSet('extraDays', 2)
            ->assertStatus(200);
    }

    /** @test */
    public function listens_to_the_availability_search_updated_event()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHas('availability.data')
            ->assertViewHas('availability.data.price', '100.00')
            ->assertViewHas('availability.request')
            ->assertViewHas('availability.request.days', 2)
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(7, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_zero()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->get('none-availabile')->id()])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_stop_sales()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->get('stop-sales')->id()])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_no_availability_if_quantity_not_enough()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    'quantity' => 4,
                    'property' => null,
                ]
            )
            ->assertViewHas('availability.message')
            ->assertViewHas('availability.message.status', false);
    }

    /** @test */
    public function returns_availability_for_extra_requested_days()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 2])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHasAll([
                'availability.-2',
                'availability.-1',
                'availability.0',
                'availability.+1',
                'availability.+2',
            ])
            ->assertViewHas('availability.-2.message.status', false)
            ->assertViewHas('availability.-1.message.status', false)
            ->assertViewHas('availability.0.data.price', '100.00')
            ->assertViewHas('availability.+1.data.price', '100.00')
            ->assertViewHas('availability.+1.request.date_start', $this->date->copy()->addDays(1)->format('Y-m-d'))
            ->assertViewHas('availability.+2.message.status', false);
    }

    /** @test */
    public function returns_availability_for_extra_requested_days_with_offset()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id(), 'extraDays' => 1, 'extraDaysOffset' => 1])
            ->dispatch('availability-search-updated',
                [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                    'quantity' => 1,
                    'property' => null,
                ]
            )
            ->assertViewHasAll([
                'availability.-1',
                'availability.0',
                'availability.+1',
            ])
            ->assertViewHas('availability.-1.message.status', false)
            ->assertViewHas('availability.0.data.price', '50.00')
            ->assertViewHas('availability.+1.data.price', '50.00')
            ->assertViewHas('availability.+1.request.date_start', $this->date->copy()->addDays(2)->format('Y-m-d'));
    }
}
