<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\ChildReservation;

class ChildReservationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ChildReservation::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'reservation_id' => '',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
            'quantity' => 1,
        ];
    }
}
