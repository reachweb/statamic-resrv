@extends('statamic::layout')
@section('title', 'Resrv Reports')
@section('wrapper_class', 'page-wrapper max-w-full')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Reports</h1>
    </div>

    <div>
        <reports-view
            reports-url="{{ cp_route('resrv.report.index') }}"
            currency="{{ config('resrv-config.currency_symbol') }}"
        >
        </reports-view>
    </div>

@endsection