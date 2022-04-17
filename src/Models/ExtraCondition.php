<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\ExtraConditionFactory;

class ExtraCondition extends Model
{
    use HasFactory;

    protected $table = 'resrv_extra_conditions';

    protected $guarded = [];

    protected $casts = [
        'conditions' => AsCollection::class,
    ];

    protected static function newFactory()
    {
        return ExtraConditionFactory::new();
    }

    public function parent()
    {
        return $this->belongsTo(Extra::class, 'extra_id');
    }
}
