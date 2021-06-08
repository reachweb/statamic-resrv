<?php

namespace Reach\StatamicResrv\Database\Factories;

use Reach\StatamicResrv\Models\Extra;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExtraFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Extra::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'id' => '1',
            'name'=> 'This is an extra',
            'slug' => 'this-is-an-extra',
            'price' => '4.65',
            'price_type' => 'perday',
            'allow_multiple' => true,
            'maximum' => 3,
            'order' => 1,
            'published' => true
        ];
    }
}
