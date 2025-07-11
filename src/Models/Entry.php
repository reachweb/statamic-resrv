<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\EntryFactory;
use Reach\StatamicResrv\Traits\HandlesCutoffRules;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry as StatamicEntry;

class Entry extends Model
{
    use HandlesCutoffRules, HandlesMultisiteIds, HasFactory, SoftDeletes;

    protected $table = 'resrv_entries';

    protected $fillable = ['item_id', 'title', 'enabled', 'collection', 'handle', 'options'];

    protected $casts = [
        'enabled' => 'boolean',
        'options' => 'array',
    ];

    protected static function newFactory()
    {
        return EntryFactory::new();
    }

    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_entry_extra');
    }

    public function scopeItemId(Builder $query, string $id): void
    {
        $query->where('item_id', $id);
    }

    public static function whereItemId(string $id): ?static
    {
        return static::query()->itemId($id)->firstOrFail();
    }

    public function syncToDatabase(StatamicEntry $entry): void
    {
        if (! $entry->blueprint()->hasField('resrv_availability')) {
            return;
        }

        if ($entry->hasOrigin()) {
            return;
        }

        $this->updateOrCreate(
            [
                'item_id' => $entry->id(),
            ],
            [
                'title' => $entry->get('title'),
                'enabled' => $entry->get('resrv_availability') === 'disabled' ? false : true,
                'collection' => $entry->collection()->handle(),
                'handle' => $entry->blueprint()->handle(),
            ]
        );
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class, 'statamic_id', 'item_id');
    }

    public function getStatamicEntry(): StatamicEntry
    {
        return StatamicEntry::find($this->item_id);
    }
}
