<?php

namespace Reach\StatamicResrv\Listeners;

use Reach\StatamicResrv\Events\AvailabilitySearch;

class SaveSearchToSession
{
    public function handle(AvailabilitySearch $event)
    {
        session(['resrv_search' => $event->data]);
    }
}
