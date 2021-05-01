<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\ExtraFactory;
use Reach\StatamicResrv\Models\StatamicEntry;
use Illuminate\Support\Facades\DB;

class Extra extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'price', 'price_type', 'allow_multiple', 'maximum', 'description', 'published'];

    protected $casts = [
        'published' => 'boolean',
        'allow_multiple' => 'boolean',
    ];

    protected static function newFactory()
    {
        return ExtraFactory::new();
    }

    public function scopeEntry($query, $entry)
    {
        return DB::table('extras')
            ->join('statamicentry_extra', function ($join) use ($entry) {
                $join->on('extras.id', '=', 'statamicentry_extra.extra_id')
                    ->where('statamicentry_extra.statamicentry_id', '=', $entry);
            })
            ->select('extras.*');
    }
}
