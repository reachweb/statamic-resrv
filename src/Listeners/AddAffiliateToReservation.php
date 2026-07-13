<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Enums\AffiliateAttributionSource;
use Reach\StatamicResrv\Events\ReservationCreated;

class AddAffiliateToReservation
{
    public function handle(ReservationCreated $event)
    {
        if ($event->data->hasAffiliate()) {
            // Snapshot the name/code so the report keeps history even after the affiliate is deleted.
            $event->reservation->affiliate()->attach($event->data->affiliate, [
                'fee' => $event->data->affiliate->fee,
                'source' => AffiliateAttributionSource::Cookie->value,
                'data' => json_encode([
                    'name' => $event->data->affiliate->name,
                    'code' => $event->data->affiliate->code,
                ]),
            ]);
        }
    }
}
