<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;
use Stripe\Collection;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\StripeClient;

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

    public function test_refund_reconciles_an_already_refunded_charge_to_the_existing_refund()
    {
        $refunds = $this->fakeRefundsService();
        $refunds->createOutcomes = [$this->alreadyRefundedError()];
        $refunds->allResult = $this->refundList('re_existing');

        $refund = $this->gatewayUsing($refunds)->refund($this->reservationWithPayment());

        $this->assertSame('re_existing', $refund->id);
        $this->assertSame(['payment_intent' => 'pi_123', 'limit' => 1], $refunds->allParams[0]);
    }

    public function test_refund_retries_a_replayed_error_with_a_fresh_key_and_reconciles_a_hidden_refund()
    {
        // A replayed 500 is indeterminate — the original attempt may have refunded despite the
        // cached error. The fresh-key retry then hits "already refunded", which must resolve to
        // the existing refund (success) instead of rolling back the caller's status transition.
        $refunds = $this->fakeRefundsService();
        $refunds->createOutcomes = [$this->replayedServerError(), $this->alreadyRefundedError()];
        $refunds->allResult = $this->refundList('re_hidden');

        $refund = $this->gatewayUsing($refunds)->refund($this->reservationWithPayment());

        $this->assertSame('re_hidden', $refund->id);
        $this->assertSame('resrv-refund-42-pi_123', $refunds->createOptions[0]['idempotency_key']);
        $this->assertNotSame(
            $refunds->createOptions[0]['idempotency_key'],
            $refunds->createOptions[1]['idempotency_key']
        );
    }

    public function test_refund_throws_refund_failed_when_the_gateway_rejects_and_no_refund_exists()
    {
        $refunds = $this->fakeRefundsService();
        $refunds->createOutcomes = [
            InvalidRequestException::factory(
                'No such payment_intent: pi_123',
                404,
                null,
                ['error' => ['code' => 'resource_missing', 'message' => 'No such payment_intent: pi_123']],
                null,
                'resource_missing'
            ),
        ];

        $this->expectException(RefundFailedException::class);

        $this->gatewayUsing($refunds)->refund($this->reservationWithPayment());
    }

    public function test_refund_still_fails_when_already_refunded_but_the_lookup_finds_no_refund()
    {
        $refunds = $this->fakeRefundsService();
        $refunds->createOutcomes = [$this->alreadyRefundedError()];
        $refunds->allResult = Collection::constructFrom(['object' => 'list', 'data' => []]);

        $this->expectException(RefundFailedException::class);

        $this->gatewayUsing($refunds)->refund($this->reservationWithPayment());
    }

    public function test_retrieve_payment_intent_returns_null_only_for_a_missing_intent()
    {
        $intents = $this->fakePaymentIntentsService();
        $intents->retrieveOutcomes = [
            InvalidRequestException::factory(
                'No such payment_intent: pi_123',
                404,
                null,
                ['error' => ['code' => 'resource_missing', 'message' => 'No such payment_intent']],
                null,
                'resource_missing'
            ),
        ];

        $result = $this->gatewayUsingPaymentIntents($intents)
            ->retrievePaymentIntent('pi_123', $this->reservationWithPayment());

        $this->assertNull($result);
    }

    public function test_retrieve_payment_intent_propagates_a_transient_failure_instead_of_replacing_the_intent()
    {
        $intents = $this->fakePaymentIntentsService();
        $intents->retrieveOutcomes = [
            ApiConnectionException::factory('Could not connect to Stripe.'),
        ];

        // A transient read failure must NOT resolve to null (which resolveOrCreateIntent treats
        // as permission to mint a replacement intent) — it must surface so the still-payable
        // original is never orphaned behind a second chargeable intent.
        $this->expectException(ApiConnectionException::class);

        $this->gatewayUsingPaymentIntents($intents)
            ->retrievePaymentIntent('pi_123', $this->reservationWithPayment());
    }

    private function reservationWithPayment(): Reservation
    {
        $reservation = new Reservation;
        $reservation->id = 42;
        $reservation->payment_id = 'pi_123';

        return $reservation;
    }

    private function fakePaymentIntentsService(): object
    {
        return new class
        {
            public array $retrieveOutcomes = [];

            public function retrieve($id, $params = null, $opts = null)
            {
                $outcome = array_shift($this->retrieveOutcomes);

                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }
        };
    }

    private function gatewayUsingPaymentIntents(object $paymentIntentsService): StripePaymentGateway
    {
        $client = new class('sk_test_resrv', $paymentIntentsService) extends StripeClient
        {
            public function __construct(string $key, private object $paymentIntentsService)
            {
                parent::__construct($key);
            }

            public function getService($name)
            {
                return $name === 'paymentIntents' ? $this->paymentIntentsService : parent::getService($name);
            }
        };

        return new class($client) extends StripePaymentGateway
        {
            public function __construct(private StripeClient $client) {}

            protected function getClient($reservation): StripeClient
            {
                return $this->client;
            }
        };
    }

    private function refundList(string $refundId): Collection
    {
        return Collection::constructFrom([
            'object' => 'list',
            'data' => [['id' => $refundId, 'object' => 'refund']],
        ]);
    }

    private function alreadyRefundedError(): ApiErrorException
    {
        return InvalidRequestException::factory(
            'Charge ch_123 has already been refunded.',
            400,
            null,
            ['error' => ['code' => 'charge_already_refunded', 'message' => 'Charge ch_123 has already been refunded.']],
            null,
            'charge_already_refunded'
        );
    }

    private function replayedServerError(): ApiErrorException
    {
        return UnknownApiErrorException::factory(
            'Something went wrong.',
            500,
            null,
            null,
            ['Idempotent-Replayed' => 'true']
        );
    }

    private function fakeRefundsService(): object
    {
        return new class
        {
            public array $createOutcomes = [];

            public array $createOptions = [];

            public $allResult = null;

            public array $allParams = [];

            public function create(array $params, array $opts = [])
            {
                $this->createOptions[] = $opts;
                $outcome = array_shift($this->createOutcomes);

                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }

            public function all(array $params)
            {
                $this->allParams[] = $params;

                return $this->allResult;
            }
        };
    }

    private function gatewayUsing(object $refundsService): StripePaymentGateway
    {
        $client = new class('sk_test_resrv', $refundsService) extends StripeClient
        {
            public function __construct(string $key, private object $refundsService)
            {
                parent::__construct($key);
            }

            public function getService($name)
            {
                return $name === 'refunds' ? $this->refundsService : parent::getService($name);
            }
        };

        return new class($client) extends StripePaymentGateway
        {
            public function __construct(private StripeClient $client) {}

            protected function getClient($reservation): StripeClient
            {
                return $this->client;
            }
        };
    }
}
