<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Tests\TestCase;

class CalculatePaymentTest extends TestCase
{
    /** Invoke the protected HandlesPricing::calculatePayment() via reflection. */
    private function calculatePayment(PriceClass $price): PriceClass
    {
        $method = new \ReflectionMethod(Availability::class, 'calculatePayment');
        $method->setAccessible(true);

        return $method->invoke(new Availability, $price);
    }

    public function test_percent_payment_returns_the_configured_percentage_of_the_price()
    {
        config(['resrv-config.payment' => 'percent']);
        config(['resrv-config.percent_amount' => 30]);

        $payment = $this->calculatePayment(Price::create(100));

        $this->assertSame('30.00', $payment->format());
    }

    public function test_percent_payment_does_not_mutate_the_passed_in_price()
    {
        config(['resrv-config.payment' => 'percent']);
        config(['resrv-config.percent_amount' => 30]);

        $price = Price::create(100);
        $this->calculatePayment($price);

        // percent() must not mutate the input; previously it returned the same object, silently overwriting the reservation price.
        $this->assertSame('100.00', $price->format());
    }

    public function test_fixed_payment_returns_the_configured_fixed_amount_without_mutating_the_price()
    {
        config(['resrv-config.payment' => 'fixed']);
        config(['resrv-config.fixed_amount' => 40]);

        $price = Price::create(100);
        $payment = $this->calculatePayment($price);

        $this->assertSame('40.00', $payment->format());
        $this->assertSame('100.00', $price->format());
    }

    public function test_full_payment_returns_the_full_price()
    {
        config(['resrv-config.payment' => 'full']);

        $price = Price::create(100);
        $payment = $this->calculatePayment($price);

        $this->assertSame('100.00', $payment->format());
        $this->assertSame('100.00', $price->format());
    }
}
