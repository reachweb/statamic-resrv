@extends('layout')

@section('content')
<div dusk="rate-collection-route">
    <livewire:availability-collection collection="rooms" :rates="true" :show-rates="true" :show-unavailable="true" />
</div>
@endsection
