<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationPriceCastTest extends TestCase
{
    public function test_money_attributes_return_price_objects_on_direct_access()
    {
        $reservation = Reservation::factory()->create([
            'price' => 200,
            'payment' => 50,
            'payment_surcharge' => 10,
            'total' => 200,
        ]);

        foreach (['price', 'payment', 'payment_surcharge', 'total'] as $attribute) {
            $this->assertInstanceOf(PriceClass::class, $reservation->{$attribute});
        }

        $this->assertSame('200.00', $reservation->price->format());
        $this->assertSame('50.00', $reservation->payment->format());
    }

    public function test_money_attributes_serialize_to_formatted_strings()
    {
        $reservation = Reservation::factory()->create([
            'price' => 200,
            'payment' => 50,
            'payment_surcharge' => 10,
            'total' => 200,
        ]);

        $array = $reservation->toArray();

        foreach (['price', 'payment', 'payment_surcharge', 'total'] as $attribute) {
            $this->assertIsString($array[$attribute]);
        }

        $this->assertSame('200.00', $array['price']);
        $this->assertSame('50.00', $array['payment']);
    }

    public function test_refunded_amount_reports_the_recorded_charge_with_surcharge()
    {
        $reservation = Reservation::factory()->create([
            'price' => 200,
            'payment' => 50,
            'payment_surcharge' => 4,
            'total' => 200,
            'payment_id' => 'pi_123',
        ]);

        // Checkout charges payment + surcharge in one intent and the gateway refunds the
        // intent in full — independent of the payment mode configured at read time, which
        // may have changed since the booking was made.
        Config::set('resrv-config.payment', 'percent');
        $this->assertSame('54.00', $reservation->refundedAmount()->format());

        Config::set('resrv-config.payment', 'full');
        $this->assertSame('54.00', $reservation->refundedAmount()->format());
    }

    public function test_refunded_amount_is_zero_when_no_payment_reached_a_gateway()
    {
        $reservation = Reservation::factory()->create([
            'price' => 200,
            'payment' => 100,
            'payment_id' => '',
            'total' => 200,
        ]);

        $this->assertSame('0.00', $reservation->refundedAmount()->format());
    }
}
