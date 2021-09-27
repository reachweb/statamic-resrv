@extends('statamic::layout')
@section('title', 'Resrv Reservations Calendar')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Reservations Calendar</h1>
    </div>
    <div>
        <reservations-calendar
            calendar-json-url="{{ cp_route('resrv.reservations.calendar.list') }}"
        >
        </reservations-calendar>
    </div>

@endsection