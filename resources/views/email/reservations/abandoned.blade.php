@component('mail::message')

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
{{ __("Tel:") }} {{ config('resrv-config.phone') }}<br>
{{ __("Email:") }} {{ config('resrv-config.mail') }}

{{ __("We noticed you didn't complete your reservation") }}

{{ __("It looks like you started a reservation but didn't finish the booking process. Your selected dates might still be available!") }}

@component('mail::panel')
{{ __("Reservation details") }}:<br>
{{ __("Date") }}: **{{ $reservation->created_at->format('d-m-Y H:i') }}**<br>
{{ __("Pick-up date") }}: **{{ $reservation->date_start->format('d-m-Y H:i') }}**<br>
{{ __("Drop-off date") }}: **{{ $reservation->date_end->format('d-m-Y H:i') }}**<br>
{{ __("Property") }}: **{{ $reservation->entry()->title ?? '' }}**
@endcomponent

@component('mail::button', ['url' => config('app.url')])
{{ __("Visit our website") }}
@endcomponent

{{ __("If you experienced any issues or have questions, simply reply to this email and we'll be happy to help.") }}

{{ __("Thank you") }},<br>
{{ config('app.name') }}
@endcomponent
