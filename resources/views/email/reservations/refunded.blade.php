@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

{{ __("Your reservation has been refunded.") }}

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Date") }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer->email }}** 
@endcomponent

@component('mail::table')
|{{ __("Refund information") }}||
| :----------------------------- |:----------------| 
@if (config('resrv-config.payment') != 'full')
| {{ __("Refunded to your card") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }} |
@else
| {{ __("Refunded to your card") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->price->format() }} |
@endif
@endcomponent

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
