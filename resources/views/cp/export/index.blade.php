@extends('statamic::layout')
@section('title', 'Export Reservations')
@section('wrapper_class', 'max-w-page mx-auto')

@section('content')
    <reservations-export
        count-url="{{ cp_route('resrv.export.count') }}"
        download-url="{{ cp_route('resrv.export.download') }}"
        :fields="{{ json_encode($fields) }}"
        :statuses="{{ json_encode($statuses) }}"
        :entries="{{ json_encode($entries) }}"
        :affiliates="{{ json_encode($affiliates) }}"
    ></reservations-export>
@endsection
