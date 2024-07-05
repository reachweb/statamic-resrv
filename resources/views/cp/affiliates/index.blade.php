@extends('statamic::layout')
@section('title', 'Affiliates')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Affiliates</h1>
    </div>

    <div>
        <affiliates-list />
    </div>

@endsection