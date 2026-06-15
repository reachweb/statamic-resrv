<?php

namespace Reach\StatamicResrv\Tests\Option;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Tests\TestCase;

class OptionCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_options_for_an_item()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $response = $this->get(cp_route('resrv.option.entryindex', $item->id()));
        $response->assertStatus(200)->assertSee($option->slug)->assertSee('22.75');
    }

    public function test_can_add_option()
    {
        $item = $this->makeStatamicItem();
        $payload = [
            'name' => 'This is an option',
            'slug' => 'this-is-an-option',
            'description' => 'This option is so cool it has a description',
            'item_id' => $item->id(),
            'required' => false,
            'published' => true,
        ];
        $response = $this->post(cp_route('resrv.option.create'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options', [
            'slug' => 'this-is-an-option',
        ]);
    }

    public function test_can_add_value_to_option()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->create();

        $payload = [
            'name' => 'This is an option value',
            'price' => '22.75',
            'price_type' => 'perday',
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.option.value.create', $option->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options_values', [
            'name' => 'This is an option value',
        ]);
    }

    public function test_cannot_add_value_with_an_unknown_price_type()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $option = Option::factory()->forEntry($item->id())->create();

        $payload = [
            'name' => 'This is an option value',
            'price' => '22.75',
            'price_type' => 'bogus',
            'published' => true,
        ];

        $this->postJson(cp_route('resrv.option.value.create', $option->id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_type');
    }

    public function test_can_update_option()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => $option->id,
            'name' => 'This is another option',
            'slug' => 'this-is-another-option',
            'description' => 'This option is less cool but still has a description',
            'item_id' => $item->id(),
            'required' => false,
            'order' => 1,
            'published' => true,
        ];
        $response = $this->patch(cp_route('resrv.option.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options', [
            'slug' => 'this-is-another-option',
        ]);
        $this->assertDatabaseMissing('resrv_options', [
            'slug' => 'reservation-option',
        ]);
    }

    public function test_can_update_option_value()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => 1,
            'name' => 'This is another option value',
            'price' => '22.75',
            'price_type' => 'perday',
            'order' => 1,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.option.value.update', $option->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options_values', [
            'name' => 'This is another option value',
        ]);
    }

    public function test_can_delete_option_value()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => 1,
        ];

        $response = $this->delete(cp_route('resrv.option.value.delete'), $payload);
        $response->assertStatus(200);
        $this->assertSoftDeleted($option->values()->withTrashed()->first());
    }

    public function test_can_delete_option()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => $option->id,
        ];

        $response = $this->delete(cp_route('resrv.option.delete'), $payload);
        $response->assertStatus(200);

        $this->assertSoftDeleted($option);
        $this->assertSoftDeleted($option->values()->withTrashed()->first());
    }

    public function test_can_reorder_options()
    {
        $option = Option::factory()->create();
        $option2 = Option::factory()->create(['id' => 2, 'order' => 2]);
        $option3 = Option::factory()->create(['id' => 3, 'order' => 3]);

        $payload = [
            'id' => 1,
            'order' => 3,
        ];

        $response = $this->patch(cp_route('resrv.option.order'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_options', [
            'id' => $option['id'],
            'order' => 3,
        ]);
        $this->assertDatabaseHas('resrv_options', [
            'id' => $option2['id'],
            'order' => 1,
        ]);
        $this->assertDatabaseHas('resrv_options', [
            'id' => $option3['id'],
            'order' => 2,
        ]);
    }

    public function test_can_reorder_option_values()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->forEntry($item->id())
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => 1,
            'order' => 3,
        ];

        $response = $this->patch(cp_route('resrv.option.value.order'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_options_values', [
            'id' => 1,
            'order' => 3,
        ]);
        $this->assertDatabaseHas('resrv_options_values', [
            'id' => 2,
            'order' => 1,
        ]);
        $this->assertDatabaseHas('resrv_options_values', [
            'id' => 3,
            'order' => 2,
        ]);
    }

    public function test_updating_a_non_existent_option_returns_404()
    {
        $this->withExceptionHandling();

        $payload = [
            'id' => 99999,
            'name' => 'This option does not exist',
            'slug' => 'this-option-does-not-exist',
            'item_id' => 'some-item',
            'required' => false,
            'order' => 1,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.option.update'), $payload);
        $response->assertNotFound();
    }

    public function test_reordering_a_non_existent_option_returns_404()
    {
        $this->withExceptionHandling();

        $payload = [
            'id' => 99999,
            'order' => 3,
        ];

        $response = $this->patch(cp_route('resrv.option.order'), $payload);
        $response->assertNotFound();
    }

    public function test_can_toggle_a_value_disabled_for_an_entry()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()->forEntry($item->id())->has(OptionValue::factory(), 'values')->create();
        $value = $option->values->first();

        $this->patchJson(cp_route('resrv.option.value.disable'), [
            'option_value_id' => $value->id,
            'statamic_id' => $item->id(),
            'disabled' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('resrv_option_value_entries', [
            'option_value_id' => $value->id,
            'statamic_id' => $item->id(),
        ]);

        $this->patchJson(cp_route('resrv.option.value.disable'), [
            'option_value_id' => $value->id,
            'statamic_id' => $item->id(),
            'disabled' => false,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('resrv_option_value_entries', [
            'option_value_id' => $value->id,
            'statamic_id' => $item->id(),
        ]);
    }

    public function test_toggling_a_value_disabled_twice_does_not_duplicate_the_pivot_row()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()->forEntry($item->id())->has(OptionValue::factory(), 'values')->create();
        $value = $option->values->first();

        foreach (range(1, 2) as $ignored) {
            $this->patchJson(cp_route('resrv.option.value.disable'), [
                'option_value_id' => $value->id,
                'statamic_id' => $item->id(),
                'disabled' => true,
            ])->assertStatus(200);
        }

        $this->assertEquals(1, DB::table('resrv_option_value_entries')
            ->where('option_value_id', $value->id)
            ->where('statamic_id', $item->id())
            ->count());
    }

    public function test_entry_index_flags_values_disabled_for_the_entry()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()->forEntry($item->id())->has(OptionValue::factory()->count(2), 'values')->create();
        $disabledValue = $option->values->first();
        $enabledValue = $option->values->last();

        $disabledValue->disabledEntries()->attach($item->id());

        $response = $this->getJson(cp_route('resrv.option.entryindex', $item->id()))->assertStatus(200);

        $values = collect($response->json())->firstWhere('id', $option->id)['values'];
        $flags = collect($values)->mapWithKeys(fn ($value) => [$value['id'] => $value['disabled_for_entry']]);

        $this->assertTrue($flags[$disabledValue->id]);
        $this->assertFalse($flags[$enabledValue->id]);
    }
}
