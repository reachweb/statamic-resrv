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
        $this->date = now()->setHour(12);
        $this->entries = $this->createEntries();
    }

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(AvailabilityResults::class, ['entry' => $this->entries->first()->id()])
            ->assertViewIs('statamic-resrv::livewire.availability-results')
            ->assertStatus(200);
    }
}
