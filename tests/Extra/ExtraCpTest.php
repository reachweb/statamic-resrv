<?php

namespace Reach\StatamicResrv\Tests\Extra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Tests\TestCase;

class ExtraCpTest extends TestCase
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
            'id' => $extra->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
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
            'allow_multiple' => 1,
            'maximum' => 3,
            'published' => 1,
        ];
        $response = $this->post(cp_route('resrv.extra.create'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_extras', [
            'slug' => 'this-is-an-extra',
        ]);
    }

    public function test_can_update_extra()
    {
        $payload = [
            'name' => 'This is an extra',
            'slug' => 'this-is-an-extra',
            'price' => 150,
            'price_type' => 'perday',
            'allow_multiple' => 1,
            'maximum' => 3,
            'published' => 1,
        ];
        $response = $this->post(cp_route('resrv.extra.create'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_extras', [
            'slug' => 'this-is-an-extra',
        ]);

        $payload2 = [
            'id' => 1,
            'name' => 'This is another extra',
            'slug' => 'something-else',
            'price' => 200,
            'price_type' => 'fixed',
            'allow_multiple' => 0,
            'order' => 1,
            'published' => 1,
        ];
        $response = $this->patch(cp_route('resrv.extra.update'), $payload2);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_extras', [
            'slug' => 'something-else',
        ]);
        $this->assertDatabaseMissing('resrv_extras', [
            'slug' => 'this-is-an-extra',
        ]);
    }

    public function test_can_delete_extra()
    {
        $extra = Extra::factory()->create();
        $item = $this->makeStatamicItem();

        $payload = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $payload);

        $response = $this->delete(cp_route('resrv.extra.delete'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);
        $this->assertSoftDeleted($extra);
    }

    public function test_can_add_extra_to_statamic_entry()
    {
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'id' => $extra->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);
    }

    public function test_can_add_and_remove_extra_to_multiple_extras()
    {
        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'entries' => [
                $item->id(),
                $item2->id(),
                $item3->id(),
            ],
        ];

        $response = $this->post(cp_route('resrv.extra.massadd', $extra->id), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
            'statamicentry_id' => $item2->id(),
            'statamicentry_id' => $item3->id(),
        ]);

        $payload = [
            'entries' => [
                $item2->id(),
                $item3->id(),
            ],
        ];

        $response = $this->post(cp_route('resrv.extra.massadd', $extra->id), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item2->id(),
            'statamicentry_id' => $item3->id(),
        ]);
        $this->assertDatabaseMissing('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);
    }

    public function test_can_get_all_entries_for_extra()
    {
        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'entries' => [
                $item->id(),
                $item2->id(),
            ],
        ];

        $this->post(cp_route('resrv.extra.massadd', $extra->id), $payload);

        $response = $this->get(cp_route('resrv.extra.entries', $extra->id));
        $response->assertStatus(200)->assertSee($item->id())->assertSee($item2->id())->assertSee($item->title);
    }

    public function test_can_remove_extra_from_statamic_entry()
    {
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();

        $payload = [
            'id' => $extra->id,
        ];

        $response = $this->post(cp_route('resrv.extra.add', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);

        $response = $this->post(cp_route('resrv.extra.remove', $item->id()), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_statamicentry_extra', [
            'statamicentry_id' => $item->id(),
        ]);
    }

    public function test_can_reorder_extras()
    {
        $extra = Extra::factory()->create();
        $extra2 = Extra::factory()->create(['id' => 2, 'order' => 2]);
        $extra3 = Extra::factory()->create(['id' => 3, 'order' => 3]);

        $payload = [
            'id' => 1,
            'order' => 3,
        ];

        $response = $this->patch(cp_route('resrv.extra.order'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('resrv_extras', [
            'id' => $extra['id'],
            'order' => 3,
        ]);
        $this->assertDatabaseHas('resrv_extras', [
            'id' => $extra2['id'],
            'order' => 1,
        ]);
        $this->assertDatabaseHas('resrv_extras', [
            'id' => $extra3['id'],
            'order' => 2,
        ]);
    }

    public function test_can_index_extras_and_conditions()
    {
        $extra = Extra::factory()->create();
        $condition = ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra->id,
        ]);

        $response = $this->get(cp_route('resrv.extra.index'));
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('extra_selected');
    }

    public function test_can_index_a_statamic_entry_extras_and_conditions()
    {
        $item = $this->makeStatamicItem();
        $extra = Extra::factory()->create();
        $condition = ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra->id,
        ]);

        $payload = [
            'id' => $extra->id,
        ];

        $this->post(cp_route('resrv.extra.add', $item->id()), $payload);

        $response = $this->get(cp_route('resrv.extra.entryindex', $item->id()));
        $response->assertStatus(200)->assertSee($extra->slug)->assertSee('extra_selected');
    }

    public function test_can_create_a_condition()
    {
        $extra = Extra::factory()->create();

        $payload = [
            'conditions' => [
                [
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ],
            ],
        ];

        $response = $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);
        $this->assertDatabaseHas('resrv_extra_conditions', [
            'extra_id' => $extra->id,
            'conditions' => json_encode([
                [
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ],
            ]),
        ]);
        $response->assertStatus(200);
    }

    public function test_can_create_multiple_conditions()
    {
        $extra = Extra::factory()->create();

        $payload = [
            'conditions' => [
                [
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ],
                [
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ],
        ];

        $response = $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);
        $this->assertDatabaseHas('resrv_extra_conditions', [
            'extra_id' => $extra->id,
            'conditions' => json_encode([
                [
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ],
                [
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ]),
        ]);
        $response->assertStatus(200);
    }

    public function test_can_edit_a_condition()
    {
        $extra = Extra::factory()->create();
        ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra->id,
        ]);

        $payload = [
            'conditions' => [
                [
                    'operation' => 'hide',
                    'type' => 'extra_selected',
                    'comparison' => '!=',
                    'value' => '4',
                ],
            ],
        ];

        $response = $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);
        $this->assertDatabaseHas('resrv_extra_conditions', [
            'extra_id' => $extra->id,
            'conditions' => json_encode([
                [
                    'operation' => 'hide',
                    'type' => 'extra_selected',
                    'comparison' => '!=',
                    'value' => '4',
                ],
            ]),
        ]);
        $this->assertDatabaseMissing('resrv_extra_conditions', [
            'extra_id' => $extra->id,
            'conditions' => json_encode([
                [
                    'operation' => 'show',
                    'condition' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ]),
        ]);
        $response->assertStatus(200);
    }

    public function test_can_edit_multiple_conditions()
    {
        $extra = Extra::factory()->create();

        $payload = [
            'conditions' => [
                [
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ],
                [
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ],
        ];

        $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);

        $payload = [
            'conditions' => [
                [
                    'operation' => 'require',
                    'type' => 'reservation_dates',
                    'date_start' => today()->toIso8601String(),
                    'date_end' => today()->add(10, 'day')->toIso8601String(),
                ],
                [
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ],
        ];
        $response = $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);
        $this->assertDatabaseHas('resrv_extra_conditions', [
            'extra_id' => $extra->id,
            'conditions' => json_encode([
                [
                    'operation' => 'require',
                    'type' => 'reservation_dates',
                    'date_start' => today()->toIso8601String(),
                    'date_end' => today()->add(10, 'day')->toIso8601String(),
                ],
                [
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'comparison' => '==',
                    'value' => '2',
                ],
            ]),
        ]);
        $response->assertStatus(200);
    }

    public function test_can_remove_conditions_if_empty()
    {
        $extra = Extra::factory()->create();
        $condition = ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra->id,
        ]);

        $payload = [
            'conditions' => [],
        ];

        $response = $this->post(cp_route('resrv.extra.conditions', $extra->id), $payload);
        $this->assertDatabaseMissing('resrv_extra_conditions', [
            'extra_id' => $extra->id,
        ]);
        $response->assertStatus(200);
    }

    public function test_deleting_extra_removes_conditions()
    {
        $extra = Extra::factory()->create();
        $condition = ExtraCondition::factory()->showExtraSelected()->create([
            'extra_id' => $extra->id,
        ]);

        $payload = [
            'id' => $extra->id,
        ];

        $response = $this->delete(cp_route('resrv.extra.delete'), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseMissing('resrv_extra_conditions', [
            'extra_id' => $extra->id,
        ]);
    }
}
