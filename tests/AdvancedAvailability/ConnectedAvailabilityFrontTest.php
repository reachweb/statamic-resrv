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
}
