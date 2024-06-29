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

    public function setUp(): void
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
                                'connected_availabilities' => 'all',
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
            'available_only' => true,
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
            'available_only' => true,
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
                                'connected_availabilities' => 'same_slug',
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
            'available_only' => true,
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
                                'connected_availabilities' => 'select',
                                'manual_connected_availabilities' => [
                                    'something' => 'something-else',
                                    'something-else-completely' => 'something,something-else',
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
            'available_only' => true,
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
            'available_only' => true,
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
}
