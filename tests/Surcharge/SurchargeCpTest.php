<?php

namespace Reach\StatamicResrv\Tests\Surcharge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Surcharge;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class SurchargeCpTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected $pickup;

    protected $return;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
        Option::resetEntryCollectionCache();

        $entry = $this->makeStatamicItemWithAvailability(collection: 'pages');
        $this->pickup = Option::factory()->forEntry($entry->id())->create(['name' => 'Pickup location', 'slug' => 'pickup-location']);
        $this->return = Option::factory()->forEntry($entry->id())->create(['name' => 'Return location', 'slug' => 'return-location']);
    }

    public function test_can_list_surcharges()
    {
        Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['name' => 'One-way fee']);

        $this->get(cp_route('resrv.surcharge.index'))
            ->assertStatus(200)
            ->assertSee('One-way fee');
    }

    public function test_options_endpoint_returns_published_options()
    {
        $this->get(cp_route('resrv.surcharge.options'))
            ->assertStatus(200)
            ->assertSee('Pickup location')
            ->assertSee('Return location');
    }

    public function test_can_create_a_surcharge()
    {
        $response = $this->post(cp_route('resrv.surcharge.store'), [
            'name' => 'One-way fee',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $this->return->id,
            'comparison' => 'differs',
            'price' => '50.00',
            'published' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_surcharges', [
            'name' => 'One-way fee',
            'slug' => 'one-way-fee',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $this->return->id,
            'comparison' => 'differs',
            'price' => '50.00',
        ]);
    }

    public function test_first_and_second_option_must_differ()
    {
        $this->withExceptionHandling();

        $this->postJson(cp_route('resrv.surcharge.store'), [
            'name' => 'Bad surcharge',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $this->pickup->id,
            'comparison' => 'differs',
            'price' => '50.00',
        ])->assertStatus(422)->assertJsonValidationErrors('first_option_id');
    }

    public function test_comparison_must_be_valid()
    {
        $this->withExceptionHandling();

        $this->postJson(cp_route('resrv.surcharge.store'), [
            'name' => 'Bad surcharge',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $this->return->id,
            'comparison' => 'bogus',
            'price' => '50.00',
        ])->assertStatus(422)->assertJsonValidationErrors('comparison');
    }

    // The two compared options must share a collection — a cross-collection pair can never both be
    // selected on one reservation, so the rule would be permanently inert.
    public function test_options_must_belong_to_the_same_collection()
    {
        $this->withExceptionHandling();

        $otherCollection = Option::factory()->create([
            'name' => 'Pickup in another collection',
            'slug' => 'pickup-other',
            'collection' => 'events',
            'apply_to_all' => true,
        ]);

        $this->postJson(cp_route('resrv.surcharge.store'), [
            'name' => 'Cross-collection fee',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $otherCollection->id,
            'comparison' => 'differs',
            'price' => '50.00',
        ])->assertStatus(422)->assertJsonValidationErrors('second_option_id');
    }

    public function test_can_update_a_surcharge()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['name' => 'One-way fee', 'price' => '50.00']);

        $this->patch(cp_route('resrv.surcharge.update', $surcharge->id), [
            'name' => 'Relocation fee',
            'first_option_id' => $this->pickup->id,
            'second_option_id' => $this->return->id,
            'comparison' => 'differs',
            'price' => '75.00',
            'published' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('resrv_surcharges', [
            'id' => $surcharge->id,
            'name' => 'Relocation fee',
            'price' => '75.00',
        ]);
    }

    public function test_can_delete_a_surcharge()
    {
        $surcharge = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create();

        $this->delete(cp_route('resrv.surcharge.destroy', $surcharge->id))->assertStatus(200);

        $this->assertSoftDeleted('resrv_surcharges', ['id' => $surcharge->id]);
    }

    public function test_can_reorder_surcharges()
    {
        $first = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['order' => 1, 'slug' => 'first']);
        $second = Surcharge::factory()->between($this->pickup->id, $this->return->id)->create(['order' => 2, 'slug' => 'second']);

        $this->patch(cp_route('resrv.surcharge.order', $first->id), ['order' => 2])->assertStatus(200);

        $this->assertEquals(2, $first->fresh()->order);
        $this->assertEquals(1, $second->fresh()->order);
    }
}
