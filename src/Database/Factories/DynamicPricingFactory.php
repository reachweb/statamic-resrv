<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\DynamicPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

class DynamicPricingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DynamicPricing::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'title' => '20% off for 3 days',
            'amount_type' => 'percent',
            'amount' => '20',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(60, 'day')->toIso8601String(),
            'condition_type' => 'reservation_duration',
            'condition_comparison' => '>=',
            'condition_value' => '3',
        ];
    }
}
