@extends('statamic::layout')
@section('title', 'Resrv Rates')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Rates</h1>
    </div>

    <div>
        <rates-list></rates-list>
    </div>

@endsection
