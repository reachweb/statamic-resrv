<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Reach\StatamicResrv\Database\Factories\CustomerFactory;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'resrv_customers';

    protected $guarded = [];

    protected $casts = [
        'data' => AsCollection::class,
    ];

    protected static function newFactory()
    {
        return CustomerFactory::new();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
