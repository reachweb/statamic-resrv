<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Enums\AvailabilityChangeReason;

class AvailabilityChange extends Model
{
    protected $table = 'resrv_availability_changes';

    /** Log rows are immutable — created_at only. */
    const UPDATED_AT = null;

    protected $fillable = [
        'batch',
        'statamic_id',
        'rate_id',
        'date',
        'action',
        'field',
        'old_value',
        'new_value',
        'reason',
        'reservation_id',
        'actor_id',
        'actor_name',
    ];

    protected $casts = [
        'date' => 'date',
        'reason' => AvailabilityChangeReason::class,
    ];

    public function scopeForEntry(Builder $query, string $statamicId): Builder
    {
        return $query->where('statamic_id', $statamicId);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForBatch(Builder $query, string $batch): Builder
    {
        return $query->where('batch', $batch);
    }
}
