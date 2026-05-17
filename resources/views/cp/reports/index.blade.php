@extends('statamic::layout')
@section('title', 'Reports')
@section('wrapper_class', 'max-w-page mx-auto')

@section('content')
    <reports-view
        reports-url="{{ cp_route('resrv.report.index') }}"
        currency="{{ config('resrv-config.currency_symbol') }}"
    ></reports-view>
@endsection
