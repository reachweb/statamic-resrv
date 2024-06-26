<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\OptionValue;

class OptionValueFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OptionValue::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->sentence(3),
            'option_id' => '',
            'price' => '22.75',
            'price_type' => 'perday',
            'order' => 1,
            'published' => true,
        ];
    }

    public function fixed()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => $this->faker->sentence(3),
                'option_id' => '',
                'price' => '30',
                'price_type' => 'fixed',
                'order' => 1,
                'published' => true,
            ];
        });
    }
}
