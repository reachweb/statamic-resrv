@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

{{ __("Thank you for your reservation!") }}

@component('mail::panel')
{{ __("Reservation code") }} **{{ $reservation->id }}**<br>
{{ __("Date") }}: **{{ $reservation->updated_at->format('d-m-Y H:i') }}**<br>
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Email") }}: **{{ $reservation->customer->email }}** 
@endcomponent

@component('mail::table')
|{{ __("Reservation details") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
@if ($reservation->type !== 'parent')
| {{ __("Pick-up date") }}      | {{ $reservation->date_start->format('d-m-Y H:i') }} |
| {{ __("Drop-off date") }}     | {{ $reservation->date_end->format('d-m-Y H:i') }} |
@endif
| {{ __("Property") }}   | {{ $reservation->entry()->title }} |
@if ($reservation->type !== 'parent')
@if (config('resrv-config.maximum_quantity') > 1)
| {{ __("Quantity") }}  | x {{ $reservation->quantity }} |
@endif
@if (config('resrv-config.enable_advanced_availability'))
| {{ __("Property") }} | {{ $reservation->getPropertyAttributeLabel() }} |
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
@if (config('resrv-config.enable_advanced_availability'))
| {{ __("Property") }} | {{ $child->getPropertyAttributeLabel() }} |
@endif
@endcomponent
@endforeach
@endif

@if ($reservation->has('customer'))
@component('mail::table')
|{{ __("Checkout data") }} ||
| :---------------------------------------- |:------------------------------------------|
@foreach ($reservation->customerData as $field => $value)
@if (is_array($value) || $value == null)
    @continue
@endif
| {{ $reservation->checkoutFormFieldsArray($reservation->entry()->id)[$field] ?? $field }}      | {{ $value }} |
@endforeach
@endcomponent
@endif

@if ($reservation->extras()->get()->count() > 0)
@component('mail::table')
|{{ __("Extras") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------| 
@foreach ($reservation->extras()->get() as $extra)
| {{ $extra->name }} | x{{ $extra->pivot->quantity }} |
@endforeach
@endcomponent
@endif

@if ($reservation->options()->get()->count() > 0)
@component('mail::table')
|{{ __("Options") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------| 
@foreach ($reservation->options()->get() as $option)
| {{ $option->name }} | {{ $option->values->find($option->pivot->value)->name }} |
@endforeach
@endcomponent
@endif

@component('mail::table')
|{{ __("Payment information") }}||
| :----------------------------- |:----------------| 
@if (config('resrv-config.payment') !== 'everything' && $reservation->status !== 'partner')
| {{ __("Already paid by credit card") }}    | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }} |
| {{ __("Remaining amount") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->amountRemaining() }} |
@endif
| **{{ __("Total") }}** ({{ __("including taxes") }}) | {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }} |

@endcomponent

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
