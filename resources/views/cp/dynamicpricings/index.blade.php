@extends('statamic::layout')
@section('title', 'Dynamic Pricing')
@section('wrapper_class', 'max-w-page mx-auto')

@section('content')
    <dynamic-pricing-list
        :timezone="{{ Illuminate\Support\Js::from(config('app.timezone', 'UTC')) }}"
    ></dynamic-pricing-list>
@endsection
