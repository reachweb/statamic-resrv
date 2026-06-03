<?php

namespace Reach\StatamicResrv\Tests\Availabilty;

use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\TestCase;

class AvailabilityValidationTest extends TestCase
{
    private function searchData(int $quantity): array
    {
        return [
            'date_start' => now()->addDay()->toDateString(),
            'date_end' => now()->addDays(2)->toDateString(),
            'quantity' => $quantity,
        ];
    }

    public function test_initiate_availability_rejects_zero_quantity()
    {
        $this->expectException(AvailabilityException::class);

        (new Availability)->initiateAvailability($this->searchData(0));
    }

    public function test_initiate_availability_rejects_negative_quantity()
    {
        $this->expectException(AvailabilityException::class);

        (new Availability)->initiateAvailability($this->searchData(-1));
    }
}
