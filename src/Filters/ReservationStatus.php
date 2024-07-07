<?php

namespace Reach\StatamicResrv\Filters;

use Statamic\Query\Scopes\Filter;

class ReservationStatus extends Filter
{
    protected $pinned = true;

    public static function title()
    {
        return __('Status');
    }

    public function fieldItems()
    {
        return [
            'status' => [
                'type' => 'checkboxes',
                'options' => [
                    'confirmed' => 'Confirmed',
                    'partner' => 'Partner',
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
            'status' => ['confirmed', 'partner'],
        ];
    }

    public function apply($query, $values)
    {
        $query->whereIn('status', $values['status']);
    }

    public function badge($values)
    {
        return implode(', ', array_map('ucwords', $values['status']));
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}
