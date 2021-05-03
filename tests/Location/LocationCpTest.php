<?php

namespace Reach\StatamicResrv\Tests\Extras;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_locations()
    {       
        $location = Location::factory()->create(); 

        $response = $this->get(cp_route('resrv.location.index'));
        $response->assertStatus(200)->assertSee($location->slug);        
    }

    public function test_can_add_location()
    {
        $location = Location::factory()->make()->toArray();
       
        $response = $this->post(cp_route('resrv.location.create'), $location);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_locations', [
            'name' => $location['name']
        ]);
    }

    public function test_can_update_location()
    {
        $location = Location::factory()->make()->toArray();
       
        $response = $this->post(cp_route('resrv.location.create'), $location);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_locations', [
            'name' => $location['name']
        ]);

        $payload = [
            'id' => 1,
            'name' => $location['name'],
            'slug' => 'new-slug',
            'extra_charge' => 25,
            'order' => $location['order'],
            'published' => 1
        ];
        $response = $this->patch(cp_route('resrv.location.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_locations', [
            'slug' => 'new-slug'
        ]);
        $this->assertDatabaseMissing('resrv_locations', [
            'slug' => 'location'
        ]);
    }

    public function test_can_delete_location()
    {
        $location = Location::factory()->create(); 

        $response = $this->delete(cp_route('resrv.location.delete'), $location->toArray());
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_locations', [
            'name' => $location->name
        ]);
    }

    public function test_can_reorder_location()
    {
        $location = Location::factory()->create()->toArray();
        $location2 = Location::factory()->create(['id' => 2, 'order' => 2]);
        $location3 = Location::factory()->create(['id' => 3, 'order' => 3]);

        $payload = [
            'id' => 1,
            'order' => 3
        ];
       
        $response = $this->patch(cp_route('resrv.location.order'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_locations', [
            'id' => $location['id'],
            'order' => 3
        ]);
        $this->assertDatabaseHas('resrv_locations', [
            'id' => $location2['id'],
            'order' => 1
        ]);
        $this->assertDatabaseHas('resrv_locations', [
            'id' => $location3['id'],
            'order' => 2
        ]);
    }

}
