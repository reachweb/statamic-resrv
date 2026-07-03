<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationStatus: string
{
    case PENDING = 'pending';

    /** Reserved for future use. No writer currently produces this status. */
    case WEBHOOK = 'webhook';

    case CONFIRMED = 'confirmed';

    /**
     * The gateway returned the charge to the customer. Rows written before CANCELLED
     * existed may also be no-charge voids that would land in CANCELLED today.
     */
    case REFUNDED = 'refunded';

    /**
     * Terminated without money moving back through a gateway: a customer no-refund
     * cancellation, or a no-charge booking (partner / zero-payment) voided from the CP.
     * Terminal — a later goodwill refund must happen directly on the provider's dashboard,
     * because re-entering REFUNDED would rerun IncreaseAvailability and double-restore stock.
     */
    case CANCELLED = 'cancelled';

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
            self::CONFIRMED => [self::REFUNDED, self::CANCELLED],
            self::PARTNER => [self::REFUNDED, self::CANCELLED],
            self::EXPIRED, self::REFUNDED, self::CANCELLED => [],
            self::WEBHOOK, self::COMPLETED => [],
        }, true);
    }

    /**
     * Terminal states cannot leave — no outbound edges in the state machine.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::EXPIRED, self::REFUNDED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Status values for live bookings — confirmed stays a customer can act on, whether paid
     * normally or via a partner (affiliate skip-payment) flow.
     *
     * @return string[]
     */
    public static function live(): array
    {
        return [
            self::CONFIRMED->value,
            self::PARTNER->value,
        ];
    }

    /**
     * Status values that should exclude reservations from active availability calculations.
     *
     * @return string[]
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETED->value,
            self::REFUNDED->value,
            self::CANCELLED->value,
            self::EXPIRED->value,
        ];
    }
}
