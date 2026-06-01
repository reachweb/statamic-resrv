<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Extras;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ExtrasPickupTimeConditionTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
    }

    private function attachExtraWithCondition(array $condition): ResrvExtra
    {
        $extra = ResrvExtra::factory()->create();

        $entry = ResrvEntry::whereItemId($this->entries->first()->id);
        $entry->extras()->attach($extra->id);

        ExtraCondition::factory()->create([
            'extra_id' => $extra->id,
            'conditions' => [$condition],
        ]);

        return $extra;
    }

    private function reservationStartingAt(string $time): Reservation
    {
        return Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'date_start' => now()->addDay()->setTimeFromTimeString($time),
            'date_end' => now()->addDays(3)->setTimeFromTimeString($time),
        ]);
    }

    public function test_required_pickup_time_extra_is_not_required_when_time_is_outside_a_same_day_window()
    {
        $extra = $this->attachExtraWithCondition([
            'operation' => 'required',
            'type' => 'pickup_time',
            'time_start' => '06:00',
            'time_end' => '10:00',
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservationStartingAt('12:00')]);

        $this->assertFalse($component->extraConditions->get('required')->contains($extra->id));
    }

    public function test_required_pickup_time_extra_is_required_when_time_is_inside_a_same_day_window()
    {
        $extra = $this->attachExtraWithCondition([
            'operation' => 'required',
            'type' => 'pickup_time',
            'time_start' => '06:00',
            'time_end' => '14:00',
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservationStartingAt('12:00')]);

        $this->assertTrue($component->extraConditions->get('required')->contains($extra->id));
    }

    public function test_required_pickup_time_extra_is_required_for_an_early_morning_overnight_window()
    {
        $extra = $this->attachExtraWithCondition([
            'operation' => 'required',
            'type' => 'pickup_time',
            'time_start' => '21:00',
            'time_end' => '08:00',
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservationStartingAt('07:00')]);

        $this->assertTrue($component->extraConditions->get('required')->contains($extra->id));
    }

    public function test_required_pickup_time_extra_is_not_required_outside_an_overnight_window()
    {
        $extra = $this->attachExtraWithCondition([
            'operation' => 'required',
            'type' => 'pickup_time',
            'time_start' => '21:00',
            'time_end' => '08:00',
        ]);

        $component = Livewire::test(Extras::class, ['reservation' => $this->reservationStartingAt('12:00')]);

        $this->assertFalse($component->extraConditions->get('required')->contains($extra->id));
    }
}
