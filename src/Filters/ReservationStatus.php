<?php

namespace Reach\StatamicResrv\Filters;

use Reach\StatamicResrv\Enums\ReservationStatus as Status;
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
                'options' => collect(['confirmed', 'partner', 'awaiting_payment', 'refunded', 'cancelled', 'pending', 'expired'])
                    ->mapWithKeys(fn (string $status) => [$status => Status::labelFor($status)])
                    ->all(),
            ],
        ];
    }

    /** Awaiting-payment holds are shown by default: the admin is expected to act on them. */
    public function autoApply()
    {
        return [
            'status' => ['confirmed', 'partner', 'awaiting_payment'],
        ];
    }

    public function apply($query, $values)
    {
        if (empty($values['status'])) {
            return;
        }
        $query->whereIn('status', $values['status']);
    }

    public function badge($values)
    {
        return implode(', ', array_map(
            fn ($status) => Status::labelFor((string) $status),
            $values['status'] ?? []
        ));
    }

    public function visibleTo($key)
    {
        return $key === 'resrv';
    }
}
