<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilitySearchTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function renders_successfully()
    {
        Livewire::test(AvailabilitySearch::class)
            ->assertViewIs('statamic-resrv::livewire.availability-search')
            ->assertStatus(200);
    }
}
