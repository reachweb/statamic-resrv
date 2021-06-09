@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
Tel: {{ config('resrv-config.phone') }}<br>
Email: {{ config('resrv-config.mail') }}

{{ __('statamic-resrv::email.refunded') }}

@component('mail::panel')
{{ __('statamic-resrv::email.reservationCode') }} **{{ $reservation->id }}**<br>
{{ __('statamic-resrv::email.date') }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __('statamic-resrv::email.bookingReference') }}: **{{ $reservation->reference }}**<br>
{{ __('statamic-resrv::email.email') }}: **{{ $reservation->customer->get('email') }}** 
@endcomponent

@component('mail::table')
|{{ __('statamic-resrv::email.paymentRefunded') }}||
| :----------------------------- |:----------------| 
@if (config('resrv-config.payment') != 'full')
| {{ __('statamic-resrv::email.refundToCard') }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }} |
@else
| {{ __('statamic-resrv::email.refundToCard') }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->price->format() }} |
@endif
@endcomponent

{{ __('statamic-resrv::email.thankYou') }},<br>
{{ config('app.name') }}
@endcomponent
