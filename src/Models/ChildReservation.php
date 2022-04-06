<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\ChildReservationFactory;
use Reach\StatamicResrv\Models\Reservation;

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
        return $this->hasOne(Reservation::class);
    }
   
}
