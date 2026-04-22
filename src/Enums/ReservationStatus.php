<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationStatus: string
{
    case PENDING = 'pending';

    /** Reserved for future use. No writer currently produces this status. */
    case WEBHOOK = 'webhook';

    case CONFIRMED = 'confirmed';

    case REFUNDED = 'refunded';

    /** Reserved for future use. No writer currently produces this status. */
    case COMPLETED = 'completed';

    case EXPIRED = 'expired';

    case PARTNER = 'partner';

    /**
     * Whether a reservation in this state may transition to the target state.
     * Same-state "transitions" are idempotent (handled by Reservation::transitionTo()).
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::PENDING => [self::CONFIRMED, self::EXPIRED, self::REFUNDED, self::PARTNER],
            self::CONFIRMED => [self::REFUNDED],
            self::PARTNER => [self::REFUNDED],
            self::EXPIRED, self::REFUNDED => [],
            self::WEBHOOK, self::COMPLETED => [],
        }, true);
    }

    /**
     * Terminal states cannot leave — no outbound edges in the state machine.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::EXPIRED, self::REFUNDED => true,
            default => false,
        };
    }
}
