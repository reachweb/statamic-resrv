<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Extra;

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
            'name' => 'This is an extra',
            'slug' => 'this-is-an-extra',
            'price' => '4.65',
            'price_type' => 'perday',
            'allow_multiple' => true,
            'maximum' => 3,
            'order' => 1,
            'description' => '',
            'published' => true,
        ];
    }

    public function fixed()
    {
        return $this->state(function (array $attributes) {
            return [
                'id' => '2',
                'name' => 'This is a fixed extra',
                'slug' => 'this-is-a-fixed-extra',
                'price' => '25',
                'price_type' => 'fixed',
            ];
        });
    }

    public function relative()
    {
        return $this->state(function (array $attributes) {
            return [
                'id' => '3',
                'name' => 'This is a relative extra',
                'slug' => 'this-is-a-relative-extra',
                'price' => '0.5',
                'price_type' => 'relative',
            ];
        });
    }

    public function custom()
    {
        return $this->state(function (array $attributes) {
            return [
                'id' => '4',
                'name' => 'This is a relative extra',
                'slug' => 'this-is-a-relative-extra',
                'price' => '10',
                'price_type' => 'custom',
                'custom' => 'adults',
                'override_label' => 'per adult',
            ];
        });
    }
}
