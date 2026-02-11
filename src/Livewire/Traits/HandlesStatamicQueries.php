<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Collection;
use Reach\StatamicResrv\Exceptions\CheckoutEntryNotFound;
use Reach\StatamicResrv\Models\Rate;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

trait HandlesStatamicQueries
{
    public function getRatesForEntry(string $entryId): Collection
    {
        return Rate::forEntry($entryId)
            ->published()
            ->orderBy('order')
            ->get();
    }

    protected function resolveEntryRates(string $entryId): array
    {
        return $this->getRatesForEntry($entryId)
            ->mapWithKeys(fn ($rate) => [$rate->id => $rate->title])
            ->toArray();
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
