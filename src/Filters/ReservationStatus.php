<?php

namespace Reach\StatamicResrv\Filters;

use Statamic\Query\Scopes\Filter;

class ReservationStatus extends Filter
{
    //protected $pinned = true;

    public function fieldItems()
    {
        return [
            'status' => [
                'type' => 'radio',
                'options' => [
                    'confirmed' => 'Confirmed',
                    'refunded' => 'Refunded',
                    'pending' => 'Pending',
                    'expired' => 'Expired',
                ],
            ],
        ];
    }

    public function autoApply()
    {
        return [
            'status' => 'confirmed',
        ];
    }

    public function apply($query, $values)
    {
        $query->where('status', $values['status']);
    }

    public function badge($values)
    {
        switch ($values['status']) {
            case 'confirmed':
                return 'Confirmed';
            case 'refunded':
                return 'Refunded';
            case 'pending':
                return 'Pending';
            case 'expired':
                return 'Expired';
        }
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}
