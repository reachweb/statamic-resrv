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
        if ($this->config->get('disable_on_cp')
            && request()->is("{$cp}/*")
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

    protected function updateByChangeAmount($availability, $query, $properties = null): void
    {
        $change = $availability->available - $availability->getOriginal('available');

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
        if ($this->config->get('change_by_amount') === true) {
            $query = Availability::where('statamic_id', $availability->statamic_id)
                ->where('date', $availability->date);

            $this->updateByChangeAmount($availability, $query);

            return;
        }

        Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date)
            ->whereNot('property', $availability->property)
            ->update([
                'available' => $availability->available,
            ]);
    }

    public function updateSameSlugConnectedAvailabilities($availability): void
    {
        if ($this->config->get('change_by_amount') === true) {
            $query = Availability::where('date', $availability->date);
            $properties = Availability::where('date', $availability->date)
                ->where('property', $availability->property)
                ->whereNot('statamic_id', $availability->statamic_id)
                ->pluck('property')
                ->toArray();

            $this->updateByChangeAmount($availability, $query, $properties);

            return;
        }

        $available = $availability->available;

        Availability::where('date', $availability->date)
            ->where('property', $availability->property)
            ->whereNot('statamic_id', $availability->statamic_id)
            ->update([
                'available' => $available,
            ]);
    }

    public function updateSelectConnectedAvailabilities($availability): void
    {
        $propertiesToUpdate = $this->config->get('manual_connected_availabilities');

        if (! array_key_exists($availability->property, $propertiesToUpdate)) {
            return;
        }

        $properties = explode(',', $propertiesToUpdate[$availability->property]);

        if ($this->config->get('change_by_amount') === true) {
            $query = Availability::where('statamic_id', $availability->statamic_id)
                ->where('date', $availability->date);

            $this->updateByChangeAmount($availability, $query, $properties);

            return;
        }

        $available = $availability->available;

        foreach ($properties as $property) {
            Availability::where('statamic_id', $availability->statamic_id)
                ->where('date', $availability->date)
                ->where('property', $property)
                ->update([
                    'available' => $availability->available,
                ]);
        }
    }
}
