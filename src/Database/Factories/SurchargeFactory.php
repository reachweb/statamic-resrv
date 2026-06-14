<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Surcharge;

class SurchargeFactory extends Factory
{
    protected $model = Surcharge::class;

    public function definition()
    {
        return [
            'name' => 'One-way fee',
            'slug' => 'one-way-fee',
            'first_option_id' => null,
            'second_option_id' => null,
            'comparison' => 'differs',
            'price' => '50.00',
            'order' => 1,
            'published' => true,
        ];
    }

    /**
     * Configure the two Options this surcharge compares.
     */
    public function between(int $firstOptionId, int $secondOptionId): static
    {
        return $this->state(fn () => [
            'first_option_id' => $firstOptionId,
            'second_option_id' => $secondOptionId,
        ]);
    }

    public function matches(): static
    {
        return $this->state(fn () => ['comparison' => 'matches']);
    }
}
