<?php

namespace Reach\StatamicResrv\Tests\Option;

use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Tests\TestCase;

class OptionCpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_options_for_an_item()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
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
            ->state([
                'item_id' => $item->id(),
            ])
            ->create();

        $payload = [
            'name' => 'This is an option value',
            'price' => '22.75',
            'price_type' => 'perday',
            'description' => 'This value has a description',
            'published' => true,
        ];

        $response = $this->post(cp_route('resrv.option.value.create', $option->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options_values', [
            'name' => 'This is an option value',
            'description' => 'This value has a description',
        ]);
    }

    public function test_cannot_add_value_with_an_unknown_price_type()
    {
        $this->withExceptionHandling();

        $item = $this->makeStatamicItem();
        $option = Option::factory()->state(['item_id' => $item->id()])->create();

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
            ->state([
                'item_id' => $item->id(),
            ])
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
            'description' => 'This option is less cool but still has a description',
        ]);
        $this->assertDatabaseMissing('resrv_options', [
            'slug' => 'reservation-option',
        ]);
    }

    public function test_can_update_option_value()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
            ->has(OptionValue::factory()->count(3), 'values')
            ->create();

        $payload = [
            'id' => 1,
            'name' => 'This is another option value',
            'price' => '22.75',
            'price_type' => 'perday',
            'description' => 'This value now has a description',
            'order' => 1,
            'published' => true,
        ];

        $response = $this->patch(cp_route('resrv.option.value.update', $option->id), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_options_values', [
            'name' => 'This is another option value',
            'description' => 'This value now has a description',
        ]);
    }

    public function test_can_delete_option_value()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
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
            ->state([
                'item_id' => $item->id(),
            ])
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

    public function test_reordering_options_is_scoped_to_the_entry()
    {
        $entryA = $this->makeStatamicItem();
        $entryB = $this->makeStatamicItem();

        $create = fn ($entry, $slug) => $this->post(cp_route('resrv.option.create'), [
            'name' => $slug,
            'slug' => $slug,
            'item_id' => $entry->id(),
            'required' => false,
            'published' => true,
        ])->assertStatus(200)->json('id');

        $o1 = $create($entryA, 'o1');
        $o2 = $create($entryA, 'o2');
        $o3 = $create($entryB, 'o3');
        $o4 = $create($entryB, 'o4');

        $idsFor = fn ($entry) => collect(
            $this->get(cp_route('resrv.option.entryindex', $entry->id()))
                ->assertStatus(200)
                ->json()
        )->pluck('id')->all();

        // OptionsList.vue sends the 1-based position within the entry's own list.
        $this->patch(cp_route('resrv.option.order'), ['id' => $o4, 'order' => 1])->assertStatus(200);
        $this->assertSame([$o4, $o3], $idsFor($entryB));

        $this->patch(cp_route('resrv.option.order'), ['id' => $o4, 'order' => 2])->assertStatus(200);
        $this->assertSame([$o3, $o4], $idsFor($entryB));
        $this->assertSame([$o1, $o2], $idsFor($entryA));
    }

    public function test_reordering_moves_an_option_whose_stored_order_equals_the_target_position()
    {
        $entry = $this->makeStatamicItem();

        // Order numbers were assigned across all entries, so this entry's options start at 3.
        [$o3, $o4, $o5] = collect([3, 4, 5])
            ->map(fn ($order) => Option::factory()->create(['item_id' => $entry->id(), 'order' => $order])->id)
            ->all();

        // Dragging o3 to the last of three positions sends order 3, which is also its stored number.
        $this->patch(cp_route('resrv.option.order'), ['id' => $o3, 'order' => 3])->assertStatus(200);

        $ids = collect($this->get(cp_route('resrv.option.entryindex', $entry->id()))->json())->pluck('id')->all();
        $this->assertSame([$o4, $o5, $o3], $ids);
    }

    public function test_can_reorder_option_values()
    {
        $item = $this->makeStatamicItem();
        $option = Option::factory()
            ->state([
                'item_id' => $item->id(),
            ])
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

    public function test_reordering_option_values_is_scoped_to_their_option()
    {
        $item = $this->makeStatamicItem();
        [$optionA, $optionB] = Option::factory()->count(2)->create(['item_id' => $item->id()]);

        // Numbered across both options, as values once were.
        [$a1, $a2, $b1, $b2] = collect([[$optionA, 1], [$optionA, 2], [$optionB, 3], [$optionB, 4]])
            ->map(fn ($pair) => OptionValue::factory()->create(['option_id' => $pair[0]->id, 'order' => $pair[1]])->id)
            ->all();

        // OptionValuesList.vue sends the 1-based position within the option's own list.
        $this->patch(cp_route('resrv.option.value.order'), ['id' => $b1, 'order' => 2])->assertStatus(200);

        $this->assertSame([$b2, $b1], OptionValue::where('option_id', $optionB->id)->pluck('id')->all());
        $this->assertSame([$a1, $a2], OptionValue::where('option_id', $optionA->id)->pluck('id')->all());
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
}
