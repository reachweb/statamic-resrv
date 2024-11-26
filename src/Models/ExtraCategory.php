<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Database\Factories\ExtraCategoryFactory;
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

    public function frontendCollection(array $data): Collection
    {
        $entry = Entry::itemId($data['item_id'])->first();

        $categories = $this
            ->where('published', true)
            ->with(['extras' => function ($query) use ($entry) {
                $query->whereHas('entries', function ($query) use ($entry) {
                    $query->where('resrv_entries.id', $entry->id);
                });
            }])
            ->orderBy('order', 'asc')
            ->get()
            ->transform(function ($category) {
                $category->extras->each(function ($extra) {
                    $extra->setAttribute('enabled', true);
                });

                return $category;
            });
    }
}
