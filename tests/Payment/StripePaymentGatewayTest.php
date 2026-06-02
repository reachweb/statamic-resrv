<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Tests\TestCase;

class StripePaymentGatewayTest extends TestCase
{
    private function filterCustomerData(array $data): array
    {
        $method = new \ReflectionMethod(StripePaymentGateway::class, 'filterCustomerData');

        return $method->invoke(new StripePaymentGateway, $data);
    }

    public function test_filter_customer_data_clamps_values_keys_and_non_strings_to_stripe_limits()
    {
        // L8: free-text form values can exceed Stripe's metadata limits and break PaymentIntent::create.
        $result = $this->filterCustomerData([
            'name' => 'John',
            'bio' => str_repeat('a', 600),
            str_repeat('k', 41) => 'key over 40 chars is dropped',
            'age' => 30, // non-string is dropped
        ]);

        $this->assertSame('John', $result['name']);
        $this->assertSame(500, mb_strlen($result['bio']));
        $this->assertArrayNotHasKey(str_repeat('k', 41), $result);
        $this->assertArrayNotHasKey('age', $result);
    }

    public function test_filter_customer_data_caps_key_count_below_stripe_limit()
    {
        $input = [];
        for ($i = 0; $i < 60; $i++) {
            $input['field_'.$i] = 'value';
        }

        // ≤ 49 leaves room for the reservation_id the caller merges in (Stripe allows 50 keys total).
        $this->assertLessThanOrEqual(49, count($this->filterCustomerData($input)));
    }
}
