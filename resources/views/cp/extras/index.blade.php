@extends('statamic::layout')
@section('title', 'Resrv Extras')
@section('wrapper_class', 'max-w-full')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Extras</h1>
    </div>

    <div>
        <extras-list></extras-list>
    </div>

@endsection