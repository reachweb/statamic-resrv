@extends('statamic::layout')
@section('title', 'Resrv Locations')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Locations</h1>
    </div>

    <div>
        <locations-list></locations-list>
    </div>

@endsection