<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\AdvancedAvailability;

class AdvancedAvailabilityFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AdvancedAvailability::class;

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
            'price' => '150',
            'property' => 'something',
        ];
    }
}
