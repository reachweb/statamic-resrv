<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Database\Factories\EntryFactory;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Traits\HandlesCutoffRules;
use Reach\StatamicResrv\Traits\HandlesMultisiteIds;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Blueprint;

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

    // Returns the ID of the Statamic entry
    public function id(): string
    {
        return $this->item_id;
    }

    public function syncToDatabase(StatamicEntry $entry): void
    {
        if (! $field = AvailabilityField::getField($entry->blueprint())) {
            return;
        }

        if ($entry->hasOrigin()) {
            return;
        }

        $resrvEntry = static::withTrashed()->updateOrCreate(
            [
                'item_id' => $entry->id(),
            ],
            [
                'title' => $entry->get('title'),
                'enabled' => $entry->get($field->handle()) === 'disabled' ? false : true,
                'collection' => $entry->collection()->handle(),
                'handle' => $entry->blueprint()->handle(),
            ]
        );

        if ($resrvEntry->trashed()) {
            $resrvEntry->restore();
        }

        Cache::forget('resrv_disabled_entry_ids');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class, 'statamic_id', 'item_id');
    }

    public function getStatamicEntry(): StatamicEntry
    {
        return StatamicEntry::find($this->item_id);
    }

    public function getAvailabilityField(): ?\Statamic\Fields\Field
    {
        return AvailabilityField::getField($this->getBlueprint());
    }

    public function getBlueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::findOrFail('collections.'.$this->collection.'.'.$this->handle);
    }

    public function isDisabled(): bool
    {
        return ! $this->enabled;
    }

    public static function getDisabledIds(): array
    {
        return Cache::remember('resrv_disabled_entry_ids', 300, function () {
            return static::where('enabled', false)
                ->pluck('item_id')
                ->toArray();
        });
    }
}
