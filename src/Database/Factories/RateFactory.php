<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Rate;

class RateFactory extends Factory
{
    protected $model = Rate::class;

    public function definition(): array
    {
        return [
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Standard Rate',
            'slug' => 'standard-rate',
            'description' => null,
            'pricing_type' => 'independent',
            'base_rate_id' => null,
            'modifier_type' => null,
            'modifier_operation' => null,
            'modifier_amount' => null,
            'availability_type' => 'independent',
            'max_available' => null,
            'date_start' => null,
            'date_end' => null,
            'min_days_before' => null,
            'max_days_before' => null,
            'min_stay' => null,
            'max_stay' => null,
            'refundable' => true,
            'order' => 0,
            'published' => true,
        ];
    }

    public function relative(): static
    {
        return $this->state(fn () => [
            'title' => 'Discounted Rate',
            'slug' => 'discounted-rate',
            'pricing_type' => 'relative',
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 20.00,
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn () => [
            'title' => 'Shared Rate',
            'slug' => 'shared-rate',
            'availability_type' => 'shared',
        ]);
    }

    public function withRestrictions(): static
    {
        return $this->state(fn () => [
            'title' => 'Restricted Rate',
            'slug' => 'restricted-rate',
            'date_start' => now()->startOfDay(),
            'date_end' => now()->addMonths(3)->endOfDay(),
            'min_days_before' => 2,
            'max_days_before' => 30,
            'min_stay' => 2,
            'max_stay' => 14,
        ]);
    }
}
