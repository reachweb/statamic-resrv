@extends('statamic::layout')
@section('title', 'Reservations Calendar')
@section('wrapper_class', 'max-w-page mx-auto')

@section('content')
    <reservations-calendar
        calendar-json-url="{{ cp_route('resrv.reservations.calendar.list') }}"
    ></reservations-calendar>
@endsection
