<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\Option;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'id' => '1',
            'name'=> 'Reservation option',
            'slug' => 'reservation-option',
            'required' => true,
            'order' => 1,
            'item_id' => '',
            'published' => true
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
