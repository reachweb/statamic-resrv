@component('mail::message')

@if ($context === 'out_of_band_duplicate')
{{ __("A reservation was confirmed as paid out of band (in person / by bank transfer) while its online payment intent had already captured — or was still capturing — money at the gateway. The customer may have paid twice: once out of band and once online.") }}
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
@else
{{ __("Recommended action: locate this payment intent in your payment provider's dashboard and issue a refund.") }}
@endif

@endcomponent
