<?php

namespace Reach\StatamicResrv\Listeners;

use Illuminate\Database\Eloquent\Builder;
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
        if ($this->isDisabledOnCp() === true && request()->is("{$cp}/*")
        ) {
            return;
        }

        foreach ($this->config->get('connected_availabilities') as $config) {
            switch ($config['connected_availability_type']) {
                case 'all':
                    $this->updateAllConnectedAvailabilities($availability, $config);
                    break;
                case 'same_slug':
                    $this->updateSameSlugConnectedAvailabilities($availability, $config);
                    break;
                case 'select':
                    $this->updateSelectConnectedAvailabilities($availability, $config);
                    break;
            }
        }
    }

    protected function isDisabledOnCp(): bool
    {
        return $this->config->get('disable_connected_availabilities_on_cp') === true;
    }

    protected function calculateChange(Availability $availability): int
    {
        return $availability->available - $availability->getOriginal('available');
    }

    protected function processAvailabilityUpdate(Availability $availability, array $config, Builder $query, ?array $properties = null): void
    {
        if ($config['block_type'] === 'change_by_amount') {
            $this->updateByChangeAmount($availability, $query, $properties);

            return;
        }

        if ($config['block_type'] === 'block_availability') {
            $this->handleBlockUnblock($availability, $config, $query, $properties);

            return;
        }

        $this->directUpdate($availability, $query, $properties);
    }

    protected function handleBlockUnblock(Availability $availability, array $config, Builder $query, ?array $properties = null): void
    {
        $change = $this->calculateChange($availability);
        $isPositiveChange = $change > 0;

        // Handle the specific cases differently based on the context
        if ($config['connected_availability_type'] === 'same_slug' && $properties !== null) {
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
        $items->each(function ($av) use ($isPositiveChange, $config) {
            if (! $isPositiveChange) {
                $av->block();
            } elseif (! (isset($config['never_unblock']) && $config['never_unblock'] === true)) {
                $av->unblock();
            }
        });
    }

    protected function directUpdate(Availability $availability, Builder $query, $properties = null): void
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

    protected function updateByChangeAmount(Availability $availability, Builder $query, ?array $properties = null): void
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

    public function updateAllConnectedAvailabilities(Availability $availability, array $config): void
    {
        $query = Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date);

        $this->processAvailabilityUpdate($availability, $config, $query);
    }

    public function updateSameSlugConnectedAvailabilities(Availability $availability, array $config): void
    {
        $query = Availability::where('date', $availability->date);
        $properties = Availability::where('date', $availability->date)
            ->where('property', $availability->property)
            ->whereNot('statamic_id', $availability->statamic_id)
            ->pluck('property')
            ->toArray();

        $this->processAvailabilityUpdate($availability, $config, $query, $properties);
    }

    public function updateSelectConnectedAvailabilities(Availability $availability, array $config): void
    {
        $propertiesToUpdate = $config['manually_connected_availabilities'];

        if (! array_key_exists($availability->property, $propertiesToUpdate)) {
            return;
        }

        $properties = explode(',', $propertiesToUpdate[$availability->property]);

        $query = Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date);

        $this->processAvailabilityUpdate($availability, $config, $query, $properties);
    }
}
