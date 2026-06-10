@component('mail::message')

{{ __("A reservation has been cancelled by the customer.") }}

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Date") }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer?->email }}**
@endcomponent

@component('mail::table')
|{{ __("Reservation details") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
@if ($reservation->type !== 'parent')
| {{ __("Pick-up date") }}      | {{ $reservation->date_start->format('d-m-Y H:i') }} |
| {{ __("Drop-off date") }}     | {{ $reservation->date_end->format('d-m-Y H:i') }} |
@endif
| {{ __("Vehicle") }}   | {{ $reservation->entry()->title }} |
@if ($reservation->type !== 'parent')
@if (config('resrv-config.maximum_quantity') > 1)
| {{ __("Quantity") }}  | x {{ $reservation->quantity }} |
@endif
@if ($reservation->rate_id)
| {{ __("Rate") }} | {{ $reservation->getRateLabel() }} |
@endif
@endif
@endcomponent

@if ($reservation->type === 'parent')
@foreach ($reservation->childs as $child)
@component('mail::table')
|{{ __("Reservation") }} # {{ $loop->iteration }}||
| :---------------------------------------- |:------------------------------------------|
| {{ __("Pick-up date") }}      | {{ $child->date_start->format('d-m-Y H:i') }} |
| {{ __("Drop-off date") }}     | {{ $child->date_end->format('d-m-Y H:i') }} |
@if (config('resrv-config.maximum_quantity') > 1)
| {{ __("Quantity") }}  | x {{ $child->quantity }} |
@endif
@if ($child->rate_id)
| {{ __("Rate") }} | {{ $child->getRateLabel() }} |
@endif
@endcomponent
@endforeach
@endif

@if ($reservation->hasGatewayPayment())
@component('mail::table')
|{{ __("Refund information") }}||
| :----------------------------- |:----------------|
| {{ __("Refunded to the customer") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->refundedAmount()->format() }} |
@endcomponent
@endif

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
