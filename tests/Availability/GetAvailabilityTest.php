<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check if we can get availability for an item
     *
     * @return void
     */
    public function testAvailabilityTest()
    {
        $response = $this->get(route('resrv.availability'));
        $response->assertStatus(200);
    }
}
