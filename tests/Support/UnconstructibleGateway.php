<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;

/**
 * Gateway double whose constructor fails the way resrv-payment-paypal 1.0.1 did without
 * credentials: a TypeError from its SDK client, not an Exception.
 */
class UnconstructibleGateway extends FakePaymentGateway
{
    public function __construct()
    {
        throw new \TypeError('ClientCredentialsAuthCredentialsBuilder::init(): Argument #1 ($oAuthClientId) must be of type string, null given');
    }
}
