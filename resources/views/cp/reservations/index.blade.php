@extends('statamic::layout')
@section('title', 'Resrv Reservations')
@section('wrapper_class', 'page-wrapper max-w-full')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Reservations</h1>
    </div>

    <div>
        <reservations-list
            reservations-url="{{ cp_route('resrv.reservation.index') }}"
            show-route="{{ cp_route('resrv.reservation.show', 'RESRVURL') }}"
            :filters="{{ json_encode($filters) }}"
        >
        </reservations-list>
    </div>

@endsection