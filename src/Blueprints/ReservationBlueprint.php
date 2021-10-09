<?php

namespace Reach\StatamicResrv\Blueprints;

use Statamic\Facades\Blueprint;

class ReservationBlueprint
{
    public function __invoke()
    {
        return Blueprint::make()->setContents([
            'sections' => [
                'main'    => [
                    'fields' => [
                        [
                            'handle' => 'id',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'ID',
                            ],
                        ],
                        [
                            'handle' => 'status',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Status',
                            ],
                        ],
                        [
                            'handle' => 'reference',
                            'field'  => [
                                'type'     => 'text',
                                'display'  => 'Reference',
                            ],
                        ],
                        [
                            'handle' => 'entry',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Entry',
                                'sortable' => false
                            ],
                        ],                                        
                        [
                            'handle' => 'date_start',
                            'field'  => [
                                'type'     => 'date',
                                'listable' => true,
                                'display'  => 'Start date',
                            ],
                        ],
                        [
                            'handle' => 'date_end',
                            'field'  => [
                                'type'     => 'date',
                                'listable' => true,
                                'display'  => 'End date',
                            ],
                        ],
                        [
                            'handle' => 'payment',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Deposit',
                            ],
                        ],
                        [
                            'handle' => 'price',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Total',
                            ],
                        ],
                        [
                            'handle' => 'location_start',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Start location',
                            ],
                        ],
                        [
                            'handle' => 'location_end',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'End location',
                            ],
                        ], 
                        [
                            'handle' => 'customer',
                            'field'  => [
                                'type'     => 'text',
                                'listable' => true,
                                'display'  => 'Customer',
                                'sortable' => false
                            ],
                        ],        
                        [
                            'handle' => 'created_at',
                            'field'  => [
                                'type'     => 'date',
                                'listable' => true,
                                'display'  => 'Created at',
                            ],
                        ],
                        [
                            'handle' => 'updated_at',
                            'field'  => [
                                'type'     => 'date',
                                'display'  => 'Updated at',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}