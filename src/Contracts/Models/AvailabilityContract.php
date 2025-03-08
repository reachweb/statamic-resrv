<?php

namespace Reach\StatamicResrv\Contracts\Models;

use Reach\StatamicResrv\Models\Reservation;

interface AvailabilityContract
{
    public function getPriceAttribute($value);

    public function getAvailable(array $data);

    public function getAvailabilityForEntry(array $data, string $statamic_id);

    public function confirmAvailabilityAndPrice(array $data, string $statamic_id);

    public function decrementAvailability(Reservation $reservation);

    public function incrementAvailability(Reservation $reservation);

    public function deleteForDates(string $date_start, string $date_end, string $statamic_id, ?array $advanced);
}
