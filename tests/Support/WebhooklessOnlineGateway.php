<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;

/**
 * Gateway double for a contract-violating provider gateway: online (no manual
 * confirmation, inherited from the fake) but with no webhook support either — nothing
 * could ever confirm a reservation it collects money for.
 */
class WebhooklessOnlineGateway extends FakePaymentGateway
{
    public function name(): string
    {
        return 'webhookless';
    }

    public function label(): string
    {
        return 'Webhookless Online';
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }
}
