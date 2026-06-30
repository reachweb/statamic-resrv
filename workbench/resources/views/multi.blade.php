@extends('layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
<div dusk="multi-page" class="resrv-cart">
    <livewire:availability-search :entry="$id" :rates="true" :any-rate="true" />

    <livewire:availability-multi-results :entry="$id" :show-extras="true" :show-options="true" />
</div>
@endsection
