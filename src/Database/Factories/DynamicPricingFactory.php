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
            'id' => 1,
            'title' => '20% off for 3 days',
            'amount_type' => 'percent',
            'amount_operation' => 'decrease',
            'amount' => '20',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'date_include' => 'all',
            'condition_type' => 'reservation_duration',
            'condition_comparison' => '>=',
            'condition_value' => '3',
            'order' => '1'
        ];
    }

    public function noDates()
    {
        return $this->state(function (array $attributes) {
            return [
                'date_start' => null,
                'date_end' => null,
                'date_include' => null,
            ];
        });
    }
    
    public function extra()
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_type' => 'fixed',
                'amount_operation' => 'decrease',
                'amount' => '2',
                'condition_value' => '2',
            ];
        });
    }

    public function conditionExtraDuration()
    {
        return $this->state(function (array $attributes) {
            return [
                'condition_value' => '7',
            ];
        });
    }
    
    public function conditionPriceOver()
    {
        return $this->state(function (array $attributes) {
            return [
                'condition_type' => 'reservation_price',
                'condition_comparison' => '>=',
                'condition_value' => '100',
            ];
        });
    }
    
    public function conditionPriceUnder()
    {
        return $this->state(function (array $attributes) {
            return [
                'condition_type' => 'reservation_price',
                'condition_comparison' => '<=',
                'condition_value' => '100',
            ];
        });
    }
    

    public function dateMost()
    {
        return $this->state(function (array $attributes) {
            return [
                'date_include' => 'most',
            ];
        });
    }
    
    public function dateStart()
    {
        return $this->state(function (array $attributes) {
            return [
                'date_include' => 'start',
            ];
        });
    }
    
    public function percentIncrease()
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_operation' => 'increase',
                'amount' => '20',
            ];
        });
    }
    
    public function fixedDecrease()
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_type' => 'fixed',
                'amount_operation' => 'decrease',
                'amount' => '20.92',
            ];
        });
    }
    
    public function fixedIncrease()
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_type' => 'fixed',
                'amount_operation' => 'increase',
                'amount' => '10.08',
            ];
        });
    }
}
