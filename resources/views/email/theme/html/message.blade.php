@component('mail::layout')
{{-- Header --}}
@slot('header')
@component('mail::header', ['url' => config('app.url')])
{{ config('resrv-config.name') }}
@endcomponent
@endslot

{{ config('resrv-config.name') }}<br>
{{ config('resrv-config.address1') }}<br>
{{ config('resrv-config.zip_city') }}<br>
{{ config('resrv-config.country') }}<br>
Tel: {{ config('resrv-config.phone') }}<br>
Email: {{ config('resrv-config.mail') }}

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
@component('mail::subcopy')
{{ $subcopy }}
@endcomponent
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
© {{ date('Y') }} {{ config('resrv-config.name') }}. @lang('All rights reserved.')
@endcomponent
@endslot
@endcomponent
