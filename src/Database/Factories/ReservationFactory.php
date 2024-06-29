<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Reservation;

class ReservationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Reservation::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'status' => 'pending',
            'type' => 'normal',
            'reference' => 'ABCDEF',
            'item_id' => '',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(2, 'day')->toIso8601String(),
            'quantity' => 1,
            'price' => 200,
            'payment' => 50,
            'payment_id' => '',
            'customer' => '',
        ];
    }

    public function expired(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'expired',
            ];
        });
    }

    public function advanced(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'property' => 'something',
            ];
        });
    }
}
