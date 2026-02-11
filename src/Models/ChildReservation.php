<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Reach\StatamicResrv\Database\Factories\ChildReservationFactory;

class ChildReservation extends Model
{
    use HasFactory;

    protected $table = 'resrv_child_reservations';

    protected $guarded = [];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
    ];

    protected static function newFactory()
    {
        return ChildReservationFactory::new();
    }

    public function parent()
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    public function getRateLabel(): string
    {
        return $this->rate?->title ?? 'Default';
    }

    public function getPropertyAttributeLabel(): string
    {
        return $this->getRateLabel();
    }
}
