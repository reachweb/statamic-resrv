{{--
    Blade layout for the browser-test harness.

    Statamic only auto-wraps a layout around *Antlers* templates
    (View::shouldUseLayout → isUsingAntlersTemplate); a Blade template must
    @extends this itself. We go all-Blade because the resrv Livewire components
    take typed/boolean mount params (:rates="true", :enable-quantity="true"),
    which Blade's <livewire:…> evaluates correctly but the Antlers
    {{ livewire:… }} bridge can't (it looks up `true` as a variable → null).

    Asset order is load-bearing (Gotcha #1): resrv-frontend.js registers the
    @reachweb/alpine-calendar plugin on alpine:init and sets window.dayjs, so it
    must parse BEFORE @livewireScripts (Livewire ships & auto-starts Alpine). No
    standalone Alpine, no Stripe.js (offline gateway only).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    <link rel="stylesheet" href="/vendor/statamic-resrv/frontend/css/resrv-frontend.css">
    <link rel="stylesheet" href="/vendor/statamic-resrv/frontend/css/resrv-tailwind.css">

    @livewireStyles
</head>
<body>
    @yield('content')

    <script src="/vendor/statamic-resrv/frontend/js/resrv-frontend.js"></script>

    @livewireScripts
</body>
</html>
