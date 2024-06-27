<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Entry;
use Statamic\Support\Str;

class EntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Entry::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'item_id' => Str::random('6'),
            'title' => 'This is an entry',
            'enabled' => true,
            'collection' => 'pages',
            'handle' => 'pages',
            'options' => [],
        ];
    }
}
