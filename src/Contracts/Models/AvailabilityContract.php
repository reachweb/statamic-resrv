<?php

namespace Reach\StatamicResrv\Contracts\Models;

interface AvailabilityContract
{
    public function getPriceAttribute($value);

    public function getAvailable(array $data);

    public function getAvailabilityForEntry(array $data, string $statamic_id);

    public function confirmAvailabilityAndPrice(array $data, string $statamic_id);

    public function decrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced);

    public function incrementAvailability(string $date_start, string $date_end, int $quantity, string $statamic_id, int $reservationId, ?string $advanced);

    public function deleteForDates(string $date_start, string $date_end, string $statamic_id, ?array $advanced);
}
