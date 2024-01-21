<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Option;

class OptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Option::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => 'Reservation option',
            'slug' => 'reservation-option',
            'description' => 'Select this option to improve your experience!',
            'required' => true,
            'order' => 1,
            'item_id' => '',
            'published' => true,
        ];
    }

    public function notRequired()
    {
        return $this->state(function (array $attributes) {
            return [
                'required' => false,
            ];
        });
    }
}
