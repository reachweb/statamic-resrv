<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;

/**
 * Gateway double that constructs but whose label() throws. Registered without a config label,
 * it fails in PaymentGatewayManager::resolve() after construction.
 */
class ThrowingLabelGateway extends FakePaymentGateway
{
    public function label(): string
    {
        throw new \RuntimeException('Gateway label could not be resolved.');
    }
}
