<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Reach\StatamicResrv\Database\Factories\EntryFactory;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry as StatamicEntry;

class Entry extends Model
{
    use HandlesMultisiteIds, HasFactory, SoftDeletes;

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
