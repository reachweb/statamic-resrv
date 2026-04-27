<?php

namespace Reach\StatamicResrv\Exceptions;

/**
 * Thrown when the checkout flow encounters a reservation that is past its minutes_to_hold
 * window or already marked EXPIRED. Terminal — the checkout UI should show the full-page
 * reservation-error view, not an inline banner.
 */
class ReservationExpiredException extends ReservationException {}
