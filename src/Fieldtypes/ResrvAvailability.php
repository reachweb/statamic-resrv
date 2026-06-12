<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Reach\StatamicResrv\Models\Availability;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Fields\Fieldtype;

class ResrvAvailability extends Fieldtype
{
    protected $icon = 'calendar';

    public function augment($value)
    {
        if (! $value || $value === 'disabled') {
            return false;
        }

        $availability_data = Availability::where('statamic_id', $this->augmentedEntryId() ?? $value)
            ->where('available', '>', '0')
            ->get();

        if ($availability_data->count() == 0) {
            return false;
        }

        $data = $availability_data->sortBy('date')->keyBy('date')->toArray();
        $cheapest = $availability_data->sortBy('price')->firstWhere('available', '>', '0')->price->format();

        return compact('data', 'cheapest');
    }

    /**
     * Prefer the entry's root ID over the stored value, which can go stale (e.g. on duplication).
     */
    private function augmentedEntryId(): ?string
    {
        $parent = $this->field?->parent();

        if (! $parent instanceof EntryContract) {
            return null;
        }

        return $parent->root()->id();
    }

    public function preload(): array
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }

        $parent = $this->field->parent()->root()->id();

        return [
            'parent' => $parent,
            'currency_symbol' => config('resrv-config.currency_symbol'),
        ];
    }

    protected function configFieldItems(): array
    {
        return [];
    }
}
