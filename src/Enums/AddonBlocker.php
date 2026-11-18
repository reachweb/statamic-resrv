<?php

namespace Reach\StatamicResrv\Enums;

/**
 * Why AddonQuote::price() can't book a selected extra or option. Each flow words its own message:
 * Reservation::validateTotal() the checkout's, ManualReservationCreator the CP's.
 */
enum AddonBlocker: string
{
    /** Not one of the entry's selectable extras: deleted, unpublished, detached or in an unpublished category. */
    case ExtraUnavailable = 'extra_unavailable';
    case ExtraQuantityBelowOne = 'extra_quantity_below_one';
    case ExtraNotMultiple = 'extra_not_multiple';
    case ExtraOverMaximum = 'extra_over_maximum';
    /** A custom-priced extra whose customer field is missing or not a number. */
    case ExtraCustomPriceMissing = 'extra_custom_price_missing';
    /** Not one of the entry's published options. */
    case OptionUnavailable = 'option_unavailable';
    /** Not a published value of the option. */
    case OptionValueUnavailable = 'option_value_unavailable';

    /** The selection the blocker belongs to: extras or options. */
    public function field(): string
    {
        return match ($this) {
            self::OptionUnavailable, self::OptionValueUnavailable => 'options',
            default => 'extras',
        };
    }
}
