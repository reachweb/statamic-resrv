@extends('statamic::layout')
@section('title', 'Resrv Extras')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Extras</h1>
    </div>

    <div>
        <extras-list></extras-list>
    </div>

@endsection