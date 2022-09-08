@extends('statamic::layout')
@section('title', 'Dynamic Pricing')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex mb-3">
        <h1 class="flex-1">Dynamic Pricing</h1>
    </div>

    <div>
        <dynamic-pricing-list
            :timezone="{{ Illuminate\Support\Js::from(config('app.timezone', 'UTC')) }}"
        >
        </dynamic-pricing-list>
    </div>

@endsection