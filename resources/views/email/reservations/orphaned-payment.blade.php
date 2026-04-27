@component('mail::message')

{{ __("An orphaned payment has been detected. A successful payment webhook arrived for a reservation that is no longer live — the charge exists on the gateway but there is no active reservation to attach it to. A manual refund is likely required.") }}

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

{{ __("Recommended action: locate this payment intent in your payment provider's dashboard and issue a refund.") }}

@endcomponent
