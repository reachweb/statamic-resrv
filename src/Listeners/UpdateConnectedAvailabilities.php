<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\AvailabilityChanged;
use Reach\StatamicResrv\Models\Availability;

class UpdateConnectedAvailabilities
{
    public function handle(AvailabilityChanged $event): void
    {
        if (config('resrv-config.enable_connected_availabilities') === false) {
            return;
        }

        $availability = $event->availability;

        switch ($availability->getConnectedAvailabilitySetting()) {
            case 'none':
                break;
            case 'all':
                $this->updateAllConnectedAvailabilities($availability);
                break;
            case 'same_slug':
                // method
                break;
            case 'select':
                // method
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
}
