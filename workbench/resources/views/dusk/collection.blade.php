@extends('layout')

{{-- T19 — AvailabilityCollection live list for the `rooms` collection. Single instance
     (showUnavailable defaults false, no pagination): the listing render + select → redirect. --}}
@section('content')
<div dusk="collection-route">
    <livewire:availability-collection collection="rooms" :rates="true" />
</div>
@endsection
