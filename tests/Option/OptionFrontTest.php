<?php

namespace Reach\StatamicResrv\Tests\Option;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Tests\TestCase;

class OptionFrontTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_index_options_with_prices_for_dates()
    {
        $this->signInAdmin();
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $this->travelTo(today()->setHour(11));

        $checkoutRequest = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(2, 'day')->toISOString(),
            'item_id' => $item->id(),
        ];

        $response = $this->post(route('resrv.option.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($option->slug)->assertSee(22.75 * 2);

        // Check for multiple items
        $checkoutRequest['quantity'] = 3;
        $response = $this->post(route('resrv.option.index'), $checkoutRequest);
        $response->assertStatus(200)->assertSee($option->slug)->assertSee(22.75 * 2 * 3);
    }
}
