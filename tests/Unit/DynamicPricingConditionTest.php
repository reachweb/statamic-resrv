<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Tests\TestCase;

class DynamicPricingConditionTest extends TestCase
{
    /** Invoke the protected DynamicPricing::checkCondition() via reflection. */
    private function checkCondition(object $pricing, ?PriceClass $price): bool
    {
        $method = new \ReflectionMethod(DynamicPricing::class, 'checkCondition');
        $method->setAccessible(true);

        return $method->invoke(new DynamicPricing, $pricing, $price, null, null);
    }

    public function test_reservation_price_condition_does_not_fatal_on_a_null_price()
    {
        $pricing = (object) [
            'condition_type' => 'reservation_price',
            'condition_comparison' => '>',
            'condition_value' => 50,
        ];

        // The signature allows a null price; a price-based condition simply cannot match it.
        $this->assertFalse($this->checkCondition($pricing, null));
    }

    public function test_reservation_price_condition_still_matches_a_qualifying_price()
    {
        $pricing = (object) [
            'condition_type' => 'reservation_price',
            'condition_comparison' => '>',
            'condition_value' => 50,
        ];

        $this->assertTrue($this->checkCondition($pricing, Price::create(100)));
    }
}
