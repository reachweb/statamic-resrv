<?php

namespace Reach\StatamicResrv\Exceptions;

use Carbon\CarbonInterface;
use Exception;

/**
 * Thrown when a superseded payment attempt is still open at the provider, could not be voided
 * or resumed, and the gateway reported when it dies by itself (`->expires_at`, see
 * UPGRADE-PAYMENT-GATEWAYS.md Step 13). No replacement was minted; the pay page asks the
 * customer to try again after that time instead of showing an error.
 */
class PaymentAttemptStillOpen extends Exception
{
    public function __construct(public readonly int $reservationId, public readonly CarbonInterface $expiresAt)
    {
        parent::__construct(sprintf(
            'Reservation %d has a payment attempt that stays open at the provider until %s.',
            $reservationId,
            $expiresAt->toIso8601String(),
        ));
    }
}
