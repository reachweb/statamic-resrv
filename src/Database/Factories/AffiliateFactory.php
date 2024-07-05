<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Affiliate;

class AffiliateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Affiliate::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => 'Larry David',
            'code' => 'AFFILIATE',
            'email' => $this->faker->unique()->safeEmail,
            'cookie_duration' => 2,
            'fee' => 20,
            'published' => true,
            'allow_skipping_payment' => true,
            'send_reservation_email' => true,
        ];
    }
}
