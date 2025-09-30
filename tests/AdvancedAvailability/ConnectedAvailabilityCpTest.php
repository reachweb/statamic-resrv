<?php

namespace Reach\StatamicResrv\Tests\AdvancedAvailability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

class ConnectedAvailabilityCpTest extends TestCase
{
    use RefreshDatabase;

    public $collection;

    private $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();

        $this->collection = Collection::make('pages')->save();
    }

    public function test_connected_availability_gets_updated_on_all()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'something' => 'Something',
                                    'something-else' => 'Something else',
                                    'something-else-completely' => 'Something else completely',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'all',
                                        'block_type' => 'sync',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle('pages')->setNamespace('collections.'.$this->collection->handle())->save();

        $item = $this->makeStatamicItem();
        // To ensure it gets saved in the Entry table
        $item->save();

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else-completely',
                ]
            );

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something']],
            'available' => 6,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something-else-completely',
        ]);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something-else-completely']],
            'available' => 12,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 12,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 12,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 12,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_does_not_work_on_cp_when_disable_on_cp_set_to_true()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'something' => 'Something',
                                    'something-else' => 'Something else',
                                    'something-else-completely' => 'Something else completely',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'all',
                                        'block_type' => 'sync',
                                    ],
                                ],
                                'disable_connected_availabilities_on_cp' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle('pages')->setNamespace('collections.'.$this->collection->handle())->save();

        $item = $this->makeStatamicItem();
        // To ensure it gets saved in the Entry table
        $item->save();

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else-completely',
                ]
            );

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something']],
            'available' => 6,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_gets_updated_for_change_amount_if_enabled()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'something' => 'Something',
                                    'something-else' => 'Something else',
                                    'something-else-completely' => 'Something else completely',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'all',
                                        'block_type' => 'change_by_amount',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle('pages')->setNamespace('collections.'.$this->collection->handle())->save();

        $item = $this->makeStatamicItem();
        // To ensure it gets saved in the Entry table
        $item->save();

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                ['statamic_id' => $item->id()]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else',
                    'available' => 10,
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else-completely',
                    'available' => 20,
                ]
            );

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something']],
            'available' => 6,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 14,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 24,
            'property' => 'something-else-completely',
        ]);

        $newPayload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something-else-completely']],
            'available' => 20,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $newPayload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 10,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 20,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_gets_updated_on_same_slug()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        Blueprint::find('collections.pages.pages')->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'something' => 'Something',
                                    'something-else' => 'Something else',
                                    'something-else-completely' => 'Something else completely',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'same_slug',
                                        'block_type' => 'sync',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        $item->save();
        $item2->save();

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item2->id(),
                    'property' => 'something-else',
                ]
            );

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something-else']],
            'available' => 6,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 6,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 6,
            'property' => 'something-else',
        ]);
    }

    public function test_connected_availability_gets_updated_on_select()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        Blueprint::find('collections.pages.pages')->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'something' => 'Something',
                                    'something-else' => 'Something else',
                                    'something-else-completely' => 'Something else completely',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'select',
                                        'block_type' => 'sync',
                                        'manually_connected_availabilities' => [
                                            'something' => 'something-else',
                                            'something-else-completely' => 'something,something-else',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        $item->save();
        $item2->save();

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item->id(),
                    'property' => 'something-else-completely',
                ]
            );

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create(
                [
                    'statamic_id' => $item2->id(),
                    'property' => 'something-else',
                ]
            );

        $payload = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something']],
            'available' => 6,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 6,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else',
        ]);

        $payload2 = [
            'statamic_id' => $item->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'something-else-completely']],
            'available' => 11,
            'price' => null,
        ];
        $response = $this->post(cp_route('resrv.availability.update'), $payload2);
        $response->assertStatus(200);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 11,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 11,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else',
        ]);
    }

    public function test_multiple_connected_availability_rules_can_be_applied()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'resrv_availability',
                            'field' => [
                                'type' => 'resrv_availability',
                                'display' => 'Resrv Availability',
                                'advanced_availability' => [
                                    'standard' => 'Standard Rate',
                                    'non_refundable' => 'Non-Refundable Rate',
                                    'free_car' => 'Free Car Rental',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'all',
                                        'block_type' => 'sync',
                                    ],
                                    [
                                        'connected_availability_type' => 'same_slug',
                                        'block_type' => 'sync',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle('pages')->setNamespace('collections.'.$this->collection->handle())->save();

        // Create three different entries
        $item1 = $this->makeStatamicItem();
        $item1->save();

        $item2 = $this->makeStatamicItem();
        $item2->save();

        $item3 = $this->makeStatamicItem();
        $item3->save();

        // Create initial availabilities for the three rate types in first entry
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'standard',
                'available' => 10,
            ]);

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'non_refundable',
                'available' => 10,
            ]);

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'free_car',
                'available' => 5,
            ]);

        // Create availabilities for second entry
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item2->id(),
                'property' => 'standard',
                'available' => 8,
            ]);

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item2->id(),
                'property' => 'free_car',
                'available' => 5,
            ]);

        // Create availabilities for third entry
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item3->id(),
                'property' => 'standard',
                'available' => 15,
            ]);

        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item3->id(),
                'property' => 'free_car',
                'available' => 5,
            ]);

        // Update free_car availability in first entry
        $payload = [
            'statamic_id' => $item1->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'free_car']],
            'available' => 3,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        // First rule: "all" - within the same entry, all availability types are synced
        // The standard and non_refundable rates in the first entry should be updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'standard',
            'available' => 3,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'non_refundable',
            'available' => 3,
        ]);

        // Second rule: "same_slug" - the free_car availability should be updated across all entries
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);

        // But the standard rate in other entries should remain unchanged
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'standard',
            'available' => 8,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'standard',
            'available' => 15,
        ]);

        // Now update the standard rate in entry 2
        $payload = [
            'statamic_id' => $item2->id(),
            'date_start' => today()->isoFormat('YYYY-MM-DD'),
            'date_end' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD'),
            'advanced' => [['code' => 'standard']],
            'available' => 6,
            'price' => null,
        ];

        $response = $this->post(cp_route('resrv.availability.update'), $payload);
        $response->assertStatus(200);

        // First rule: "all" - within entry 2, free_car should be updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'standard',
            'available' => 6,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 6,
        ]);

        // But the free_car in other entries should remain at 3 (from previous update)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);
    }
}
