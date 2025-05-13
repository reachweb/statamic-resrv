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

    public function updateAllConnectedAvailabilities($availability): void
    {
        Availability::where('statamic_id', $availability->statamic_id)
            ->where('date', $availability->date)
            ->whereNot('property', $availability->property)
            ->update([
                'available' => $availability->available,
            ]);
    }

    public function updateSameSlugConnectedAvailabilities($availability): void
    {
        Availability::where('date', $availability->date)
            ->where('property', $availability->property)
            ->whereNot('statamic_id', $availability->statamic_id)
            ->update([
                'available' => $availability->available,
            ]);
    }

    public function updateSelectConnectedAvailabilities($availability): void
    {
        $propertiesToUpdate = $this->config->get('manual_connected_availabilities');

        if (! array_key_exists($availability->property, $propertiesToUpdate)) {
            return;
        }

        $properties = explode(',', $propertiesToUpdate[$availability->property]);

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
