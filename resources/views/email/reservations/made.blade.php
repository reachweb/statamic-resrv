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
| {{ __('statamic-resrv::email.itemLabel') }}   | {{ $reservation->entry()->title }} |
@if (config('resrv-config.maximum_quantity') > 1)
| {{ __('statamic-resrv::email.quantity') }}  | x {{ $reservation->quantity }} |
@endif
@if (config('resrv-config.enable_advanced_availability'))
| {{ __('statamic-resrv::email.property') }} | {{ $reservation->property }} |
@endif
@endcomponent

@if ($reservation->extras()->get()->count() > 0)
@component('mail::table')
|{{ __('statamic-resrv::email.extras') }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------| 
@foreach ($reservation->extras()->get() as $extra)
| {{ $extra->name }} | x{{ $extra->pivot->quantity }} |
@endforeach
@endcomponent
@endif

@if ($reservation->options()->get()->count() > 0)
@component('mail::table')
|{{ __('statamic-resrv::email.options') }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------| 
@foreach ($reservation->options()->get() as $option)
| {{ $option->name }} | {{ $option->values->find($option->pivot->value)->name }} |
@endforeach
@endcomponent
@endif

@component('mail::table')
|{{ __('statamic-resrv::email.paymentInformation') }}||
| :----------------------------- |:----------------| 
@if (config('resrv-config.payment') != 'full')
| {{ __('statamic-resrv::email.alreadyPaid') }}    | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }} |
| {{ __('statamic-resrv::email.amountToBePaid') }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->amountRemaining() }} |
@endif
| **{{ __('statamic-resrv::email.total') }}** ({{ __('statamic-resrv::email.includingTaxes') }}) | {{ config('resrv-config.currency_symbol') }} {{ $reservation->price->format() }} |

@endcomponent

@endcomponent
