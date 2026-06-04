<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Reach\StatamicResrv\Database\Factories\ChildReservationFactory;
use Reach\StatamicResrv\Money\Price as PriceClass;

class ChildReservation extends Model
{
    use HasFactory;

    protected $table = 'resrv_child_reservations';

    // Explicit allow-list (vs. $guarded = []) so untrusted input can't mass-assign the primary key
    // or any column not written by app code.
    protected $fillable = [
        'reservation_id',
        'date_start',
        'date_end',
        'quantity',
        'rate_id',
        'price',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        'price' => PriceClass::class,
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
        return $this->belongsTo(Rate::class, 'rate_id')->withTrashed();
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
