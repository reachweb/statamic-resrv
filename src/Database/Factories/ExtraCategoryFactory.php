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
            'name' => 'This is an extra category',
            'slug' => 'this-is-an-extra-category',
            'description' => $this->faker->optional()->paragraph(),
            'order' => 1,
            'published' => true,
        ];
    }
}
