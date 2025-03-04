<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\ChildReservationFactory;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class ChildReservation extends Model
{
    use HasFactory;

    protected $table = 'resrv_child_reservations';

    protected $guarded = [];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        'price' => PriceClass::class,
        'payment' => PriceClass::class,
        'total' => PriceClass::class,
    ];

    protected $appends = ['entry'];

    protected static function newFactory()
    {
        return ChildReservationFactory::new();
    }

    public function parent()
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function entry()
    {
        return $this->belongsTo(Entry::class, 'item_id');
    }

    public function getPriceAttribute($value)
    {
        return $value ? Price::create($value) : null;
    }

    public function getPaymentAttribute($value)
    {
        return $value ? Price::create($value) : null;
    }

    public function getTotalAttribute($value)
    {
        return $value ? Price::create($value) : null;
    }

    public function getPropertyAttributeLabel()
    {
        if ($this->property === null) {
            return '';
        }

        $availability = app(Availability::class);

        if (! $this->entry) {
            return $this->property;
        }

        return $availability->getPropertyLabel(
            $this->entry->blueprint,
            $this->entry->collecton,
            $this->property
        );
    }

    protected function emptyEntry()
    {
        return [
            'id' => null,
            'title' => '## Entry deleted ##',
            'api_url' => '## Entry deleted ##',
            'permalink' => '#',
        ];
    }
}
