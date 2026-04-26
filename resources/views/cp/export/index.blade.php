@extends('statamic::layout')
@section('title', 'Resrv Export')
@section('wrapper_class', 'page-wrapper max-w-full')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">{{ __('Export reservations') }}</h1>
    </div>

    <reservations-export
        count-url="{{ cp_route('resrv.export.count') }}"
        download-url="{{ cp_route('resrv.export.download') }}"
        :fields="{{ json_encode($fields) }}"
        :statuses="{{ json_encode($statuses) }}"
        :entries="{{ json_encode($entries) }}"
        :affiliates="{{ json_encode($affiliates) }}"
    >
    </reservations-export>

@endsection
