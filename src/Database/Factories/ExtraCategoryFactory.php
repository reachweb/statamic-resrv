<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\ExtraCategory;

class ExtraCategoryFactory extends Factory
{
    protected $model = ExtraCategory::class;

    public function definition()
    {
        return [
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->paragraph(),
            'order' => 1,
            'published' => true,
        ];
    }
}
