<?php

namespace Reach\StatamicResrv\Contracts\Models;

interface AvailabilityContract
{
    public function scopeEntry($query, $entry);

    public function getPriceAttribute($value);

    public function getAvailableItems($data);

    public function getAvailabilityForItem($data, $statamic_id);

    public function confirmAvailabilityAndPrice($data, $statamic_id);

    public function decrementAvailability($date_start, $date_end, $quantity, $advanced, $statamic_id);

    public function incrementAvailability($date_start, $date_end, $quantity, $advanced, $statamic_id);

    public function deleteForDates($date_start, $date_end, $advanced, $statamic_id);
}
