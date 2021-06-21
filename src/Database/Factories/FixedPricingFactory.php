<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\FixedPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

class FixedPricingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FixedPricing::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'statamic_id' => '',
            'days'=> 3,
            'price' => '77.35'
        ];
    }
}
