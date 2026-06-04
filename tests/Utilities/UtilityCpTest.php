<?php

namespace Reach\StatamicResrv\Tests\Utilities;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Tests\TestCase;

class UtilityCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    // The entries endpoint feeds the CP entry pickers, which only need id (ExtraMassAssignPanel),
    // item_id (DynamicPricingPanel) and title. The mirror's bulky `options` JSON and the other
    // columns must not be serialised to the client.
    public function test_entries_endpoint_returns_only_the_columns_the_pickers_need()
    {
        Entry::factory()->create([
            'item_id' => 'entry-1',
            'title' => 'Bookable entry',
        ]);

        $response = $this->getJson(cp_route('resrv.utilities.entries'))->assertOk();

        $response->assertJsonCount(1)
            ->assertJsonFragment(['item_id' => 'entry-1', 'title' => 'Bookable entry']);

        $this->assertEqualsCanonicalizing(['id', 'item_id', 'title'], array_keys($response->json('0')));
    }
}
