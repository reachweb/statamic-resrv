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
                case 'specific_slugs':
                    $this->updateSlugConnectedAvailabilities($availability, $config);
                    break;
                case 'select':
                    $this->updateSelectConnectedAvailabilities($availability, $config);
                    break;
                case 'entries':
                    $this->updateEntriesConnectedAvailabilities($availability, $config);
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

        // Build query with optional same_slug handling
        $baseQuery = $this->filterQuery($query, $availability, $properties, $config);

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

    /**
     * Filter a query by properties or exclude current property.
     * Accepts optional $config to handle special block/unblock cases.
     */
    protected function filterQuery(Builder $query, Availability $availability, ?array $properties, ?array $config = null): Builder
    {
        // Special same_slug case when blocking/unblocking
        if ($config !== null && ($config['connected_availability_type'] ?? null) === 'same_slug' && $properties !== null) {
            return Availability::where('date', $availability->date)
                ->where('property', $availability->property)
                ->whereNot('statamic_id', $availability->statamic_id);
        }
        $base = clone $query;
        if ($properties !== null) {
            return $base->whereIn('property', $properties);
        } elseif (isset($availability->property)) {
            return $base->whereNot('property', $availability->property);
        }

        return $base;
    }

    protected function directUpdate(Availability $availability, Builder $query, $properties = null): void
    {
        // Build query with property filters
        $baseQuery = $this->filterQuery($query, $availability, $properties);

        // Update without firing events to prevent loops
        Availability::withoutEvents(function () use ($baseQuery, $availability) {
            $baseQuery->update([
                'available' => $availability->available,
            ]);
        });
    }

    protected function updateByChangeAmount(Availability $availability, Builder $query, ?array $properties = null): void
    {
        $change = $this->calculateChange($availability);

        if ($properties) {
            // For manually selected properties
            foreach ($properties as $property) {
                $propertyQuery = $this->filterQuery($query, $availability, [$property]);
                $current = $propertyQuery->first();
                if (! $current) {
                    continue;
                }
                $newAvailable = $current->available + $change;

                Availability::withoutEvents(function () use ($availability, $property, $newAvailable) {
                    Availability::where('statamic_id', $availability->statamic_id)
                        ->where('date', $availability->date)
                        ->where('property', $property)
                        ->update([
                            'available' => $newAvailable,
                        ]);
                });
            }
        } else {
            // For all properties scenario
            $properties = $availability->getProperties();
            unset($properties[$availability->property]);

            foreach ($properties as $property => $label) {
                $propertyQuery = $this->filterQuery($query, $availability, [$property]);
                $current = $propertyQuery->first();

                if (! $current) {
                    continue;
                }
                $newAvailable = $current->available + $change;

                Availability::withoutEvents(function () use ($availability, $property, $newAvailable) {
                    Availability::where('statamic_id', $availability->statamic_id)
                        ->where('date', $availability->date)
                        ->where('property', $property)
                        ->update([
                            'available' => $newAvailable,
                        ]);
                });
            }
        }
    }

    public function updateAllConnectedAvailabilities(Availability $availability, array $config): void
    {
        $query = Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date);

        $this->processAvailabilityUpdate($availability, $config, $query);
    }

    public function updateSlugConnectedAvailabilities(Availability $availability, array $config): void
    {
        $query = Availability::where('date', $availability->date);
        if ($config['connected_availability_type'] === 'specific_slugs') {
            $properties = explode(',', $config['slugs_to_sync']);
            // If the availability changing is not in the list of properties, return
            if (! in_array($availability->property, $properties)) {
                return;
            }
        } else {
            $properties = Availability::where('date', $availability->date)
                ->where('property', $availability->property)
                ->whereNot('statamic_id', $availability->statamic_id)
                ->pluck('property')
                ->toArray();
        }
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

    public function updateEntriesConnectedAvailabilities(Availability $availability, array $config): void
    {
        // Check if there are connected entries configured
        if (empty($config['connected_entries'])) {
            return;
        }

        // Find the connected entry group that contains the current entry
        $currentStatamicId = $availability->statamic_id;
        $connectedGroups = collect();

        foreach ($config['connected_entries'] as $group) {
            $entries = $group['entries'] ?? [];
            if (in_array($currentStatamicId, $entries)) {
                $connectedGroups->push($entries);
            }
        }

        // If the current statamic_id is not part of any group, return
        if ($connectedGroups->isEmpty()) {
            return;
        }

        // Merge all matching groups into one and remove duplicate IDs
        $connectedGroup = $connectedGroups
            ->flatten()
            ->unique()
            ->reject(fn ($entryId) => $entryId === $currentStatamicId)
            ->all();

        // If no other entries in the group, return
        if (empty($connectedGroup)) {
            return;
        }

        // Check if we should sync only the same property or all properties
        $syncSamePropertyOnly = isset($config['entries_sync_same_property_only'])
            ? $config['entries_sync_same_property_only']
            : true; // Default to true for backward compatibility

        // Create a query for all connected entries with the same date
        $query = Availability::whereIn('statamic_id', $connectedGroup)
            ->where('date', $availability->date);

        // If property is set and we want to sync only the same property,
        // match the same property in connected entries
        if (isset($availability->property) && $syncSamePropertyOnly) {
            $properties = [$availability->property];
        } else {
            // Otherwise, get all properties from the connected entries
            $properties = Availability::whereIn('statamic_id', $connectedGroup)
                ->where('date', $availability->date)
                ->pluck('property')
                ->toArray();
        }

        // Process the availability update for all entries in the group
        $this->processAvailabilityUpdate($availability, $config, $query, $properties);
    }
}
