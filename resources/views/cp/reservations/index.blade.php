@extends('statamic::layout')
@section('title', 'Reservations')
@section('wrapper_class', 'max-w-page mx-auto')

@section('content')
    <reservations-list
        reservations-url="{{ cp_route('resrv.reservation.index') }}"
        show-route="{{ cp_route('resrv.reservation.show', 'RESRVURL') }}"
        refund-route="{{ cp_route('resrv.reservation.refund') }}"
        :filters="{{ json_encode($filters) }}"
    ></reservations-list>
@endsection
