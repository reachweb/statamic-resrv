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

    /**
     * Unpaid admin-created hold, created directly by the CP manual flow — never transitioned
     * into. Exempt from minutes_to_hold expiry, abandoned emails and housekeeping (all keyed
     * off PENDING/EXPIRED); leaves only to CONFIRMED or CANCELLED.
     */
    case AWAITING_PAYMENT = 'awaiting_payment';

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
            self::AWAITING_PAYMENT => [self::CONFIRMED, self::CANCELLED],
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

    /**
     * Status values for checkouts still in flight — holds that may release asynchronously
     * (expiry restores +quantity within minutes), which would corrupt an absolute CP inventory
     * edit. Confirmed/partner bookings keep their hold key for life but only release on an
     * explicit refund, so they are not in flight. AWAITING_PAYMENT is deliberately absent: a
     * manual hold has no deadline by default and releases like a confirmed booking's refund
     * (an admin cancel or a lapse adds +quantity on top of the admin's value), so blocking on
     * it would freeze inventory edits for as long as the customer takes to pay.
     *
     * @return string[]
     */
    public static function inFlight(): array
    {
        return [
            self::PENDING->value,
            self::WEBHOOK->value,
        ];
    }

    /** Human, translated label for the CP (badges, filters, errors). */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::WEBHOOK => __('Webhook'),
            self::CONFIRMED => __('Confirmed'),
            self::REFUNDED => __('Refunded'),
            self::CANCELLED => __('Cancelled'),
            self::COMPLETED => __('Completed'),
            self::AWAITING_PAYMENT => __('Awaiting payment'),
            self::EXPIRED => __('Expired'),
            self::PARTNER => __('Partner'),
        };
    }

    /** label() for a raw status value; unknown or legacy values are humanized rather than thrown on. */
    public static function labelFor(?string $status): string
    {
        return self::tryFrom((string) $status)?->label()
            ?? ucfirst(str_replace('_', ' ', (string) $status));
    }
}
