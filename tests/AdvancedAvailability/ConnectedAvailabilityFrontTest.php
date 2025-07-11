<?php

namespace Reach\StatamicResrv\Tests\AdvancedAvailability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Illuminate\Support\Facades\Cache;

class ConnectedAvailabilityFrontTest extends TestCase
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

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['item_id' => $item->id()]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_gets_updated_even_if_disable_on_cp_is_true()
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

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['item_id' => $item->id()]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_gets_changed_only_for_amount()
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
                [
                    'statamic_id' => $item->id(),
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
                    'available' => 6,
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
                    'available' => 8,
                ]
            );

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                    'quantity' => 2,
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 0,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 4,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something-else-completely',
        ]);

        Event::dispatch(new ReservationExpired($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 8,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availabilities_gets_blocked_and_unblocked()
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
                                        'block_type' => 'block_availability',
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
                [
                    'statamic_id' => $item->id(),
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
                    'available' => 6,
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
                    'available' => 8,
                ]
            );

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 1,
            'available_blocked' => null,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 0,
            'available_blocked' => 6,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 0,
            'available_blocked' => 8,
            'property' => 'something-else-completely',
        ]);

        Event::dispatch(new ReservationExpired($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 2,
            'available_blocked' => null,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 6,
            'available_blocked' => null,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'available' => 8,
            'available_blocked' => null,
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

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['property' => 'something-else',
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);
    }

    public function test_connected_availability_gets_updated_on_specific_slug()
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
                                        'connected_availability_type' => 'specific_slugs',
                                        'block_type' => 'sync',
                                        'slugs_to_sync' => 'something-else',
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
                    'property' => 'something-else-completely',
                ]
            );

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['property' => 'something-else',
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 2,
            'property' => 'something-else-completely',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else-completely',
        ]);

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['property' => 'something-else-completely',
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else-completely',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else-completely',
        ]);
    }

    public function test_connected_availability_gets_blocked_and_unblocked_on_same_slug()
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
                                        'block_type' => 'block_availability',
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

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                ['property' => 'something-else',
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'available_blocked' => null,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 0,
            'available_blocked' => 2,
            'property' => 'something-else',
        ]);

        Event::dispatch(new ReservationExpired($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 2,
            'available_blocked' => null,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'available_blocked' => null,
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

        $reservation = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else',
        ]);

        $reservation2 = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'something-else-completely',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'something-else',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'available' => 2,
            'property' => 'something-else',
        ]);
    }

    public function test_connected_availability_gets_updated_on_select_multiple()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $item = $this->makeStatamicItem();

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
                                    'morning' => 'Morning',
                                    'afternoon' => 'Afternoon',
                                    'fullday' => 'Full day',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'select',
                                        'block_type' => 'sync',
                                        'manually_connected_availabilities' => [
                                            'morning' => 'fullday',
                                            'afternoon' => 'fullday',
                                            'fullday' => 'morning,afternoon',
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
                    'property' => 'morning',
                    'available' => 1,
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
                    'property' => 'afternoon',
                    'available' => 1,
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
                    'property' => 'fullday',
                    'available' => 1,
                ]
            );

        $reservation = Reservation::factory()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'morning',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'property' => 'fullday',
        ]);

        $reservation2 = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'afternoon',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'property' => 'fullday',
        ]);

        Event::dispatch(new ReservationExpired($reservation));
        Event::dispatch(new ReservationExpired($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 1,
            'property' => 'fullday',
        ]);
    }

    public function test_connected_availability_gets_blocked_unblocked_on_select_multiple()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $item = $this->makeStatamicItem();

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
                                    'morning' => 'Morning',
                                    'afternoon' => 'Afternoon',
                                    'fullday' => 'Full day',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'select',
                                        'block_type' => 'block_availability',
                                        'manually_connected_availabilities' => [
                                            'morning' => 'fullday',
                                            'afternoon' => 'fullday',
                                            'fullday' => 'morning,afternoon',
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
                    'property' => 'morning',
                    'available' => 4,
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
                    'property' => 'afternoon',
                    'available' => 4,
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
                    'property' => 'fullday',
                    'available' => 4,
                ]
            );

        $reservation = Reservation::factory()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'morning',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 3,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);

        $reservation2 = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'afternoon',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 3,
            'available_blocked' => null,
            'property' => 'afternoon',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);

        Event::dispatch(new ReservationExpired($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'fullday',
        ]);

        Event::dispatch(new ReservationExpired($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'fullday',
        ]);
    }

    public function test_connected_availability_gets_never_unblocks_if_set()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        $item = $this->makeStatamicItem();

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
                                    'morning' => 'Morning',
                                    'afternoon' => 'Afternoon',
                                    'fullday' => 'Full day',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'select',
                                        'block_type' => 'block_availability',
                                        'manually_connected_availabilities' => [
                                            'morning' => 'fullday',
                                            'afternoon' => 'fullday',
                                            'fullday' => 'morning,afternoon',
                                        ],
                                        'never_unblock' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        $item->save();

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
                    'property' => 'morning',
                    'available' => 4,
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
                    'property' => 'afternoon',
                    'available' => 4,
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
                    'property' => 'fullday',
                    'available' => 4,
                ]
            );

        $reservation = Reservation::factory()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'morning',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 3,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);

        $reservation2 = Reservation::factory()
            ->advanced()
            ->create(
                [
                    'item_id' => $item->id(),
                    'property' => 'afternoon',
                ]
            );

        Event::dispatch(new ReservationCreated($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 3,
            'available_blocked' => null,
            'property' => 'afternoon',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);

        Event::dispatch(new ReservationExpired($reservation));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);

        Event::dispatch(new ReservationExpired($reservation2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 4,
            'available_blocked' => null,
            'property' => 'morning',
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item->id(),
            'available' => 0,
            'available_blocked' => 4,
            'property' => 'fullday',
        ]);
    }

    public function test_multiple_connected_availability_rules_can_be_applied()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

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
                                    'standard' => 'Standard Rate',
                                    'non_refundable' => 'Non-Refundable Rate',
                                    'free_car' => 'Free Car Rental',
                                ],
                                'connected_availabilities' => [

                                    [
                                        'connected_availability_type' => 'specific_slugs',
                                        'slugs_to_sync' => 'free_car',
                                        'block_type' => 'sync',
                                    ],
                                    [
                                        'connected_availability_type' => 'select',
                                        'block_type' => 'change_by_amount',
                                        'manually_connected_availabilities' => [
                                            'standard' => 'non_refundable',
                                            'non_refundable' => 'standard',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        // Create three different entries
        $item1 = $this->makeStatamicItem();
        $item1->save();

        $item2 = $this->makeStatamicItem();
        $item2->save();

        $item3 = $this->makeStatamicItem();
        $item3->save();

        // Create initial availabilities for the three rate types in first entry
        // First item: 10 standard, 10 non_refundable, 5 free_car
        // Second item: 8 standard, 8 non_refundable, 5 free_car
        // Third item: 15 standard, 15 non_refundable, 5 free_car
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
                'property' => 'non_refundable',
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
                'property' => 'non_refundable',
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

        // Create a reservation for free_car in entry 1
        $reservation = Reservation::factory()
            ->advanced()
            ->create([
                'item_id' => $item1->id(),
                'property' => 'free_car',
                'quantity' => 2,
            ]);

        Event::dispatch(new ReservationCreated($reservation));

        // First rule: "specific_slug"
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 3, // 5 - 2
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 3, // 5 - 2
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'free_car',
            'available' => 3, // 5 - 2
        ]);

        // Second rule: the rest should not be updated only on the same entry
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'standard',
            'available' => 10,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'non_refundable',
            'available' => 10,
        ]);

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

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'non_refundable',
            'available' => 8,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'non_refundable',
            'available' => 15,
        ]);

        // Now create a reservation for standard rate in entry 2
        $reservation2 = Reservation::factory()
            ->advanced()
            ->create([
                'item_id' => $item2->id(),
                'property' => 'standard',
                'quantity' => 3,
            ]);

        Event::dispatch(new ReservationCreated($reservation2));

        // First rule: "specific_slug" - the car availability should not be affected
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 3,
        ]);

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

        // Second rule: the non refundable in item2 should change and nothing else
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'standard',
            'available' => 10,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'non_refundable',
            'available' => 10,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'standard',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'non_refundable',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'standard',
            'available' => 15,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'non_refundable',
            'available' => 15,
        ]);

        // First reservation expired
        Event::dispatch(new ReservationExpired($reservation));

        // free_car availabilities should be restored
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);

        // non refundable availabilities should be restored
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'standard',
            'available' => 10,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'non_refundable',
            'available' => 10,
        ]);

        // The rest should not be updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'standard',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'non_refundable',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'standard',
            'available' => 15,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'non_refundable',
            'available' => 15,
        ]);

        Event::dispatch(new ReservationExpired($reservation2));

        // free_car should not be affected
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'free_car',
            'available' => 5,
        ]);
    }

    public function test_entries_connected_availability_with_sync_same_property_only_enabled()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        // Create two entry items
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        // Save both items
        $item1->save();
        $item2->save();

        // Set up blueprint with connected entries rule and entries_sync_same_property_only enabled (default)
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
                                    'property1' => 'Property 1',
                                    'property2' => 'Property 2',
                                    'property3' => 'Property 3',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'entries',
                                        'block_type' => 'sync',
                                        'entries_sync_same_property_only' => true,
                                        'connected_entries' => [
                                            [
                                                'entries' => [$item1->id(), $item2->id()],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        // Create availabilities for item1 with different properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'property1',
                'available' => 2,
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
                'property' => 'property2',
                'available' => 2,
            ]);

        // Create availabilities for item2 with the same properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item2->id(),
                'property' => 'property1',
                'available' => 2,
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
                'property' => 'property2',
                'available' => 2,
            ]);

        // Create a reservation for item1, property1
        $reservation = Reservation::factory()
            ->advanced()
            ->create([
                'item_id' => $item1->id(),
                'property' => 'property1',
            ]);

        // Dispatch reservation created event
        Event::dispatch(new ReservationCreated($reservation));

        // Check that item1, property1 availability is updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        // Check that item2, property1 availability is also updated (same property sync)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        // Check that property2 availabilities are NOT updated for both items
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property2',
            'available' => 2,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property2',
            'available' => 2,
        ]);
    }

    public function test_entries_connected_availability_with_sync_same_property_only_disabled()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        // Create two entry items
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        // Save both items
        $item1->save();
        $item2->save();

        // Set up blueprint with connected entries rule and entries_sync_same_property_only disabled
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
                                    'property1' => 'Property 1',
                                    'property2' => 'Property 2',
                                    'property3' => 'Property 3',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'entries',
                                        'block_type' => 'sync',
                                        'entries_sync_same_property_only' => false,
                                        'connected_entries' => [
                                            [
                                                'entries' => [$item1->id(), $item2->id()],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        // Create availabilities for item1 with different properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'property1',
                'available' => 2,
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
                'property' => 'property2',
                'available' => 3,
            ]);

        // Create availabilities for item2 with the same properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item2->id(),
                'property' => 'property1',
                'available' => 5,
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
                'property' => 'property2',
                'available' => 4,
            ]);

        // Create a reservation for item1, property1
        $reservation = Reservation::factory()
            ->advanced()
            ->create([
                'item_id' => $item1->id(),
                'property' => 'property1',
            ]);

        // Dispatch reservation created event
        Event::dispatch(new ReservationCreated($reservation));

        // Check that item1, property1 availability is updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        // Check that ALL properties of item2 are updated, not just the same property
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property2',
            'available' => 1,
        ]);

        // Check that property2 of item1 is NOT updated (only affected the source item's property)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property2',
            'available' => 3,
        ]);
    }

    public function test_entries_connected_availability_with_multiple_rules()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        // Create three entry items
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();

        // Save all items
        $item1->save();
        $item2->save();
        $item3->save();

        // Set up blueprint with both connected entries rule and all properties rule
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
                                    'property1' => 'Property 1',
                                    'property2' => 'Property 2',
                                    'property3' => 'Property 3',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'entries',
                                        'block_type' => 'sync',
                                        'entries_sync_same_property_only' => true,
                                        'connected_entries' => [
                                            [
                                                'entries' => [$item1->id(), $item2->id()],
                                            ],
                                        ],
                                    ],
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
        ])->save();

        // Create availabilities for item1 with different properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item1->id(),
                'property' => 'property1',
                'available' => 2,
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
                'property' => 'property2',
                'available' => 3,
            ]);

        // Create availabilities for item2 with the same properties
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item2->id(),
                'property' => 'property1',
                'available' => 5,
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
                'property' => 'property2',
                'available' => 4,
            ]);

        // Create availabilities for item3 (not in connected entries)
        Availability::factory()
            ->advanced()
            ->count(2)
            ->sequence(
                ['date' => today()->isoFormat('YYYY-MM-DD')],
                ['date' => today()->add(1, 'day')->isoFormat('YYYY-MM-DD')]
            )
            ->create([
                'statamic_id' => $item3->id(),
                'property' => 'property1',
                'available' => 6,
            ]);

        // Create a reservation for item1, property1
        $reservation = Reservation::factory()
            ->advanced()
            ->create([
                'item_id' => $item1->id(),
                'property' => 'property1',
            ]);

        // Dispatch reservation created event
        Event::dispatch(new ReservationCreated($reservation));

        // Check that item1, property1 availability is updated
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        // Check that item1, property2 availability is also updated (due to 'all' rule)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property2',
            'available' => 1,
        ]);

        // Check that item2, property1 availability is updated (due to entries rule with same property only)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        // Check that item2, property2 availability is NOT updated (same property only is enabled)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property2',
            'available' => 4,
        ]);

        // Check that item3 availability is not updated (not in connected entries)
        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'property1',
            'available' => 6,
        ]);
    }

    public function test_entries_connected_availability_with_two_groups_of_entries()
    {
        Config::set('resrv-config.enable_connected_availabilities', true);

        // Create four entry items
        $item1 = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();
        $item3 = $this->makeStatamicItem();
        $item4 = $this->makeStatamicItem();

        $item1->save();
        $item2->save();
        $item3->save();
        $item4->save();

        // Set up blueprint with two connected entries rules: [1,2] and [1,3,4]
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
                                    'property1' => 'Property 1',
                                ],
                                'connected_availabilities' => [
                                    [
                                        'connected_availability_type' => 'entries',
                                        'block_type' => 'sync',
                                        'entries_sync_same_property_only' => true,
                                        'connected_entries' => [
                                            ['entries' => [$item1->id(), $item2->id()]],
                                            ['entries' => [$item1->id(), $item3->id(), $item4->id()]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        // Create initial availability (2) for all items
        foreach ([$item1, $item2, $item3, $item4] as $item) {
            Availability::factory()
                ->advanced()
                ->create([
                    'statamic_id' => $item->id(),
                    'property' => 'property1',
                    'available' => 2,
                ]);
        }

        // When item1 changes, all four should decrement to 1
        $res1 = Reservation::factory()->advanced()->create([
            'item_id' => $item1->id(),
            'property' => 'property1',
        ]);

        Event::dispatch(new ReservationCreated($res1));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item4->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $res2 = Reservation::factory()->advanced()->create([
            'item_id' => $item3->id(),
            'property' => 'property1',
        ]);

        Event::dispatch(new ReservationCreated($res2));

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item1->id(),
            'property' => 'property1',
            'available' => 0,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item2->id(),
            'property' => 'property1',
            'available' => 1,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item3->id(),
            'property' => 'property1',
            'available' => 0,
        ]);

        $this->assertDatabaseHas('resrv_availabilities', [
            'statamic_id' => $item4->id(),
            'property' => 'property1',
            'available' => 0,
        ]);
    }
}
