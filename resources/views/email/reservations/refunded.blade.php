@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

@php($refundedAutomatically = $refundedAutomatically ?? $reservation->refundIsAutomatic())
@if ($refundedAutomatically)
{{ __("Your reservation has been refunded.") }}
@else
{{ __("Your reservation has been cancelled.") }}
@endif

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Date") }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer?->email }}**
@endcomponent

@if ($refundedAutomatically)
@component('mail::table')
|{{ __("Refund information") }}||
| :----------------------------- |:----------------|
| {{ __("Refunded to your card") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->refundedAmount()->format() }} |
@endcomponent
@elseif (($returnedSeparately ?? null) === 'hedged')
{{ __("If you have already paid for this reservation, your payment of :amount will be returned to you separately.", ['amount' => config('resrv-config.currency_symbol').' '.$reservation->amountDue()->format()]) }}
@elseif (($returnedSeparately ?? null) === 'promised')
{{ __("Your payment of :amount will be returned to you separately.", ['amount' => config('resrv-config.currency_symbol').' '.$reservation->amountDue()->format()]) }}
@endif

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
