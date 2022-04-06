<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function getPropertyAttributeLabel()
    {
        if ($this->property == null) {
            return '';
        }
        $availability = new AdvancedAvailability;
        return $availability->getPropertyLabel($this->parent->entry()->blueprint, $this->parent->entry()->collection()->handle(), $this->property);
    }
}
