@component('mail::message')

@if ($context === 'out_of_band_duplicate')
{{ __("A reservation was confirmed as paid out of band (in person / by bank transfer) while its online payment intent had already captured — or was still capturing — money at the gateway. The customer may have paid twice: once out of band and once online.") }}
@elseif ($context === 'out_of_band_still_payable')
{{ __("A reservation was confirmed as paid out of band (in person / by bank transfer), but its open online payment intent could not be cancelled at the gateway and can still collect payment. If the customer completes that payment, the booking will be paid twice — and no further alert will be sent.") }}
@elseif ($context === 'out_of_band_unverified')
{{ __("A reservation was confirmed as paid out of band (in person / by bank transfer), but its online payment intent could not be verified at the gateway. It may have already collected a payment, or may still be able to collect one — if it does, the booking will be paid twice and no further alert will be sent.") }}
@elseif ($context === 'cancelled_captured')
{{ __("A reservation was cancelled while its online payment intent had already captured — or was still capturing — money at the gateway. The customer was told the charge will be refunded.") }}
@elseif ($context === 'cancelled_unverified')
{{ __("A reservation was cancelled, but its recorded payment gateway is no longer configured — the payment intent could not be voided or verified, and the gateway's webhook endpoint is no longer active, so no further alert will be sent if it collects money.") }}
@else
{{ __("An orphaned payment has been detected. A successful payment webhook arrived for a reservation that is no longer live — the charge exists on the gateway but there is no active reservation to attach it to. A manual refund is likely required.") }}
@endif

@component('mail::panel')
{{ __("Reservation") }} **#{{ $reservation->id }}**<br>
{{ __("Reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Status") }}: **{{ $reservation->status }}**<br>
{{ __("Payment intent") }}: **{{ $paymentIntentId }}**<br>
@if ($reservation->payment_gateway)
{{ __("Gateway") }}: **{{ $reservation->payment_gateway }}**<br>
@endif
@if ($stripeEventId)
{{ __("Event id") }}: **{{ $stripeEventId }}**<br>
@endif
@endcomponent

@if ($context === 'out_of_band_duplicate')
{{ __("Recommended action: check this payment intent in your payment provider's dashboard and, if the out-of-band payment was also received, refund one of the two payments.") }}
@elseif ($context === 'out_of_band_still_payable')
{{ __("Recommended action: cancel this payment intent in your payment provider's dashboard so it can no longer be completed. If it has already collected a payment, refund one of the two payments.") }}
@elseif ($context === 'out_of_band_unverified')
{{ __("Recommended action: check this payment intent in your payment provider's dashboard. Cancel it if it is still open; if it has already collected a payment, refund one of the two payments.") }}
@elseif ($context === 'cancelled_captured')
{{ __("Recommended action: refund this payment intent in your payment provider's dashboard.") }}
@elseif ($context === 'cancelled_unverified')
{{ __("Recommended action: check this payment intent in that provider's dashboard. Cancel it if it is still open; refund it if it has collected money.") }}
@else
{{ __("Recommended action: locate this payment intent in your payment provider's dashboard and issue a refund.") }}
@endif

@endcomponent
