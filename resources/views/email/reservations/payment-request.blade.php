@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

{{ __("We have created a reservation for you. Please complete the payment to confirm it.") }}

@component('mail::panel')
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Amount to pay") }}: **{{ config('resrv-config.currency_symbol') }} {{ $amountDue }}**
@if ($reservation->hold_expires_at)
<br>{{ __("Please pay by") }}: **{{ $reservation->hold_expires_at->format('d-m-Y H:i') }}**
@endif
@endcomponent

@php($resrvEntry = $reservation->entry())
@component('mail::table')
|{{ __("Reservation details") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
| {{ __("Pick-up date") }}      | {{ $reservation->date_start->format('d-m-Y H:i') }} |
| {{ __("Drop-off date") }}     | {{ $reservation->date_end->format('d-m-Y H:i') }} |
| {{ __("Property") }}   | {{ is_array($resrvEntry) ? ($resrvEntry['title'] ?? '') : $resrvEntry->title }} |
@if (config('resrv-config.maximum_quantity') > 1)
| {{ __("Quantity") }}  | x {{ $reservation->quantity }} |
@endif
@if ($reservation->rate_id)
| {{ __("Rate") }} | {{ $reservation->getRateLabel() }} |
@endif
| **{{ __("Total") }}** ({{ __("including taxes") }}) | {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }} |
@endcomponent

@if (! $isOffline && $payUrl)
@component('mail::button', ['url' => $payUrl])
{{ __("Pay now") }}
@endcomponent

{{ __("If the button does not work, copy this link into your browser:") }} {{ $payUrl }}
@else
{{ __("We will confirm your reservation as soon as your payment arrives. Please use the booking reference above when sending your payment.") }}
@endif

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
