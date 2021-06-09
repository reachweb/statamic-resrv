<?php

namespace Tests\Unit;

use Reach\StatamicResrv\Tests\TestCase;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

class PriceTest extends TestCase
{
    public function test_price_creation()
    {
        $price = Price::create(22.76);
        $this->assertInstanceOf(PriceClass::class, $price);
    }

    public function test_price_format()
    {
        $price = Price::create(22.76);
        $format = $price->get();
        $this->assertSame($format, '22.76');
    }

    public function test_price_addition()
    {
        $price1 = Price::create(22.76);
        $price2 = Price::create(202.22);
        $price3 = Price::create(57.9);
        $result = $price1->add($price2, $price3);
        $this->assertEquals($result, Price::create(282.88));
        $this->assertEquals($result->get(), '282.88');
    }

    public function test_price_subtraction()
    {
        $price1 = Price::create(202.22);
        $price2 = Price::create(57.9);
        $result = $price1->subtract($price2);
        $this->assertEquals($result, Price::create(144.32));
        $this->assertEquals($result->get(), '144.32');
    }

    public function test_price_multiple()
    {
        $price1 = Price::create(202.22);
        $result = $price1->multiply(4);
        $this->assertEquals($result, Price::create(808.88));

        $price2 = Price::create(202.22);
        $result = $price2->multiply(7.66);
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

    public function test_price_equals()
    {
        $price1 = Price::create(44.13);
        $price2 = Price::create(44.13);
        $this->assertTrue($price2->equals($price1));

        $price1 = Price::create(212.41);
        $price2 = Price::create(44.13);
        $this->assertFalse($price2->equals($price1));        
    }

    public function test_price_greaterThan()
    {
        $price1 = Price::create(82.73);
        $price2 = Price::create(44.13);
        $this->assertTrue($price1->greaterThan($price2));
        $this->assertFalse($price2->greaterThan($price1)); 
    }
    
    public function test_price_lessThan()
    {
        $price1 = Price::create(82.73);
        $price2 = Price::create(44.13);
        $this->assertFalse($price1->lessThan($price2));
        $this->assertTrue($price2->lessThan($price1)); 
    }
}