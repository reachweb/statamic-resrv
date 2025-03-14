<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Reach\StatamicResrv\Database\Factories\ExtraCategoryFactory;
use Reach\StatamicResrv\Scopes\OrderScope;
use Reach\StatamicResrv\Traits\HandlesOrdering;

class ExtraCategory extends Model
{
    use HandlesOrdering, HasFactory;

    protected $table = 'resrv_extra_categories';

    protected $fillable = ['name', 'slug', 'description', 'order', 'published'];

    protected static function newFactory()
    {
        return ExtraCategoryFactory::new();
    }

    public function extras()
    {
        return $this->hasMany(Extra::class, 'category_id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new OrderScope);
    }
}
