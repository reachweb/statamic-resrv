@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

@if ($customerFirstName ?? null)
{{ __("Hi :name,", ['name' => $customerFirstName]) }}

@endif
@if ($isReminder ?? false)
{{ ($isOffline || ! $payUrl) ? __("Here are the payment details for your reservation again.") : __("Here is the payment link for your reservation again.") }}
@if (($deadlineChanged ?? false) && ($paymentDueBy ?? null))
{{ __("Your payment deadline is now :deadline.", ['deadline' => $paymentDueBy]) }}
@else
{{ __("Please complete the payment to confirm it.") }}
@endif
@else
{{ __("We have created a reservation for you. Please complete the payment to confirm it.") }}
@endif

@component('mail::panel')
{{ __("Booking reference") }}: **{{ $reservation->reference }}**<br>
{{ __("Amount to pay") }}: **{{ config('resrv-config.currency_symbol') }} {{ $amountDue }}**
@if ($paymentDueBy ?? null)
<br>{{ ($paymentDueIsArrival ?? false) ? __("Please pay by the end of your arrival day") : __("Please pay by") }}: **{{ $paymentDueBy }}**
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
@if (! $reservation->payment_surcharge->isZero())
| {{ __("Payment surcharge") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment_surcharge->format() }} |
@endif
@if ($reservation->payment->lessThan($reservation->total))
| {{ __("Remaining amount") }} | {{ config('resrv-config.currency_symbol') }} {{ $reservation->amountRemaining() }} |
@endif
@endcomponent

@php($resrvExtras = $reservation->extras()->get())
@if ($resrvExtras->isNotEmpty())
@component('mail::table')
|{{ __("Extras") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
@foreach ($resrvExtras as $extra)
| {{ $extra->name }} | x{{ $extra->pivot->quantity }} |
@endforeach
@endcomponent
@endif

@php($reservationOptions = $reservation->optionsForEmail())
@if ($reservationOptions->isNotEmpty())
@component('mail::table')
|{{ __("Options") }}||
| :------------------------------------------------ |:--------------------------------------------------------------------------|
@foreach ($reservationOptions as $option)
| {{ $option->name }} | {{ $option->values->find($option->pivot->value)?->name }} |
@endforeach
@endcomponent
@endif

@if (! $isOffline && $payUrl)
@component('mail::button', ['url' => $payUrl])
{{ __("Pay now") }}
@endcomponent

{{ __("If the button does not work, copy this link into your browser:") }} {{ $payUrl }}
@elseif ($isOffline && ($offlineInstructions ?? null))
**{{ __("How to pay") }}**

@component('mail::panel')
{{ $offlineInstructions }}
@endcomponent

{{ __("Please include the booking reference :reference with your payment. We will confirm your reservation as soon as your payment arrives.", ['reference' => $reservation->reference]) }}
@elseif ($isOffline)
{{ __("This reservation is not paid online: payment is arranged directly with us. Please contact us using the details above for the payment details, quoting the booking reference :reference. We will confirm your reservation as soon as your payment arrives.", ['reference' => $reservation->reference]) }}
@else
{{ __("Please contact us to arrange the payment for this reservation.") }}
@endif

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
