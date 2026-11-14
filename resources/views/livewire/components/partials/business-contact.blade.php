@php($contactMail = config('resrv-config.mail'))
@php($contactPhone = config('resrv-config.phone'))
@if (is_string($contactMail) && filled($contactMail))
<p class="text-gray-700 break-words">{{ trans('statamic-resrv::frontend.emailAddress') }}: <a href="mailto:{{ $contactMail }}" class="text-gray-900 underline">{{ $contactMail }}</a></p>
@endif
@if (is_scalar($contactPhone) && filled($contactPhone))
<p class="text-gray-700 break-words">{{ trans('statamic-resrv::frontend.contactPhone') }}: <a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $contactPhone) }}" class="text-gray-900 underline">{{ $contactPhone }}</a></p>
@endif
