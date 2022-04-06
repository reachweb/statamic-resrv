<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Tests\TestCase;

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
        $extra2 = Extra::factory(['id' => 2])->fixed()->create();
        $item = $this->makeStatamicItem();

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
            'extra_id' => $extra->id,
        ]);

        $addExtra2ToEntry = [
            'id' => $extra2->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $addExtra2ToEntry);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
            'extra_id' => $extra2->id,
        ]);

        $this->travelTo(today()->setHour(11));

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'item_id' => $item->id(),
        ];

        $response = $this->post(route('resrv.extra.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('9.30')->assertSee($extra2->slug)->assertSee('25');

        // Check for multiple items
        $checkoutRequest['quantity'] = 3;
        $response = $this->post(route('resrv.extra.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('9.30')->assertSee($extra2->slug)->assertSee('25');
    }

    public function test_can_index_extras_with_relative_price()
    {
        $this->signInAdmin();
        $extra = Extra::factory()->relative()->create();
        $item = $this->makeStatamicItem();

        Availability::factory()
            ->count(2)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        $addExtraToEntry = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $addExtraToEntry);

        $this->travelTo(today()->setHour(11));

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'item_id' => $item->id(),
        ];

        $response = $this->post(route('resrv.extra.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('75');
    }
}
