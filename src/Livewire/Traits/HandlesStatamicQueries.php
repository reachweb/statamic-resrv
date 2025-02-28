<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use MarcoRieser\Livewire\RestoreCurrentSite;
use Reach\StatamicResrv\Exceptions\BlueprintNotFoundException;
use Reach\StatamicResrv\Exceptions\CheckoutEntryNotFound;
use Reach\StatamicResrv\Exceptions\FieldNotFoundException;
use Reach\StatamicResrv\Exceptions\NoAdvancedAvailabilitySet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

trait HandlesStatamicQueries
{
    use RestoreCurrentSite;

    public function getProperties()
    {
        return $this->getPropertiesFromBlueprint();
    }

    public function getStatamicBlueprint()
    {
        if ($blueprint = Blueprint::find('collections.'.$this->advanced)) {
            return $blueprint;
        }
        throw new BlueprintNotFoundException($this->advanced);
    }

    public function getStatamicField($blueprint)
    {
        if ($field = $blueprint->field('resrv_availability')) {
            return $field;
        }
        throw new FieldNotFoundException('resrv_availability', $this->advanced);
    }

    public function getPropertiesFromBlueprint()
    {
        $blueprint = $this->getStatamicBlueprint();
        $field = $this->getStatamicField($blueprint);

        $config = $field->config();

        if (isset($config['advanced_availability'])) {
            return $config['advanced_availability'];
        }
        throw new NoAdvancedAvailabilitySet($this->advanced);
    }

    public function getCheckoutEntry()
    {
        if ($entry = Entry::find(config('resrv-config.checkout_entry'))) {
            if ($localizedCheckout = $this->getLocalizedEntry($entry)) {
                return $localizedCheckout;
            }

            return $entry;
        }
        throw new CheckoutEntryNotFound;
    }

    public function getCheckoutCompleteEntry()
    {
        if ($entry = Entry::find(config('resrv-config.checkout_completed_entry'))) {
            if ($localizedComplete = $this->getLocalizedEntry($entry)) {
                return $localizedComplete;
            }

            return $entry;
        }
        throw new CheckoutEntryNotFound;
    }

    public function getLocalizedEntry($entry)
    {
        if ($localizedEntry = $entry->in(Site::current()->handle())) {
            if ($this->isSafe($localizedEntry)) {
                return $localizedEntry;
            }
        }

        return false;
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
