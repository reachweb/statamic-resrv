<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Models\Extra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Factories\Sequence;

class ExtraFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_index_extras_with_prices_for_dates()
    {   
        $this->signInAdmin();
        $extra = Extra::factory()->create();
        $item = $this->makeStatamicItem();

        $addExtraToEntry = [
            'id' => $extra->id
        ];
        
        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id()
        ]);
        
        $this->travelTo(today()->setHour(11));


        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'item_id' => $item->id()
        ];

        $response = $this->post(route('resrv.extra.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('4.65');        
    }    
    
}
