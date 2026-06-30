@extends('layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
<div dusk="bookable-page" class="resrv-funnel">
    <livewire:availability-search :entry="$id" :rates="true" :enable-quantity="true" :show-availability-on-calendar="true" />

    <livewire:availability-results :entry="$id" :rates="true" :show-extras="true" :show-options="true" />
</div>
@endsection
