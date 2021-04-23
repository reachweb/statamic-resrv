<?php

namespace Reach\StatamicResrv\Tests\Extras;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Extra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class AvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_extras()
    {       
        $extra = Extra::factory()->create(); 

        $response = $this->get(cp_route('resrv.extra.index'));
        $response->assertStatus(200)->assertSee($extra->slug);        
    }
    
    public function test_can_index_a_statamic_entry_extras()
    {       
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'id' => $extra->id
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);  

        $response = $this->get(cp_route('resrv.extra.entryindex', $item->id()));
        $response->assertStatus(200)->assertSee($extra->slug);
    }
    
    public function test_can_add_extra()
    {      
        $payload = [
            'name' => 'This is an extra',
            'slug' => 'this-is-an-extra',
            'price' => 150,
            'price_type' => 'perday',
            'published' => 1
        ];
        $response = $this->post(cp_route('resrv.extra.create'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('extras', [
            'slug' => 'this-is-an-extra'
        ]);
    }

    public function test_can_update_extra()
    {
        $payload = [
            'name' => 'This is an extra',
            'slug' => 'this-is-an-extra',
            'price' => 150,
            'price_type' => 'perday',
            'published' => 1
        ];
        $response = $this->post(cp_route('resrv.extra.create'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('extras', [
            'slug' => 'this-is-an-extra'
        ]);

        $payload2 = [
            'id' => 1,
            'name' => 'This is another extra',
            'slug' => 'something-else',
            'price' => 200,
            'price_type' => 'fixed',
            'published' => 1
        ];
        $response = $this->patch(cp_route('resrv.extra.update'), $payload2);
        $response->assertStatus(200);

        $this->assertDatabaseHas('extras', [
            'slug' => 'something-else'
        ]);
        $this->assertDatabaseMissing('extras', [
            'slug' => 'this-is-an-extra'
        ]);
    }

    public function test_can_add_extra_to_statamic_entry()
    {
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'id' => $extra->id
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);
    }
    
    public function test_can_remove_extra_from_statamic_entry()
    {
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'id' => $extra->id
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);

        $response = $this->post(cp_route('resrv.extra.remove', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseMissing('statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);
    }

}
