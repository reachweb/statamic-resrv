<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Reach\StatamicResrv\Livewire\AvailabilitySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Tests\TestCase;
use Livewire\Livewire;

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
