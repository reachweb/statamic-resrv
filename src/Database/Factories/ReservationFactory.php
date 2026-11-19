<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Customer;
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
            'payment_settlement' => fn (array $attributes) => $this->settlementFor($attributes),
        ];
    }

    /**
     * How a booking in this state would have been settled by the app, following the backfill of
     * the payment_settlement migration: confirmed, refunded and cancelled bookings with a
     * payment_id were paid through it, and a confirmed one owing money without it (or a manual one
     * refunded) out of band. Pass payment_settlement for anything else.
     */
    protected function settlementFor(array $attributes): string
    {
        $status = $attributes['status'] ?? 'pending';
        $owes = fn (string $column) => (float) (string) ($attributes[$column] ?? 0) !== 0.0;

        return match (true) {
            in_array($status, ['confirmed', 'refunded', 'cancelled'], true) && filled($attributes['payment_id'] ?? null) => 'gateway',
            $status === 'confirmed' && ($owes('payment') || $owes('payment_surcharge')) => 'out_of_band',
            $status === 'refunded' && filled($attributes['created_by'] ?? null) => 'out_of_band',
            default => 'none',
        };
    }

    /**
     * Configure the model factory to use a real Customer model.
     */
    public function withCustomer(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'customer_id' => Customer::factory(),
            ];
        });
    }

    public function expired(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'expired',
            ];
        });
    }

    public function withRate(int $rateId): Factory
    {
        return $this->state(function (array $attributes) use ($rateId) {
            return [
                'rate_id' => $rateId,
            ];
        });
    }
}
