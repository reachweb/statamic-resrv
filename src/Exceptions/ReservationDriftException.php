<?php

namespace Reach\StatamicResrv\Exceptions;

/**
 * Thrown when reservation validation fails because availability, pricing, or quantity have
 * drifted between the time the reservation was created and the current checkout step.
 * Recoverable — the checkout UI should show an inline banner and let the user retry.
 */
class ReservationDriftException extends ReservationException {}
