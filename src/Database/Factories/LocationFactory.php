<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Location::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'id' => '1',
            'name'=> 'Location',
            'slug' => 'location',
            'extra_charge' => '5.72',
            'order' => 1,
            'published' => true
        ];
    }
}
