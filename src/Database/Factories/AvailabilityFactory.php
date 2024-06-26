<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Availability;

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
     */
    public function definition(): array
    {
        return [
            'statamic_id' => '',
            'date' => today(),
            'available' => 2,
            'price' => '150',
            'property' => 'none',
            'pending' => [],
        ];
    }

    public function advanced()
    {
        return $this->state(function (array $attributes) {
            return [
                'property' => 'something',
            ];
        });
    }

    public function withPendingArray()
    {
        return $this->state(function (array $attributes) {
            return [
                'pending' => [1, 2, 3],
            ];
        });
    }
}
