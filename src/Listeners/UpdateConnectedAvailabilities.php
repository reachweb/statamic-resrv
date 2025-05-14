<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\AvailabilityChanged;
use Reach\StatamicResrv\Models\Availability;

class UpdateConnectedAvailabilities
{
    protected $config;

    public function handle(AvailabilityChanged $event): void
    {
        if (config('resrv-config.enable_connected_availabilities') === false) {
            return;
        }

        $availability = $event->availability;

        $this->config = $availability->getConnectedAvailabilitySettings();

        $cp = config('statamic.cp.route');
        if ($this->config->get('disable_on_cp') === true && request()->is("{$cp}/*")
        ) {
            return;
        }

        switch ($this->config->get('connected_availabilities')) {
            case 'none':
                break;
            case 'all':
                $this->updateAllConnectedAvailabilities($availability);
                break;
            case 'same_slug':
                $this->updateSameSlugConnectedAvailabilities($availability);
                break;
            case 'select':
                $this->updateSelectConnectedAvailabilities($availability);
                break;
        }
    }

    protected function calculateChange($availability): int
    {
        return $availability->available - $availability->getOriginal('available');
    }

    protected function processAvailabilityUpdate($availability, $query, $properties = null): void
    {
        if ($this->config->get('change_by_amount') === true) {
            $this->updateByChangeAmount($availability, $query, $properties);

            return;
        }

        if ($this->config->get('block_availability') === true) {
            $this->handleBlockUnblock($availability, $query, $properties);

            return;
        }

        $this->directUpdate($availability, $query, $properties);
    }

    protected function handleBlockUnblock($availability, $query, $properties = null): void
    {
        $change = $this->calculateChange($availability);
        $isPositiveChange = $change > 0;

        // Handle the specific cases differently based on the context
        if ($this->config->get('connected_availabilities') === 'same_slug' && $properties !== null) {
            // Same slug case requires a different query structure
            $baseQuery = Availability::where('date', $availability->date)
                ->where('property', $availability->property)
                ->whereNot('statamic_id', $availability->statamic_id);
        } else {
            // Create base query
            $baseQuery = clone $query;

            if ($properties !== null) {
                // For manually selected properties
                $baseQuery = $baseQuery->whereIn('property', $properties);
            } elseif (isset($availability->property)) {
                // Exclude current property if not in properties list
                $baseQuery = $baseQuery->whereNot('property', $availability->property);
            }
        }

        $items = $baseQuery->get();

        // Apply block or unblock to each item
        $items->each(function ($av) use ($isPositiveChange) {
            if (! $isPositiveChange) {
                $av->block();
            } elseif (! $this->config->get('never_unblock')) {
                $av->unblock();
            }
        });
    }

    protected function directUpdate($availability, $query, $properties = null): void
    {
        // Create base query
        $baseQuery = clone $query;

        if ($properties !== null) {
            // For manually selected properties
            $baseQuery = $baseQuery->whereIn('property', $properties);
        } elseif (isset($availability->property)) {
            // Exclude current property if not in properties list
            $baseQuery = $baseQuery->whereNot('property', $availability->property);
        }

        $baseQuery->update([
            'available' => $availability->available,
        ]);
    }

    protected function updateByChangeAmount($availability, $query, $properties = null): void
    {
        $change = $this->calculateChange($availability);

        if ($properties) {
            // For manually selected properties
            foreach ($properties as $property) {
                $propertyQuery = clone $query;
                $current = $propertyQuery->where('property', $property)->first();
                if (! $current) {
                    continue;
                }
                $newAvailable = $current->available + $change;

                Availability::where('statamic_id', $availability->statamic_id)
                    ->where('date', $availability->date)
                    ->where('property', $property)
                    ->update([
                        'available' => $newAvailable,
                    ]);
            }
        } else {
            // For all properties scenario
            $properties = $availability->getProperties();
            unset($properties[$availability->property]);

            foreach ($properties as $property => $label) {
                $propertyQuery = clone $query;
                $current = $propertyQuery->where('property', $property)->first();

                if (! $current) {
                    continue;
                }
                $newAvailable = $current->available + $change;

                Availability::where('statamic_id', $availability->statamic_id)
                    ->where('date', $availability->date)
                    ->where('property', $property)
                    ->update([
                        'available' => $newAvailable,
                    ]);
            }
        }
    }

    public function updateAllConnectedAvailabilities($availability): void
    {
        $query = Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date);

        $this->processAvailabilityUpdate($availability, $query);
    }

    public function updateSameSlugConnectedAvailabilities($availability): void
    {
        $query = Availability::where('date', $availability->date);
        $properties = Availability::where('date', $availability->date)
            ->where('property', $availability->property)
            ->whereNot('statamic_id', $availability->statamic_id)
            ->pluck('property')
            ->toArray();

        $this->processAvailabilityUpdate($availability, $query, $properties);
    }

    public function updateSelectConnectedAvailabilities($availability): void
    {
        $propertiesToUpdate = $this->config->get('manual_connected_availabilities');

        if (! array_key_exists($availability->property, $propertiesToUpdate)) {
            return;
        }

        $properties = explode(',', $propertiesToUpdate[$availability->property]);

        $query = Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date);

        $this->processAvailabilityUpdate($availability, $query, $properties);
    }
}
