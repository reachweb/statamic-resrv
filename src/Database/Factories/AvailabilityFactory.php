<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\Availability;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Availability::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'statamic_id' => '',
            'date'=> today(),
            'available' => 2,
            'price' => '150'
        ];
    }
}
