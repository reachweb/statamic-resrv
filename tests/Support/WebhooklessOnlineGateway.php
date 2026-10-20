<?php

namespace Reach\StatamicResrv\Tests\Support;

use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;

/**
 * Online gateway double with no webhook support — nothing could ever confirm a
 * reservation it collects money for.
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
