@component('mail::message')

{{ __("A new reservation is awaiting payment.") }}

{{ __("The customer booked on the website and chose to pay with :method. Its dates are held until you mark it as paid or cancel it in the Control Panel.", ['method' => $paymentMethod]) }}

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer?->email }}**<br>
{{ __("Amount due") }}: **{{ config('resrv-config.currency_symbol') }} {{ $reservation->amountDue()->format() }}**
@endcomponent

@php($resrvEntry = $reservation->entry())
@component('mail::table')
|{{ __("Reservation details") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
| {{ __("Property") }}   | {{ is_array($resrvEntry) ? ($resrvEntry['title'] ?? '') : $resrvEntry->title }} |
| {{ __("Pick-up date") }}      | {{ $reservation->date_start->format('d-m-Y H:i') }} |
| {{ __("Drop-off date") }}     | {{ $reservation->date_end->format('d-m-Y H:i') }} |
@if ($reservation->quantity > 1)
| {{ __("Quantity") }}  | x {{ $reservation->quantity }} |
@endif
| **{{ __("Total") }}** ({{ __("including taxes") }}) | {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }} |
@endcomponent

@component('mail::button', ['url' => $reservationUrl])
{{ __("View reservation") }}
@endcomponent

@endcomponent
