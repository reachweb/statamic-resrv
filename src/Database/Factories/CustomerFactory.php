<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Models\Customer;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->safeEmail(),
            'data' => new Collection([
                'first_name' => $this->faker->firstName(),
                'last_name' => $this->faker->lastName(),
                'phone' => $this->faker->phoneNumber(),
                'address' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'postal_code' => $this->faker->postcode(),
                'country' => $this->faker->country(),
            ]),
        ];
    }

    public function withGuests(int $adults = 2, int $children = 0): Factory
    {
        return $this->state(function (array $attributes) use ($adults, $children) {
            return [
                'data' => collect(isset($attributes['data']) ? $attributes['data'] : [])
                    ->merge([
                        'adults' => $adults,
                        'childs' => $children,
                    ]),
            ];
        });
    }
}
