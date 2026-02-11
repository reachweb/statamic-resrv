<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Exceptions\CheckoutEntryNotFound;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Models\Rate;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

trait HandlesStatamicQueries
{
    public function getRatesForEntry(string $entryId): Collection
    {
        return Rate::where('statamic_id', $entryId)
            ->published()
            ->orderBy('order')
            ->get();
    }

    public function getEntryProperties($entry): array
    {
        $blueprint = $entry->blueprint();
        $field = $blueprint ? AvailabilityField::getField($blueprint) : null;

        return $field?->config()['advanced_availability'] ?? [];
    }

    protected function resolveEntryRates(string $entryId): array
    {
        $rates = $this->getRatesForEntry($entryId);

        if ($rates->isNotEmpty()) {
            return $rates->mapWithKeys(fn ($rate) => [$rate->id => $rate->title])->toArray();
        }

        // Fallback to blueprint properties for backward compatibility
        $entry = $this->getEntry($entryId);

        return $entry ? $this->getEntryProperties($entry) : [];
    }

    public function getCheckoutEntry()
    {
        return $this->findEntryOrFail(config('resrv-config.checkout_entry'));
    }

    public function getCheckoutCompleteEntry()
    {
        return $this->findEntryOrFail(config('resrv-config.checkout_completed_entry'));
    }

    protected function findEntryOrFail(string $entryId)
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new CheckoutEntryNotFound;
        }

        return $this->getLocalizedEntry($entry) ?? $entry;
    }

    public function getLocalizedEntry($entry)
    {
        $localizedEntry = $entry->in(Site::current()->handle());

        if ($localizedEntry && $this->isSafe($localizedEntry)) {
            return $localizedEntry;
        }

        return null;
    }

    public function getEntry($id)
    {
        return Entry::find($id);
    }

    public function isSafe($content)
    {
        return $content->published()
            && ! $content->private()
            && $content->url();
    }
}
