<?php

namespace Tests\Unit;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Tests\TestCase;

class PriceTest extends TestCase
{
    public function test_price_creation()
    {
        $price = Price::create(22.76);
        $this->assertInstanceOf(PriceClass::class, $price);
        $this->assertTrue(true);
    }

    public function test_price_format()
    {
        $price = Price::create(22.76);
        $format = $price->format();
        $this->assertSame($format, '22.76');
        $this->assertTrue(true);
    }

    public function test_price_addition()
    {
        $price1 = Price::create(22.76);
        $price2 = Price::create(202.22);
        $price3 = Price::create(57.9);
        $result = $price1->add($price2, $price3);
        $this->assertEquals($result, Price::create(282.88));
        $this->assertEquals($result->format(), '282.88');
    }

    public function test_price_subtraction()
    {
        $price1 = Price::create(202.22);
        $price2 = Price::create(57.9);
        $result = $price1->subtract($price2);
        $this->assertEquals($result, Price::create(144.32));
        $this->assertEquals($result->format(), '144.32');
    }

    public function test_price_multiple()
    {
        $price1 = Price::create(202.22);
        $result = $price1->multiply(4);
        $this->assertEquals($result, Price::create(808.88));

        $price2 = Price::create(202.22);
        $result = $price2->multiply(2)->multiply(2);
        $this->assertEquals($result, Price::create(808.88));

        $price3 = Price::create(202.22);
        $result = $price3->multiply(7.66);
        $this->assertEquals($result, Price::create(1549.01));
    }

    public function test_price_divide()
    {
        $price1 = Price::create(37.23);
        $result = $price1->divide(3);
        $this->assertEquals($result, Price::create(12.41));

        $price2 = Price::create(37.23);
        $result = $price2->divide(0.83);
        $this->assertEquals($result, Price::create(44.86));
    }

    public function test_price_percent()
    {
        $price1 = Price::create(100);
        $result = $price1->percent(30);
        $this->assertEquals($result, Price::create(30));
        $this->assertTrue(true);
    }

    public function test_price_equals()
    {
        $price1 = Price::create(44.13);
        $price2 = Price::create(44.13);
        $this->assertTrue($price2->equals($price1));

        $price1 = Price::create(212.41);
        $price2 = Price::create(44.13);
        $this->assertFalse($price2->equals($price1));
    }

    public function test_price_greater_than()
    {
        $price1 = Price::create(82.73);
        $price2 = Price::create(44.13);
        $this->assertTrue($price1->greaterThan($price2));
        $this->assertFalse($price2->greaterThan($price1));
    }

    public function test_price_less_than()
    {
        $price1 = Price::create(82.73);
        $price2 = Price::create(44.13);
        $this->assertFalse($price1->lessThan($price2));
        $this->assertTrue($price2->lessThan($price1));
    }

    public function test_price_round_trips_for_zero_decimal_currency()
    {
        config(['resrv-config.currency_isoCode' => 'JPY']);

        $price = Price::create(1000);

        // JPY has no minor unit; old *100 logic produced 100000.
        $this->assertSame('1000', $price->format());
        $this->assertSame('1000', $price->raw());
    }

    public function test_price_round_trips_for_three_decimal_currency()
    {
        config(['resrv-config.currency_isoCode' => 'BHD']);

        $price = Price::create(100);

        // BHD has 3 decimal places; old *100 logic gave 10.000 instead of 100.000.
        $this->assertSame('100.000', $price->format());
        $this->assertSame('100000', $price->raw());
    }

    public function test_price_arithmetic_for_zero_decimal_currency()
    {
        config(['resrv-config.currency_isoCode' => 'JPY']);

        $result = Price::create(1000)->add(Price::create(500));
        $this->assertSame('1500', $result->format());

        $discounted = Price::create(1000)->percent(30);
        $this->assertSame('300', $discounted->format());
    }

    public function test_price_create_handles_null_as_zero()
    {
        config(['resrv-config.currency_isoCode' => 'EUR']);

        // Price::create(null) (nullable column before checkout) must return zero without warnings on PHP 8.5+.
        $price = Price::create(null);

        $this->assertInstanceOf(PriceClass::class, $price);
        $this->assertSame('0.00', $price->format());
        $this->assertSame('0', $price->raw());
    }

    public function test_price_round_trips_for_two_decimal_currency()
    {
        config(['resrv-config.currency_isoCode' => 'USD']);

        $price = Price::create(22.76);

        $this->assertSame('22.76', $price->format());
        $this->assertSame('2276', $price->raw());
    }

    public function test_price_create_rounds_sub_cent_input_half_up()
    {
        config(['resrv-config.currency_isoCode' => 'EUR']);

        // Sub-cent precision must round half-up to the currency subunit, not truncate.
        // (The old bcmul(*, 100) logic floored these: 77.359 -> 7735, 77.355 -> 7735.)
        $this->assertSame('7736', Price::create('77.359')->raw());
        $this->assertSame('7736', Price::create('77.355')->raw());
        $this->assertSame('7735', Price::create('77.354')->raw());
    }
}
