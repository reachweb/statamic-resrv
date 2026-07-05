@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

{{ __("Your reservation has been cancelled.") }}

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Date") }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer?->email }}**
@endcomponent

@if ($reservation->hasGatewayPayment())
**{{ __("No refund has been issued for this cancellation. The payment for this reservation is non-refundable.") }}**
@else
{{ __("No payment was collected for this reservation, so there is nothing to refund.") }}
@endif

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
