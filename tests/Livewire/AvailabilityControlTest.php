<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityControl;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityControlTest extends TestCase
{
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
        Livewire::test(AvailabilitySearch::class)
            ->set('data.dates',
                [
                    'date_start' => $this->date,
                    'date_end' => $this->date->copy()->add(1, 'day'),
                ]
            )
            ->set('data.quantity', 2)
            ->set('data.rate', 'test')
            ->set('data.customer', ['adults' => 2]);

        Livewire::test(AvailabilityControl::class)
            ->assertSet('data.dates.date_start', $this->date)
            ->assertSet('data.dates.date_end', $this->date->copy()->add(1, 'day'))
            ->assertSet('data.quantity', 2)
            ->assertSet('data.rate', 'test')
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
            ->set('data.rate', 'test')
            ->set('data.customer', ['adults' => 2])
            ->call('save')
            ->assertDispatched('availability-search-updated', [
                'dates' => [
                    'date_start' => $this->date->toISOString(),
                    'date_end' => $this->date->copy()->add(1, 'day')->toISOString(),
                ],
                'quantity' => 2,
                'rate' => 'test',
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
}
