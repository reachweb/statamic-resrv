@extends('layout')

{{-- T19 — showUnavailable is a #[Locked] mount option, not a UI toggle, so the true/false
     behaviour is compared by mounting two instances of the same `rooms` collection side by
     side and asserting which rows each renders (a per-test sell-out of one room drives it). --}}
@section('content')
<div dusk="collection-compare-route">
    <div dusk="col-show">
        <livewire:availability-collection collection="rooms" :rates="true" :show-unavailable="true" />
    </div>
    <div dusk="col-hide">
        <livewire:availability-collection collection="rooms" :rates="true" :show-unavailable="false" />
    </div>
</div>
@endsection
