<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;

class ReservationLog extends Model
{
    protected $table = 'resrv_reservation_logs';

    /** Log rows are immutable — created_at only. */
    const UPDATED_AT = null;

    protected $fillable = [
        'reservation_id',
        'reference',
        'status_from',
        'status_to',
        'reason',
        'context',
        'actor_id',
        'actor_name',
    ];

    protected $casts = [
        'context' => 'array',
        'reason' => ReservationLogReason::class,
        'status_from' => ReservationStatus::class,
        'status_to' => ReservationStatus::class,
    ];

    public function scopeForReservation(Builder $query, int $reservationId): Builder
    {
        return $query->where('reservation_id', $reservationId);
    }
}
