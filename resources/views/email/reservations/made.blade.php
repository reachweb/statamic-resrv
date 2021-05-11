@component('mail::message')

{{ __('statamic-resrv::email.newReservation') }}

@component('mail::panel')
{{ __('statamic-resrv::email.reservationCode') }} **{{ $reservation->id }}**<br>
{{ __('statamic-resrv::email.date') }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __('statamic-resrv::email.bookingReference') }}: **{{ $reservation->reference }}**<br>
{{ __('statamic-resrv::email.email') }}: **{{ $reservation->customer->get('email') }}** 
@endcomponent

@component('mail::table')
|{{ __('statamic-resrv::email.reservationDetails') }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------| 
| {{ __('statamic-resrv::email.pickUpDate') }}      | {{ $reservation->date_start->format('d-m-Y H:i') }} |
| {{ __('statamic-resrv::email.dropOffDate') }}     | {{ $reservation->date_end->format('d-m-Y H:i') }} |
@if (config('resrv-config.enable_locations'))
| {{ __('statamic-resrv::email.pickUpLocation') }}  | {{ $reservation->location_start_data->name }} |
| {{ __('statamic-resrv::email.dropOffLocation') }} | {{ $reservation->location_end_data->name }} |
@endif
| {{ __('statamic-resrv::email.itemLabel') }}   | {{ $reservation->entry()->title }} {{ __('statamic-resrv::email.orSimilar') }} |
@endcomponent

@component('mail::table')
|{{ __('statamic-resrv::email.paymentInformation') }}||
| :----------------------------- |:----------------| 
@if (config('resrv-config.payment') != 'full')
| {{ __('statamic-resrv::email.alreadyPaid') }}    | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment }} |
| {{ __('statamic-resrv::email.amountToBePaid') }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->amountRemaining() }} |
@endif
| **{{ __('statamic-resrv::email.total') }}** ({{ __('statamic-resrv::email.includingTaxes') }}) | {{ config('resrv-config.currency_symbol') }} {{ $reservation->price }} |

@endcomponent

@endcomponent
